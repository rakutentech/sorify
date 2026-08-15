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

func registerMCPTools(server *mcp.Server, store *RecordingStore, ws *WSServer) {
	mcp.AddTool(server, &mcp.Tool{
		Name:  "start_recording",
		Title: "Start recording",
		Description: "Start a new recording session. Tells the connected Chrome extension to begin capturing " +
			"clicks/inputs/navigation. Returns the session id and the JSONL file path it will be written to.",
	}, func(_ context.Context, _ *mcp.CallToolRequest, args startRecordingArgs) (*mcp.CallToolResult, any, error) {
		result, err := store.Start(args.Label)
		if err != nil {
			return nil, nil, err
		}
		ws.BroadcastStatus()
		return textResult(map[string]any{"session_id": result.SessionID, "path": result.Path})
	})

	mcp.AddTool(server, &mcp.Tool{
		Name:        "stop_recording",
		Title:       "Stop recording",
		Description: "Stop the active (or given) recording session and finalize its JSONL file.",
	}, func(_ context.Context, _ *mcp.CallToolRequest, args stopRecordingArgs) (*mcp.CallToolResult, any, error) {
		result, err := store.Stop(args.SessionID)
		if err != nil {
			return nil, nil, err
		}
		ws.BroadcastStatus()
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
		status := store.Status()
		return textResult(map[string]any{
			"extension_connected": ws.IsExtensionConnected(),
			"active_session_id":   statusSessionID(status),
			"event_count":         status.EventCount,
		})
	})

	mcp.AddTool(server, &mcp.Tool{
		Name:  "list_recordings",
		Title: "List recordings",
		Description: "List saved recording sessions (id, label, file path, event count, timestamps) without their " +
			"contents. Read the file directly to get the recorded events.",
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
