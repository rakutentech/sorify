package main

import (
	"encoding/json"
	"fmt"
	"strings"
	"testing"
	"time"

	"github.com/gorilla/websocket"
)

// dialTestWS connects a raw test client to the owner's WS server.
func dialTestWS(t *testing.T, url string) *websocket.Conn {
	t.Helper()
	dialer := &websocket.Dialer{HandshakeTimeout: 2 * time.Second}
	conn, _, err := dialer.Dial(url, nil)
	if err != nil {
		t.Fatalf("dial %s: %v", url, err)
	}
	t.Cleanup(func() { conn.Close() })
	return conn
}

// readStatusUntil reads status frames until one reports the wanted active
// session (the connection also receives connect-time broadcasts where no
// session is active yet).
func readStatusUntil(t *testing.T, conn *websocket.Conn, wantSessionID string, timeout time.Duration) {
	t.Helper()
	deadline := time.Now().Add(timeout)
	for {
		_ = conn.SetReadDeadline(deadline)
		_, raw, err := conn.ReadMessage()
		if err != nil {
			t.Fatalf("read: %v", err)
		}
		var msg map[string]any
		if err := json.Unmarshal(raw, &msg); err != nil {
			continue
		}
		if msg["type"] == "status" && msg["activeSessionId"] == wantSessionID {
			return
		}
	}
}

func pollProxyStatus(t *testing.T, proxy *ProxyClient, timeout time.Duration, cond func(RecorderStatusInfo) bool) RecorderStatusInfo {
	t.Helper()
	deadline := time.Now().Add(timeout)
	var last RecorderStatusInfo
	for time.Now().Before(deadline) {
		st, err := proxy.RecorderStatus()
		if err == nil {
			last = st
			if cond(st) {
				return st
			}
		}
		time.Sleep(20 * time.Millisecond)
	}
	t.Fatalf("condition not met within %v; last status: %+v", timeout, last)
	return last
}

func TestProxyRoundTripThroughOwner(t *testing.T) {
	withTempRecordingsDir(t)

	ownerStore := &RecordingStore{}
	owner := NewWSServer(ownerStore)
	if err := owner.Start(0); err != nil {
		t.Fatalf("owner start: %v", err)
	}
	t.Cleanup(func() { owner.Close() })
	baseURL := fmt.Sprintf("ws://127.0.0.1:%d", owner.Port())

	// Extension-role client on the regular path.
	ext := dialTestWS(t, baseURL+"/")
	if err := ext.WriteJSON(map[string]any{"type": "hello"}); err != nil {
		t.Fatalf("hello: %v", err)
	}

	// Proxy client with its own store, like a separate process would have.
	proxy := NewProxyClient(owner.Port())
	if err := proxy.Connect(); err != nil {
		t.Fatalf("proxy connect: %v", err)
	}
	t.Cleanup(func() { proxy.Close() })

	label := "proxy-session"
	start, err := proxy.Start(&label)
	if err != nil {
		t.Fatalf("proxy start: %v", err)
	}
	if start.SessionID == "" || !strings.HasSuffix(start.Path, ".jsonl") {
		t.Fatalf("unexpected start result: %+v", start)
	}

	// The extension must learn about the session via the status broadcast
	// (this is what makes the Chrome extension actually capture).
	readStatusUntil(t, ext, start.SessionID, 2*time.Second)

	// The extension records an event (delivered to the owner, not the proxy).
	if err := ext.WriteJSON(map[string]any{
		"type":  "event",
		"event": map[string]any{"kind": "click", "selector": "#go"},
	}); err != nil {
		t.Fatalf("send event: %v", err)
	}

	st := pollProxyStatus(t, proxy, 2*time.Second, func(s RecorderStatusInfo) bool {
		return s.ExtensionConnected && s.ActiveSessionID == start.SessionID && s.EventCount == 1
	})
	if st.ActiveSessionID != start.SessionID {
		t.Fatalf("status active session = %q, want %q", st.ActiveSessionID, start.SessionID)
	}

	stop, err := proxy.Stop(nil)
	if err != nil {
		t.Fatalf("proxy stop: %v", err)
	}
	if stop == nil || stop.SessionID != start.SessionID || stop.EventCount != 1 {
		t.Fatalf("unexpected stop result: %+v", stop)
	}

	// The proxy's own (file-backed) store sees the owner-written recording.
	proxyStore := &RecordingStore{}
	recordings, err := proxyStore.List()
	if err != nil {
		t.Fatalf("list: %v", err)
	}
	if len(recordings) != 1 {
		t.Fatalf("expected 1 recording, got %d", len(recordings))
	}
	got := recordings[0]
	if got.SessionID != start.SessionID || got.Label == nil || *got.Label != label || got.EventCount != 1 {
		t.Fatalf("unexpected summary: %+v", got)
	}
}

