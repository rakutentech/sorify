package main

import (
	"context"
	"log"
	"os"
	"os/signal"
	"strconv"
	"syscall"

	"github.com/modelcontextprotocol/go-sdk/mcp"
)

func main() {
	// Logs must go to stderr only — stdout is the MCP JSON-RPC channel to
	// the coding agent, and any plain text written there would corrupt it.
	log.SetOutput(os.Stderr)
	log.SetFlags(0)

	port := 7420
	if raw := os.Getenv("SORIFY_RECORDER_PORT"); raw != "" {
		if parsed, err := strconv.Atoi(raw); err == nil {
			port = parsed
		}
	}

	store := &RecordingStore{}
	ws := NewWSServer(store)
	if err := ws.Start(port); err != nil {
		os.Exit(1)
	}

	server := mcp.NewServer(&mcp.Implementation{Name: "sorify-recorder", Version: "0.1.0"}, nil)
	registerMCPTools(server, store, ws)

	ctx, stop := signal.NotifyContext(context.Background(), os.Interrupt, syscall.SIGTERM)
	defer stop()

	go func() {
		<-ctx.Done()
		log.Println("[sorify-recorder-mcp] received shutdown signal, shutting down")
		ws.Close()
		log.Println("[sorify-recorder-mcp] stopped")
		os.Exit(0)
	}()

	log.Println("[sorify-recorder-mcp] MCP server ready on stdio")
	if err := server.Run(ctx, &mcp.StdioTransport{}); err != nil && ctx.Err() == nil {
		log.Printf("[sorify-recorder-mcp] MCP server error: %v", err)
		os.Exit(1)
	}
}
