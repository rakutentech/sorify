package main

import (
	"encoding/json"
	"fmt"
	"log"
	"net"
	"net/http"
	"strings"
	"sync"

	"github.com/gorilla/websocket"
)

// WSServer mirrors src/ws-server.js: a raw WebSocket server that the Chrome
// extension connects to, broadcasting {type: "status", ...} on every
// connect/disconnect/state change.
//
// The Chrome extension connects on "/", while additional recorder MCP
// instances (started by other agent sessions when the port is already taken)
// connect on "/mcp" and drive the recorder through mcp_request/mcp_response
// frames. Only "/" connections count toward IsExtensionConnected.
type WSServer struct {
	store    *RecordingStore
	upgrader websocket.Upgrader
	server   *http.Server
	listener net.Listener

	mu sync.Mutex
	// sockets maps live connections to isExtension (true for "/" clients).
	sockets map[*websocket.Conn]bool
}

// wsMessage is the union of every frame the server accepts. The Chrome
// extension uses hello/get_status/start_recording/stop_recording/event/cookies;
// proxy-mode instances use mcp_request.
type wsMessage struct {
	Type      string          `json:"type"`
	Label     *string         `json:"label"`
	Event     json.RawMessage `json:"event"`
	Phase     string          `json:"phase"`
	Cookies   json.RawMessage `json:"cookies"`
	ID        string          `json:"id"`
	Op        string          `json:"op"`
	SessionID *string         `json:"session_id"`
}

func NewWSServer(store *RecordingStore) *WSServer {
	return &WSServer{
		store:    store,
		upgrader: websocket.Upgrader{CheckOrigin: func(r *http.Request) bool { return true }},
		sockets:  make(map[*websocket.Conn]bool),
	}
}

// Port returns the port the WS listener is bound to. With Start(0) this is
// the OS-assigned ephemeral port.
func (w *WSServer) Port() int {
	return w.listener.Addr().(*net.TCPAddr).Port
}

func (w *WSServer) Start(port int) error {
	listener, err := net.Listen("tcp", fmt.Sprintf("127.0.0.1:%d", port))
	if err != nil {
		if isAddrInUse(err) {
			log.Printf("[sorify-recorder-mcp] port %d is already in use — is another instance already running? (lsof -nP -iTCP:%d -sTCP:LISTEN)", port, port)
		} else {
			log.Printf("[sorify-recorder-mcp] WebSocket server error: %v", err)
		}
		return err
	}
	w.listener = listener

	mux := http.NewServeMux()
	mux.HandleFunc("/mcp", func(rw http.ResponseWriter, r *http.Request) { w.handleConnection(rw, r, false) })
	mux.HandleFunc("/", func(rw http.ResponseWriter, r *http.Request) { w.handleConnection(rw, r, true) })
	w.server = &http.Server{Handler: mux}

	log.Printf("[sorify-recorder-mcp] listening on ws://127.0.0.1:%d for the Chrome extension", port)
	go func() {
		if err := w.server.Serve(listener); err != nil && err != http.ErrServerClosed {
			log.Printf("[sorify-recorder-mcp] WebSocket server error: %v", err)
		}
	}()
	return nil
}

func isAddrInUse(err error) bool {
	return strings.Contains(err.Error(), "address already in use")
}

