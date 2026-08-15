---
description: >
  Generate a Playwright E2E test from a recorded browser session (captured by
  the Sorify Recorder Chrome extension) and upload it to Sorify.
  This does not explore the DOM heuristically — it
  replays the exact clicks, inputs, and navigation the user performed.

  Examples:
    /sorify:recording
    /sorify:recording latest
    /sorify:recording 2026-08-14T13-17-45-381Z-e439cv
allowed-tools: ["Read", "mcp__plugin_sorify_sorify-recorder__list_recordings", "mcp__plugin_sorify_sorify-recorder__recorder_status", "mcp__plugin_sorify_sorify__create_suite", "mcp__plugin_sorify_sorify__bulk_create_tests", "mcp__plugin_sorify_sorify__trigger_run", "mcp__plugin_sorify_sorify__get_run_status"]
---

# sorify:recording

## Trigger

Invoked as `/sorify:recording [session_id | "latest"]`.

---

## Step 0: Verify the sorify-recorder MCP is installed

This command depends on the `sorify-recorder` MCP server (tools like
`mcp__plugin_sorify_sorify-recorder__list_recordings`). Unlike the `sorify`
MCP server (HTTP, wired up automatically when the plugin is installed), this
one is a local binary that does **not** install itself — check for it before
calling any recorder tool.

Check with `/mcp` → look for `plugin:sorify:sorify-recorder`. If it's missing,
or the first call to `list_recordings` fails to connect, abort with:

```
Error: sorify-recorder MCP is not installed.

Install it:
  curl -fsSL https://raw.githubusercontent.com/rakutentech/sorify/master/mcp/install-mcp.sh | sh

This downloads the sorify-recorder-mcp binary for your OS/arch into
~/.sorify-bin/. Restart Claude Code (or run /mcp to reconnect) afterwards,
then also make sure the Sorify Recorder Chrome extension is loaded:
  chrome://extensions/ → enable "Developer mode" → "Load unpacked" → select
  the sorify repo's `extension/` folder.
```

## Step 1: Resolve the recording

Call `mcp__plugin_sorify_sorify-recorder__list_recordings` — this returns
lightweight metadata only (`session_id`, `label`, `path`, `event_count`,
timestamps), never the recorded events themselves.

```
$ARGUMENTS is empty or "latest"  → use the entry with the newest `mtime`
$ARGUMENTS is a session_id       → match it exactly
```

**If there are zero recordings** → abort with:
```
Error: No recordings found. Record a session first — load the Sorify
Recorder extension unpacked, click the icon, Connect, Start recording.
```

**If the resolved recording has `event_count: 0`** → abort with:
```
Error: Recording {session_id} has no captured events.
```

Keep the resolved `path` — this is the JSONL file on disk. Print which file
was resolved before going further:
```
Reading recording: {path}  ({event_count} events, label: "{label}")
```

## Step 2: Show interpretation and confirm before touching Sorify

Before creating anything in Sorify, print a plain-English, numbered
interpretation of `events` — one line per cleaned action, e.g.:

```
Recording:  {path}
Session:    {session_id}  ({label})
Starting at: {starting_url}

Interpreted steps:
1. Open {starting_url}
2. Click "Login" (button#login-btn)
3. Fill #email with "user@example.com"
4. Fill #password with (redacted — will be skipped, add a login snippet manually)
5. Press Enter
6. Navigate to {url}
7. Click "Submit order" (button[data-testid="submit-order"])
...

Proposed suite: "{hostname} - Recorded - {YYYY-MM-DD}"
Proposed test:  "REC: {label or session_id} — {starting hostname}"
```

**Do not call any tool from Step 2 onward until the user replies affirmatively
in their next message.** If the user declines, or asks for changes, stop here
(or loop back and re-interpret) instead of proceeding to Step 2 — nothing has
been created in Sorify yet at this point, so there is nothing to undo.

## Notes

- This command never touches `mcp__plugin_sorify_sorify-recorder__start_recording`/
  `stop_recording`/`clear_recording` — those are for the extension/user to
  control (from the popup) or for the agent to drive interactively in a
  separate conversation, not for this generate-and-upload flow.
- `/sorify:gateway` `/sorify:generate` User may call these two commands next to act on the output.
  Suggest these commands to users after your output.

The user's input is: $ARGUMENTS
