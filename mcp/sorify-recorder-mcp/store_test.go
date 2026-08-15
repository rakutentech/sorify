package main

import (
	"os"
	"testing"
)

func withTempRecordingsDir(t *testing.T) {
	t.Helper()
	dir := t.TempDir()
	prev := recordingsDir
	recordingsDir = dir
	t.Cleanup(func() { recordingsDir = prev })
	_ = os.MkdirAll(dir, 0o755)
}

func TestStoreLifecycle(t *testing.T) {
	withTempRecordingsDir(t)
	store := &RecordingStore{}

	label := "manual"
	start, err := store.Start(&label)
	if err != nil {
		t.Fatalf("Start: %v", err)
	}
	if start.SessionID == "" {
		t.Fatal("expected non-empty session id")
	}

	recorded, count, err := store.RecordEvent(map[string]any{"kind": "click", "selector": "#go"})
	if err != nil {
		t.Fatalf("RecordEvent: %v", err)
	}
	if !recorded || count != 1 {
		t.Fatalf("expected recorded=true count=1, got recorded=%v count=%d", recorded, count)
	}

	status := store.Status()
	if status.ActiveSessionID != start.SessionID || status.EventCount != 1 {
		t.Fatalf("unexpected status: %+v", status)
	}

	stop, err := store.Stop(nil)
	if err != nil {
		t.Fatalf("Stop: %v", err)
	}
	if stop == nil || stop.SessionID != start.SessionID || stop.EventCount != 1 {
		t.Fatalf("unexpected stop result: %+v", stop)
	}

	afterStop := store.Status()
	if afterStop.ActiveSessionID != "" || afterStop.EventCount != 0 {
		t.Fatalf("expected cleared status after stop, got %+v", afterStop)
	}

	recordings, err := store.List()
	if err != nil {
		t.Fatalf("List: %v", err)
	}
	if len(recordings) != 1 {
		t.Fatalf("expected 1 recording, got %d", len(recordings))
	}
	got := recordings[0]
	if got.SessionID != start.SessionID || got.EventCount != 1 || got.Label == nil || *got.Label != label {
		t.Fatalf("unexpected summary: %+v", got)
	}
	if got.StartedAt == nil || got.EndedAt == nil {
		t.Fatalf("expected started_at and ended_at to be set: %+v", got)
	}

	deleted, err := store.Clear(start.SessionID)
	if err != nil {
		t.Fatalf("Clear: %v", err)
	}
	if !deleted {
		t.Fatal("expected deleted=true")
	}

	deletedAgain, err := store.Clear(start.SessionID)
	if err != nil {
		t.Fatalf("Clear (again): %v", err)
	}
	if deletedAgain {
		t.Fatal("expected deleted=false for missing session")
	}
}

func TestRecordEventWithoutActiveSession(t *testing.T) {
	withTempRecordingsDir(t)
	store := &RecordingStore{}

	recorded, count, err := store.RecordEvent(map[string]any{"kind": "click"})
	if err != nil {
		t.Fatalf("RecordEvent: %v", err)
	}
	if recorded || count != 0 {
		t.Fatalf("expected recorded=false count=0, got recorded=%v count=%d", recorded, count)
	}
}

func TestStopWithoutActiveSession(t *testing.T) {
	withTempRecordingsDir(t)
	store := &RecordingStore{}

	stop, err := store.Stop(nil)
	if err != nil {
		t.Fatalf("Stop: %v", err)
	}
	if stop != nil {
		t.Fatalf("expected nil stop result, got %+v", stop)
	}
}
