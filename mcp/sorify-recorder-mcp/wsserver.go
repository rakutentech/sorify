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
type WSServer struct {
	store    *RecordingStore
	upgrader websocket.Upgrader
	server   *http.Server
	listener net.Listener

	mu      sync.Mutex
	sockets map[*websocket.Conn]struct{}
}

func NewWSServer(store *RecordingStore) *WSServer {
	return &WSServer{
		store:    store,
		upgrader: websocket.Upgrader{CheckOrigin: func(r *http.Request) bool { return true }},
		sockets:  make(map[*websocket.Conn]struct{}),
	}
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
	mux.HandleFunc("/", w.handleConnection)
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

func (w *WSServer) handleConnection(rw http.ResponseWriter, r *http.Request) {
	conn, err := w.upgrader.Upgrade(rw, r, nil)
	if err != nil {
		return
	}

	w.mu.Lock()
	w.sockets[conn] = struct{}{}
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

		var msg struct {
			Type  string          `json:"type"`
			Label *string         `json:"label"`
			Event json.RawMessage `json:"event"`
		}
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
		}
	}
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
	return len(w.sockets) > 0
}

func (w *WSServer) Close() error {
	w.mu.Lock()
	for conn := range w.sockets {
		conn.Close()
	}
	w.sockets = make(map[*websocket.Conn]struct{})
	w.mu.Unlock()

	if w.server != nil {
		return w.server.Close()
	}
	return nil
}