func (w *WSServer) handleConnection(rw http.ResponseWriter, r *http.Request, isExtension bool) {
	conn, err := w.upgrader.Upgrade(rw, r, nil)
	if err != nil {
		return
	}

	w.mu.Lock()
	w.sockets[conn] = isExtension
	w.mu.Unlock()
	w.BroadcastStatus()

	defer func() {
		conn.Close()
		w.mu.Lock()
		delete(w.sockets, conn)
		w.mu.Unlock()
		w.BroadcastStatus()
	}()

	for {
		_, raw, err := conn.ReadMessage()
		if err != nil {
			return
		}

		var msg wsMessage
		if err := json.Unmarshal(raw, &msg); err != nil {
			continue
		}

		switch msg.Type {
		case "hello", "get_status":
			w.BroadcastStatus()
		case "start_recording":
			if _, err := w.store.Start(msg.Label); err != nil {
				log.Printf("[sorify-recorder-mcp] start_recording failed: %v", err)
			}
			w.BroadcastStatus()
		case "stop_recording":
			if _, err := w.store.Stop(nil); err != nil {
				log.Printf("[sorify-recorder-mcp] stop_recording failed: %v", err)
			}
			w.BroadcastStatus()
		case "event":
			var event map[string]any
			if err := json.Unmarshal(msg.Event, &event); err == nil {
				if _, _, err := w.store.RecordEvent(event); err != nil {
					log.Printf("[sorify-recorder-mcp] recordEvent failed: %v", err)
				}
			}
		case "cookies":
			var cookies []map[string]any
			if err := json.Unmarshal(msg.Cookies, &cookies); err == nil {
				if _, _, err := w.store.RecordCookies(msg.Phase, cookies); err != nil {
					log.Printf("[sorify-recorder-mcp] recordCookies failed: %v", err)
				}
			}
		case "mcp_request":
			w.handleMCPRequest(conn, msg)
		}
	}
}

// handleMCPRequest executes a proxy-mode tool call against the local store
// and replies only to the requesting connection. Status is still broadcast
// to every socket so the Chrome extension starts/stops capturing exactly as
// it would when the owning instance drives the recorder itself.
func (w *WSServer) handleMCPRequest(conn *websocket.Conn, msg wsMessage) {
	switch msg.Op {
	case "start":
		res, err := w.store.Start(msg.Label)
		if err != nil {
			w.reply(conn, msg.ID, false, nil, err.Error())
			return
		}
		w.BroadcastStatus()
		w.reply(conn, msg.ID, true, map[string]any{"session_id": res.SessionID, "path": res.Path}, "")
	case "stop":
		res, err := w.store.Stop(msg.SessionID)
		if err != nil {
			w.reply(conn, msg.ID, false, nil, err.Error())
			return
		}
		w.BroadcastStatus()
		var result any
		if res != nil {
			result = map[string]any{"session_id": res.SessionID, "path": res.Path, "event_count": res.EventCount}
		}
		w.reply(conn, msg.ID, true, result, "")
	case "status":
		st := w.store.Status()
		w.reply(conn, msg.ID, true, map[string]any{
			"extension_connected": w.IsExtensionConnected(),
			"active_session_id":   statusSessionID(st),
			"event_count":         st.EventCount,
		}, "")
	default:
		w.reply(conn, msg.ID, false, nil, fmt.Sprintf("unknown op %q", msg.Op))
	}
}

// reply sends an mcp_response frame to a single connection.
func (w *WSServer) reply(conn *websocket.Conn, id string, ok bool, result any, errMsg string) {
	payload := map[string]any{"type": "mcp_response", "id": id, "ok": ok}
	if ok {
		payload["result"] = result
	} else {
		payload["error"] = errMsg
	}
	body, err := json.Marshal(payload)
	if err != nil {
		return
	}
	w.mu.Lock()
	defer w.mu.Unlock()
	_ = conn.WriteMessage(websocket.TextMessage, body)
}

func (w *WSServer) BroadcastStatus() {
	status := w.store.Status()

	w.mu.Lock()
	defer w.mu.Unlock()

	payload, err := json.Marshal(map[string]any{
		"type":            "status",
		"connected":       len(w.sockets) > 0,
		"activeSessionId": statusSessionID(status),
		"eventCount":      status.EventCount,
	})
	if err != nil {
		return
	}
	for conn := range w.sockets {
		_ = conn.WriteMessage(websocket.TextMessage, payload)
	}
}

func statusSessionID(status StatusResult) any {
	if status.ActiveSessionID == "" {
		return nil
	}
	return status.ActiveSessionID
}

func (w *WSServer) IsExtensionConnected() bool {
	w.mu.Lock()
	defer w.mu.Unlock()
	for _, isExtension := range w.sockets {
		if isExtension {
			return true
		}
	}
	return false
}

func (w *WSServer) Close() error {
	w.mu.Lock()
	for conn := range w.sockets {
		conn.Close()
	}
	w.sockets = make(map[*websocket.Conn]bool)
	w.mu.Unlock()

	if w.server != nil {
		return w.server.Close()
	}
	return nil
}
