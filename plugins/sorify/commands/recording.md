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
allowed-tools: ["Read", "mcp__plugin_sorify_sorify-recorder__list_recordings", "mcp__plugin_sorify_sorify-recorder__recorder_status", "mcp__plugin_sorify_sorify__create_suite", "mcp__plugin_sorify_sorify__bulk_create_tests", "mcp__plugin_sorify_sorify__trigger_run", "mcp__plugin_sorify_sorify__get_run_status", "mcp__plugin_sorify_sorify__upload_suite_cookies", "mcp__plugin_sorify_sorify__update_suite"]
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
6. Navigate to {url}  (caused by: click on button#login-btn, navigation_type: form_submit)
7. Click "Submit order" (button[data-testid="submit-order"])
8. Expect toast "Order placed" to become visible  (assertion_hint after step 7)
...

Proposed suite: "{hostname} - Recorded - {YYYY-MM-DD}"
Proposed test:  "REC: {label or session_id} — {starting hostname}"
```

**Do not call any tool from Step 2 onward until the user replies affirmatively
in their next message.** If the user declines, or asks for changes, stop here
(or loop back and re-interpret) instead of proceeding to Step 2 — nothing has
been created in Sorify yet at this point, so there is nothing to undo.

## Field reference

Beyond the basics (`selector`, `selector_strategy`, `tag_name`, `text`,
`value`, `url`, `timestamp`), events may carry these fields — use them to
produce more accurate Playwright code, not just a blind action replay:

- `caused_by: {selector, tag_name, text, event_type}` — appears on
  `navigation`, `network_request`, `new_tab_opened`, and `assertion_hint`
  events. Ties the event back to the action that triggered it (e.g. "Click
  'Checkout' → causes navigation to /confirm").
- `navigation_type` — one of `link_click`, `typed_url`, `form_submit`,
  `reload`, `redirect`, `forward_back`, `pushstate`, `replacestate`,
  `history_api`, `hashchange`. Use it to decide whether to expect a full
  `page.goto`/click-triggered navigation vs. an SPA route change.
- `is_top_frame` / `frame_url` / `tab_id` — when `is_top_frame` is false, the
  event happened inside an iframe; generate `page.frameLocator(...)` against
  `frame_url` instead of acting on `page` directly.
- `opener_tab_id` / `new_tab_id` / `new_tab_url` (on `new_tab_opened` /
  `new_tab_loaded`) — a link/action opened a new tab. Generate
  `const [newPage] = await Promise.all([context.waitForEvent('page'), ...])`.
- `drag_and_drop` events, with a nested `source` (same shape as `describe()`)
  — generate `page.locator(source.selector).dragTo(page.locator(selector))`.
- `upload: true` / `files: [{name, size, type}]` on a `change` event — a file
  input was used. Only metadata was captured (no real path/content), so
  generate `.setInputFiles(...)` with a TODO/fixture placeholder rather than
  inventing a fake path.
- `select_multiple` / `selected_options: [{value, label}]` on a `change`
  event from a `<select>` — generate `.selectOption({ label })` (or
  `{ value }`), and pass an array if `select_multiple` is true.
- `focus` / `hover` event types — generate `.focus()` / `.hover()`. A
  `focus` event immediately before an `input`/`change` on the same selector
  is usually just setup, not a separate assertable step.
- `network_request` (`method`, `request_url`, `status`, `ok`, `duration_ms`),
  `console_error` (`message`, `stack`), `page_error` (`message`, `filename`,
  `lineno`) — only act on these when they carry a `caused_by` linking them to
  a step you're already generating (e.g. `expect(response.status()).toBe(200)`
  right after a submit). Ignore unlinked ones — they're usually
  analytics/polling noise, not part of the tested flow.
- `assertion_hint` (`appeared`, `disappeared`, `title_changed`, `url_changed`,
  `caused_by`) — the primary signal for generating real assertions instead of
  blind replay. Turn `appeared` entries into
  `expect(page.locator(selector)).toBeVisible()`/`toHaveText(text)`,
  `title_changed`/`url_changed` into `toHaveTitle()`/`toHaveURL()`. Note
  `url_changed` here is informational only — a `navigation` event is the
  authoritative source for "did a navigation happen," don't double-count.
- `cookies` rows (`{"type":"cookies","phase":"start|stop","cookies":[...],"timestamp":...}`)
  — cookie snapshots captured by the Chrome extension at recording start
  (the active tab's domain, pre-auth baseline) and at recording stop (all
  visited domains, final authenticated state). Each cookie follows the
  Playwright `addCookies()` shape (`{name, value, domain, path, expires,
  httpOnly, secure, sameSite}`). The `phase: "stop"` snapshot is the
  preferred source for uploading to a suite — it captures the post-login
  authenticated state. The `phase: "start"` snapshot is the pre-auth
  baseline (useful if the test needs to start unauthenticated and log in
  itself). When uploading cookies to a suite via
  `upload_suite_cookies`, filter to domains relevant to the test flow
  (e.g. exclude third-party analytics or OAuth provider cookies the user
  happened to visit) — the extension captures all cookies for all visited
  domains, not just the target site.

## Notes

- This command never touches `mcp__plugin_sorify_sorify-recorder__start_recording`/
  `stop_recording`/`clear_recording` — those are for the extension/user to
  control (from the popup) or for the agent to drive interactively in a
  separate conversation, not for this generate-and-upload flow.
- **Cookie upload**: if the recording contains `cookies` rows (check by
  reading the JSONL), and the user confirms creating a suite, extract the
  `phase: "stop"` cookie array and call
  `mcp__plugin_sorify_sorify__upload_suite_cookies` with the `suite_id` and
  the cookies array (filtered to domains relevant to the test flow). This
  makes every test run for the suite start already authenticated, so the
  generated test code does not need to perform a login flow. Mention to the
  user how many cookies were uploaded and for which domains. If the user
  declines the cookie upload, proceed with test creation only.
- `/sorify:gateway` `/sorify:generate` User may call these two commands next to act on the output.
  Suggest these commands to users after your output.

The user's input is: $ARGUMENTS
