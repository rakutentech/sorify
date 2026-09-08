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

	// When the WebSocket port is already taken, another instance of this
	// server (e.g. spawned by a different agent session) owns the Chrome
	// extension connection. Instead of exiting — which made every additional
	// agent session report the MCP as "not connected" — attach to that
	// instance in proxy mode.
	var backend recorderBackend
	if err := ws.Start(port); err != nil {
		if !isAddrInUse(err) {
			log.Printf("[sorify-recorder-mcp] WebSocket server error: %v", err)
			os.Exit(1)
		}
		log.Printf("[sorify-recorder-mcp] port %d already in use — attaching to the existing instance in proxy mode", port)
		proxy := NewProxyClient(port)
		if err := proxy.Connect(); err != nil {
			log.Printf("[sorify-recorder-mcp] could not attach to the instance on port %d: %v", port, err)
			os.Exit(1)
		}
		defer proxy.Close()
		backend = proxy
	} else {
		backend = &ownerBackend{store: store, ws: ws}
	}

	server := mcp.NewServer(&mcp.Implementation{Name: "sorify-recorder", Version: "0.2.0"}, nil)
	registerMCPTools(server, store, backend)

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