func TestProxyDoesNotCountAsExtension(t *testing.T) {
	withTempRecordingsDir(t)

	owner := NewWSServer(&RecordingStore{})
	if err := owner.Start(0); err != nil {
		t.Fatalf("owner start: %v", err)
	}
	t.Cleanup(func() { owner.Close() })
	baseURL := fmt.Sprintf("ws://127.0.0.1:%d", owner.Port())

	proxy := NewProxyClient(owner.Port())
	if err := proxy.Connect(); err != nil {
		t.Fatalf("proxy connect: %v", err)
	}
	t.Cleanup(func() { proxy.Close() })

	st, err := proxy.RecorderStatus()
	if err != nil {
		t.Fatalf("status: %v", err)
	}
	if st.ExtensionConnected {
		t.Fatal("proxy connection must not count as extension_connected")
	}

	ext := dialTestWS(t, baseURL+"/")
	if err := ext.WriteJSON(map[string]any{"type": "hello"}); err != nil {
		t.Fatalf("hello: %v", err)
	}
	pollProxyStatus(t, proxy, 2*time.Second, func(s RecorderStatusInfo) bool { return s.ExtensionConnected })

	ext.Close()
	pollProxyStatus(t, proxy, 2*time.Second, func(s RecorderStatusInfo) bool { return !s.ExtensionConnected })
}

func TestProxyStopWithoutActiveSession(t *testing.T) {
	withTempRecordingsDir(t)

	owner := NewWSServer(&RecordingStore{})
	if err := owner.Start(0); err != nil {
		t.Fatalf("owner start: %v", err)
	}
	t.Cleanup(func() { owner.Close() })

	proxy := NewProxyClient(owner.Port())
	if err := proxy.Connect(); err != nil {
		t.Fatalf("proxy connect: %v", err)
	}
	t.Cleanup(func() { proxy.Close() })

	stop, err := proxy.Stop(nil)
	if err != nil {
		t.Fatalf("stop: %v", err)
	}
	if stop != nil {
		t.Fatalf("expected nil stop result, got %+v", stop)
	}
}

func TestProxyReconnectsToNewOwner(t *testing.T) {
	withTempRecordingsDir(t)

	ownerStore := &RecordingStore{}
	owner1 := NewWSServer(ownerStore)
	if err := owner1.Start(0); err != nil {
		t.Fatalf("owner1 start: %v", err)
	}
	port := owner1.Port()

	proxy := NewProxyClient(port)
	if err := proxy.Connect(); err != nil {
		t.Fatalf("proxy connect: %v", err)
	}
	t.Cleanup(func() { proxy.Close() })

	label := "first"
	if _, err := proxy.Start(&label); err != nil {
		t.Fatalf("first start: %v", err)
	}

	if err := owner1.Close(); err != nil {
		t.Fatalf("owner1 close: %v", err)
	}

	owner2 := NewWSServer(ownerStore)
	if err := owner2.Start(port); err != nil {
		t.Fatalf("owner2 start: %v", err)
	}
	t.Cleanup(func() { owner2.Close() })

	start, err := proxy.Start(&label)
	if err != nil {
		t.Fatalf("start after owner restart: %v", err)
	}
	if start.SessionID == "" {
		t.Fatal("expected non-empty session id after reconnect")
	}
}
