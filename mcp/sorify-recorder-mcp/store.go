package main

import (
	"bufio"
	"encoding/json"
	"fmt"
	"maps"
	"math/rand"
	"os"
	"path/filepath"
	"sort"
	"strings"
	"sync"
	"time"
)

var recordingsDir = filepath.Join(mustHomeDir(), ".sorify-recordings")

func mustHomeDir() string {
	home, err := os.UserHomeDir()
	if err != nil {
		panic(err)
	}
	return home
}

func ensureRecordingsDir() error {
	return os.MkdirAll(recordingsDir, 0o755)
}

func sessionPath(sessionID string) string {
	return filepath.Join(recordingsDir, sessionID+".jsonl")
}

func newSessionID() string {
	stamp := strings.NewReplacer(":", "-", ".", "-").Replace(time.Now().UTC().Format("2006-01-02T15:04:05.000Z"))
	return fmt.Sprintf("%s-%06x", stamp, rand.Int31n(1<<24))
}

func appendLine(sessionID string, obj map[string]any) error {
	if err := ensureRecordingsDir(); err != nil {
		return err
	}
	line, err := json.Marshal(obj)
	if err != nil {
		return err
	}
	f, err := os.OpenFile(sessionPath(sessionID), os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0o644)
	if err != nil {
		return err
	}
	defer f.Close()
	_, err = f.Write(append(line, '\n'))
	return err
}

// RecordingStore mirrors src/store.js: a file-backed session store under
// ~/.sorify-recordings. The WS server and MCP tool handlers run on separate
// goroutines, so state is guarded by mu (the original JS had no such need).
type RecordingStore struct {
	mu               sync.Mutex
	activeSessionID  string
	activeEventCount int
}

type StartResult struct {
	SessionID string
	Path      string
}

func (s *RecordingStore) Start(label *string) (StartResult, error) {
	s.mu.Lock()
	defer s.mu.Unlock()

	if err := ensureRecordingsDir(); err != nil {
		return StartResult{}, err
	}
	sessionID := newSessionID()
	s.activeSessionID = sessionID
	s.activeEventCount = 0

	if err := appendLine(sessionID, map[string]any{
		"type":       "session_start",
		"session_id": sessionID,
		"label":      label,
		"started_at": time.Now().UTC().Format(time.RFC3339Nano),
	}); err != nil {
		return StartResult{}, err
	}
	return StartResult{SessionID: sessionID, Path: sessionPath(sessionID)}, nil
}

func (s *RecordingStore) RecordEvent(event map[string]any) (recorded bool, eventCount int, err error) {
	s.mu.Lock()
	defer s.mu.Unlock()

	if s.activeSessionID == "" {
		return false, 0, nil
	}
	row := map[string]any{"type": "event"}
	maps.Copy(row, event)
	if err := appendLine(s.activeSessionID, row); err != nil {
		return false, 0, err
	}
	s.activeEventCount++
	return true, s.activeEventCount, nil
}

// RecordCookies writes a cookies row to the active session's JSONL file.
// The Chrome extension sends cookie snapshots at recording start (the
// pre-auth baseline for the active tab's domain) and at recording stop
// (the final authenticated state for all visited domains). Each cookie
// snapshot counts as one row in the session's event count.
func (s *RecordingStore) RecordCookies(phase string, cookies []map[string]any) (recorded bool, eventCount int, err error) {
	s.mu.Lock()
	defer s.mu.Unlock()

	if s.activeSessionID == "" {
		return false, 0, nil
	}
	row := map[string]any{
		"type":    "cookies",
		"phase":   phase,
		"cookies": cookies,
	}
	if err := appendLine(s.activeSessionID, row); err != nil {
		return false, 0, err
	}
	s.activeEventCount++
	return true, s.activeEventCount, nil
}

type StopResult struct {
	SessionID  string
	Path       string
	EventCount int
}

