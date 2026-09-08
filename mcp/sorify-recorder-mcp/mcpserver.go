package main

import (
	"context"
	"encoding/json"

	"github.com/modelcontextprotocol/go-sdk/mcp"
)

// mirrors src/mcp-server.js: registers the same 5 tools with the same JSON
// output shapes, so extension/background.js and any agent already reading
// tool output need no changes.
func textResult(obj any) (*mcp.CallToolResult, any, error) {
	body, err := json.Marshal(obj)
	if err != nil {
		return nil, nil, err
	}
	return &mcp.CallToolResult{Content: []mcp.Content{&mcp.TextContent{Text: string(body)}}}, nil, nil
}

type startRecordingArgs struct {
	Label *string `json:"label,omitempty" jsonschema:"optional label for the recording session"`
}

type stopRecordingArgs struct {
	SessionID *string `json:"session_id,omitempty" jsonschema:"optional session id; defaults to the active session"`
}

type clearRecordingArgs struct {
	SessionID string `json:"session_id" jsonschema:"the session id to delete"`
}

type noArgs struct{}

// recorderBackend is the live-recorder half of the tool surface: start/stop
// and extension/status queries. This instance implements it directly when it
// owns the WebSocket port; otherwise a ProxyClient forwards to the owning
// instance. list/clear are deliberately not part of it — they operate on the
// file-backed recordings directory, which every instance can do.
type recorderBackend interface {
	Start(label *string) (StartResult, error)
	Stop(sessionID *string) (*StopResult, error)
	RecorderStatus() (RecorderStatusInfo, error)
}

// ownerBackend drives the recorder directly: this instance owns the
// WebSocket port and the Chrome extension connects to it.
type ownerBackend struct {
	store *RecordingStore
	ws    *WSServer
}

func (b *ownerBackend) Start(label *string) (StartResult, error) {
	res, err := b.store.Start(label)
	if err == nil {
		b.ws.BroadcastStatus()
	}
	return res, err
}

func (b *ownerBackend) Stop(sessionID *string) (*StopResult, error) {
	res, err := b.store.Stop(sessionID)
	if err == nil {
		b.ws.BroadcastStatus()
	}
	return res, err
}

func (b *ownerBackend) RecorderStatus() (RecorderStatusInfo, error) {
	st := b.store.Status()
	return RecorderStatusInfo{
		ActiveSessionID:    st.ActiveSessionID,
		EventCount:         st.EventCount,
		ExtensionConnected: b.ws.IsExtensionConnected(),
	}, nil
}

func registerMCPTools(server *mcp.Server, store *RecordingStore, backend recorderBackend) {
	mcp.AddTool(server, &mcp.Tool{
		Name:  "start_recording",
		Title: "Start recording",
		Description: "Start a new recording session. Tells the connected Chrome extension to begin capturing " +
			"clicks/inputs/navigation. A cookie snapshot for the active tab's domain is captured at start " +
			"(the pre-auth baseline) and written to the JSONL as a cookies row. Returns the session id and " +
			"the JSONL file path it will be written to.",
	}, func(_ context.Context, _ *mcp.CallToolRequest, args startRecordingArgs) (*mcp.CallToolResult, any, error) {
		result, err := backend.Start(args.Label)
		if err != nil {
			return nil, nil, err
		}
		return textResult(map[string]any{"session_id": result.SessionID, "path": result.Path})
	})

	mcp.AddTool(server, &mcp.Tool{
		Name:        "stop_recording",
		Title:       "Stop recording",
		Description: "Stop the active (or given) recording session and finalize its JSONL file. The final cookie snapshot (for all domains visited during the session) is captured automatically and included as a cookies row in the JSONL.",
	}, func(_ context.Context, _ *mcp.CallToolRequest, args stopRecordingArgs) (*mcp.CallToolResult, any, error) {
		result, err := backend.Stop(args.SessionID)
		if err != nil {
			return nil, nil, err
		}
		if result == nil {
			return textResult(map[string]any{"error": "no_active_session"})
		}
		return textResult(map[string]any{
			"session_id":  result.SessionID,
			"path":        result.Path,
			"event_count": result.EventCount,
		})
	})

	mcp.AddTool(server, &mcp.Tool{
		Name:  "recorder_status",
		Title: "Recorder status",
		Description: "Check whether the Chrome extension is currently connected to this bridge, and whether a " +
			"recording session is active.",
	}, func(_ context.Context, _ *mcp.CallToolRequest, _ noArgs) (*mcp.CallToolResult, any, error) {
		info, err := backend.RecorderStatus()
		if err != nil {
			return nil, nil, err
		}
		activeSessionID := any(info.ActiveSessionID)
		if info.ActiveSessionID == "" {
			activeSessionID = nil
		}
		return textResult(map[string]any{
			"extension_connected": info.ExtensionConnected,
			"active_session_id":   activeSessionID,
			"event_count":         info.EventCount,
		})
	})

	mcp.AddTool(server, &mcp.Tool{
		Name:  "list_recordings",
		Title: "List recordings",
		Description: "List saved recording sessions (id, label, file path, event count, cookie snapshot count, " +
			"timestamps) without their contents. Read the file directly to get the recorded events and cookie snapshots.",
	}, func(_ context.Context, _ *mcp.CallToolRequest, _ noArgs) (*mcp.CallToolResult, any, error) {
		recordings, err := store.List()
		if err != nil {
			return nil, nil, err
		}
		return textResult(map[string]any{"recordings": recordings})
	})

	mcp.AddTool(server, &mcp.Tool{
		Name:        "clear_recording",
		Title:       "Clear recording",
		Description: "Delete a saved recording session's JSONL file.",
	}, func(_ context.Context, _ *mcp.CallToolRequest, args clearRecordingArgs) (*mcp.CallToolResult, any, error) {
		deleted, err := store.Clear(args.SessionID)
		if err != nil {
			return nil, nil, err
		}
		return textResult(map[string]any{"deleted": deleted})
	})
}
