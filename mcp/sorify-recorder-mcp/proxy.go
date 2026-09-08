package main

import (
	"encoding/json"
	"fmt"
	"strings"
	"sync"
	"time"

	"github.com/gorilla/websocket"
)

// RecorderStatusInfo is the live recorder state as reported by whichever
// instance owns the WebSocket bridge.
type RecorderStatusInfo struct {
	ActiveSessionID    string
	EventCount         int
	ExtensionConnected bool
}

// ProxyClient attaches to another sorify-recorder-mcp instance that owns the
// WebSocket port, and forwards start/stop/status operations to it over the
// mcp_request/mcp_response protocol. Used when the port is already in use so
// that multiple agent sessions can share one recorder bridge. List/clear work
// directly on the local file-backed store, which the owning instance also
// writes to.
type ProxyClient struct {
	port int

	mu      sync.Mutex
	conn    *websocket.Conn
	nextID  int
	pending map[string]chan *mcpResponse
}

type mcpResponse struct {
	Type   string          `json:"type"`
	ID     string          `json:"id"`
	OK     bool            `json:"ok"`
	Result json.RawMessage `json:"result"`
	Error  string          `json:"error"`
}

func NewProxyClient(port int) *ProxyClient {
	return &ProxyClient{port: port, pending: make(map[string]chan *mcpResponse)}
}

func (p *ProxyClient) Connect() error {
	p.mu.Lock()
	defer p.mu.Unlock()
	return p.connectLocked()
}

func (p *ProxyClient) connectLocked() error {
	if p.conn != nil {
		return nil
	}
	dialer := &websocket.Dialer{HandshakeTimeout: 3 * time.Second}
	conn, _, err := dialer.Dial(fmt.Sprintf("ws://127.0.0.1:%d/mcp", p.port), nil)
	if err != nil {
		return err
	}
	p.conn = conn
	go p.readPump(conn)
	return nil
}

func (p *ProxyClient) Close() error {
	p.mu.Lock()
	conn := p.conn
	p.conn = nil
	p.mu.Unlock()
	if conn != nil {
		return conn.Close()
	}
	return nil
}

func (p *ProxyClient) readPump(conn *websocket.Conn) {
	for {
		_, raw, err := conn.ReadMessage()
		if err != nil {
			p.dropConn(conn, err)
			return
		}
		var msg mcpResponse
		if err := json.Unmarshal(raw, &msg); err != nil || msg.Type != "mcp_response" || msg.ID == "" {
			continue // status broadcasts and anything else the owner sends
		}
		p.mu.Lock()
		ch, ok := p.pending[msg.ID]
		delete(p.pending, msg.ID)
		p.mu.Unlock()
		if ok {
			ch <- &msg
		}
	}
}

// dropConn marks the connection as lost and fails any in-flight requests
// so their callers can retry.
func (p *ProxyClient) dropConn(conn *websocket.Conn, cause error) {
	p.mu.Lock()
	defer p.mu.Unlock()
	if p.conn == conn {
		p.conn = nil
		conn.Close()
	}
	for id, ch := range p.pending {
		ch <- &mcpResponse{Type: "mcp_response", ID: id, OK: false, Error: "connection lost: " + cause.Error()}
		delete(p.pending, id)
	}
}

// request performs one mcp_request round trip, retrying once if the
// connection dropped (e.g. the previous owner exited between calls).
func (p *ProxyClient) request(op string, extra map[string]any) (json.RawMessage, error) {
	var lastErr error
	for attempt := 0; attempt < 2; attempt++ {
		resp, err := p.send(op, extra)
		if err != nil {
			lastErr = err
			continue
		}
		if resp.OK {
			return resp.Result, nil
		}
		if strings.HasPrefix(resp.Error, "connection lost") {
			lastErr = fmt.Errorf("recorder bridge: %s", resp.Error)
			continue
		}
		return nil, fmt.Errorf("recorder bridge: %s", resp.Error)
	}
	return nil, fmt.Errorf("recorder bridge on port %d unreachable: %w", p.port, lastErr)
}

func (p *ProxyClient) send(op string, extra map[string]any) (*mcpResponse, error) {
	p.mu.Lock()
	if p.conn == nil {
		if err := p.connectLocked(); err != nil {
			p.mu.Unlock()
			return nil, err
		}
	}
	p.nextID++
	id := fmt.Sprintf("req-%d", p.nextID)
	ch := make(chan *mcpResponse, 1)
	p.pending[id] = ch
	conn := p.conn

	msg := map[string]any{"type": "mcp_request", "id": id, "op": op}
	for k, v := range extra {
		msg[k] = v
	}
	if err := conn.WriteJSON(msg); err != nil {
		delete(p.pending, id)
		if p.conn == conn {
			p.conn = nil
			conn.Close()
		}
		p.mu.Unlock()
		return nil, err
	}
	p.mu.Unlock()

	select {
	case resp := <-ch:
		return resp, nil
	case <-time.After(10 * time.Second):
		p.mu.Lock()
		delete(p.pending, id)
		p.mu.Unlock()
		return nil, fmt.Errorf("timed out waiting for the recorder bridge")
	}
}

// Start starts a recording session through the owning instance. The owner
// broadcasts the status change so the Chrome extension begins capturing.
func (p *ProxyClient) Start(label *string) (StartResult, error) {
	extra := map[string]any{}
	if label != nil {
		extra["label"] = *label
	}
	raw, err := p.request("start", extra)
	if err != nil {
		return StartResult{}, err
	}
	var res struct {
		SessionID string `json:"session_id"`
		Path      string `json:"path"`
	}
	if err := json.Unmarshal(raw, &res); err != nil {
		return StartResult{}, err
	}
	return StartResult{SessionID: res.SessionID, Path: res.Path}, nil
}

// Stop stops the active (or given) session through the owning instance.
// A nil result with a nil error means there was no active session, mirroring
// the owning instance's tool semantics.
func (p *ProxyClient) Stop(sessionID *string) (*StopResult, error) {
	extra := map[string]any{}
	if sessionID != nil && *sessionID != "" {
		extra["session_id"] = *sessionID
	}
	raw, err := p.request("stop", extra)
	if err != nil {
		return nil, err
	}
	if len(raw) == 0 || string(raw) == "null" {
		return nil, nil
	}
	var res struct {
		SessionID  string `json:"session_id"`
		Path       string `json:"path"`
		EventCount int    `json:"event_count"`
	}
	if err := json.Unmarshal(raw, &res); err != nil {
		return nil, err
	}
	return &StopResult{SessionID: res.SessionID, Path: res.Path, EventCount: res.EventCount}, nil
}

// RecorderStatus reports the owning instance's live state: whether the Chrome
// extension is connected to it and whether a recording session is active.
func (p *ProxyClient) RecorderStatus() (RecorderStatusInfo, error) {
	raw, err := p.request("status", nil)
	if err != nil {
		return RecorderStatusInfo{}, err
	}
	var res struct {
		ExtensionConnected bool    `json:"extension_connected"`
		ActiveSessionID    *string `json:"active_session_id"`
		EventCount         int     `json:"event_count"`
	}
	if err := json.Unmarshal(raw, &res); err != nil {
		return RecorderStatusInfo{}, err
	}
	info := RecorderStatusInfo{ExtensionConnected: res.ExtensionConnected, EventCount: res.EventCount}
	if res.ActiveSessionID != nil {
		info.ActiveSessionID = *res.ActiveSessionID
	}
	return info, nil
}