func (s *RecordingStore) Stop(sessionID *string) (*StopResult, error) {
	s.mu.Lock()
	defer s.mu.Unlock()

	targetID := s.activeSessionID
	if sessionID != nil && *sessionID != "" {
		targetID = *sessionID
	}
	if targetID == "" {
		return nil, nil
	}

	if err := appendLine(targetID, map[string]any{
		"type":        "session_end",
		"session_id":  targetID,
		"ended_at":    time.Now().UTC().Format(time.RFC3339Nano),
		"event_count": s.activeEventCount,
	}); err != nil {
		return nil, err
	}

	result := &StopResult{SessionID: targetID, Path: sessionPath(targetID), EventCount: s.activeEventCount}
	if targetID == s.activeSessionID {
		s.activeSessionID = ""
		s.activeEventCount = 0
	}
	return result, nil
}

type StatusResult struct {
	ActiveSessionID string
	EventCount      int
}

func (s *RecordingStore) Status() StatusResult {
	s.mu.Lock()
	defer s.mu.Unlock()
	return StatusResult{ActiveSessionID: s.activeSessionID, EventCount: s.activeEventCount}
}

type RecordingSummary struct {
	SessionID       string  `json:"session_id"`
	Label           *string `json:"label"`
	Path            string  `json:"path"`
	EventCount      int     `json:"event_count"`
	CookieSnapshots int     `json:"cookie_snapshots"`
	StartedAt       *string `json:"started_at"`
	EndedAt         *string `json:"ended_at"`
	Mtime           string  `json:"mtime"`
}

func (s *RecordingStore) List() ([]RecordingSummary, error) {
	if err := ensureRecordingsDir(); err != nil {
		return nil, err
	}
	entries, err := os.ReadDir(recordingsDir)
	if err != nil {
		return nil, err
	}

	summaries := make([]RecordingSummary, 0, len(entries))
	for _, entry := range entries {
		name := entry.Name()
		if !strings.HasSuffix(name, ".jsonl") {
			continue
		}
		full := filepath.Join(recordingsDir, name)
		info, err := entry.Info()
		if err != nil {
			return nil, err
		}
		summary, err := summarizeRecording(full, strings.TrimSuffix(name, ".jsonl"), info.ModTime())
		if err != nil {
			return nil, err
		}
		summaries = append(summaries, summary)
	}

	sort.Slice(summaries, func(i, j int) bool {
		return summaries[i].Mtime > summaries[j].Mtime
	})
	return summaries, nil
}

func summarizeRecording(path, sessionID string, mtime time.Time) (RecordingSummary, error) {
	summary := RecordingSummary{SessionID: sessionID, Path: path, Mtime: mtime.UTC().Format(time.RFC3339Nano)}

	f, err := os.Open(path)
	if err != nil {
		return summary, err
	}
	defer f.Close()

	scanner := bufio.NewScanner(f)
	for scanner.Scan() {
		line := scanner.Text()
		if line == "" {
			continue
		}
		var row struct {
			Type       string `json:"type"`
			Label      *string `json:"label"`
			StartedAt  *string `json:"started_at"`
			EndedAt    *string `json:"ended_at"`
			EventCount int    `json:"event_count"`
		}
		if err := json.Unmarshal([]byte(line), &row); err != nil {
			return summary, err
		}
		switch row.Type {
		case "session_start":
			summary.Label = row.Label
			summary.StartedAt = row.StartedAt
		case "event":
			summary.EventCount++
		case "cookies":
			summary.CookieSnapshots++
		case "session_end":
			summary.EndedAt = row.EndedAt
			summary.EventCount = row.EventCount
		}
	}
	return summary, scanner.Err()
}

func (s *RecordingStore) Clear(sessionID string) (bool, error) {
	s.mu.Lock()
	defer s.mu.Unlock()

	full := sessionPath(sessionID)
	if _, err := os.Stat(full); os.IsNotExist(err) {
		return false, nil
	} else if err != nil {
		return false, err
	}
	if err := os.Remove(full); err != nil {
		return false, err
	}
	if sessionID == s.activeSessionID {
		s.activeSessionID = ""
		s.activeEventCount = 0
	}
	return true, nil
}
