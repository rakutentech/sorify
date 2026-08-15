---
description: >
  Generate a Playwright E2E test from a recorded browser session (captured by
  the Sorify Recorder Chrome extension) and upload it to Sorify.
  Unlike /sorify:generate, this does not explore the DOM heuristically — it
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

---

## Step 2: Read the recording directly (not via MCP)

Use the `Read` tool on `path` from Step 1. Do **not** ask an MCP tool to
return the events — the file can contain thousands of lines and MCP
tool-result payloads count against the conversation's token budget, while a
direct file read does not go through the model's context the same way for
large mechanical data. (This is the one step that differs from
`/sorify:generate`'s explore-then-generate flow — a recording already has
exact selectors, values, and order, so there is nothing to explore.)

Each line is one JSON object:
- `{"type":"session_start", "session_id", "label", "started_at"}` — first line
- `{"type":"event", "type":"click"|"input"|"change"|"submit"|"keydown"|"navigation", "url", "timestamp", "selector", "selector_strategy", "tag_name", "text", "attributes", "bounding_rect", "is_visible", "matched_count", "outer_html_snippet", "value"?, "key"?}` — one per captured action
- `{"type":"session_end", "session_id", "ended_at", "event_count"}` — last line

Extra per-event fields beyond `selector`, useful when the selector alone is
ambiguous or unreliable:
- `attributes` — dump of `id`/`class`/`name`/`type`/`placeholder`/`title`/`alt`/`href`/`role`/`aria-label`/`disabled`/`checked` present on the element at record time.
- `matched_count` — how many elements `document.querySelectorAll(selector)` matched on the recording page at capture time. `1` = selector was unique; `>1` = ambiguous, needs disambiguation when generating code (see Step 6).
- `is_visible` — whether the element had non-zero size and wasn't `display:none`/`visibility:hidden` at capture time. `false` usually means it was a mobile/SP-only element or hidden behind another interaction.
- `bounding_rect` — `{x, y, width, height}` at capture time, for reference only.
- `outer_html_snippet` — first 200 chars of `outerHTML`, for disambiguating by eye when the selector and attributes aren't enough.

Parse into an ordered `events` array (drop the `session_start`/`session_end`
meta lines after extracting `label` and the first event's `url` as the
starting URL).

**Clean up before generating code:**
- Redacted values (`"***redacted***"`, from password fields) → do not emit a
  `.fill()` for that field; instead emit a comment noting a password field
  was skipped and must be filled manually or via a login snippet.
- Consecutive `input` and `change` events on the same `selector` with the
  same `value` → collapse into a single fill action (the extension debounces
  `input` already; a trailing `change` on blur is usually a duplicate).
- A `navigation` event immediately following a `click` on the element that
  caused it → do not emit a separate `page.goto()`; the click already
  triggers it. Only the very first URL (from `session_start`/first event)
  becomes an explicit `page.goto()`.

Store the cleaned, ordered result as `events`.

---

## Step 3: Determine the Sorify base URL and suite name

Same as `/sorify:generate` Step 2 — read `SORIFY_URL`/`SORIFY_USERNAME` from
`~/.sorify` for the human-readable link and `uploaded_by`. Build the suite
name as `"{hostname of starting URL} - Recorded - {YYYY-MM-DD}"`.

---

## Step 4: Show interpretation and confirm before touching Sorify

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

Then explicitly ask the user:

```
Proceed to create this suite in Sorify and upload/run the generated test? (yes/no)
```

**Do not call any tool from Step 5 onward until the user replies affirmatively
in their next message.** If the user declines, or asks for changes, stop here
(or loop back and re-interpret) instead of proceeding to Step 5 — nothing has
been created in Sorify yet at this point, so there is nothing to undo.

---

## Step 5: Create Sorify suite

```
mcp__plugin_sorify_sorify__create_suite({
  name: "{hostname} - Recorded - {YYYY-MM-DD}",
  description: "/sorify:recording {session_id}",
  browser: "chromium",
  base_url: "{scheme}://{hostname}"
})
```

Extract `suite_id` from `structuredContent.suite.id`.

---

## Step 6: Generate the test (sub-agent)

**Sub-agent → Recording Generator**

```
Read: skills/shared/generator-patterns.md
(Only the "Understanding Sorify's Execution Model" section applies here —
the Pattern Selection Flow is for DOM-exploration mode and does not apply
to a literal action replay.)

## Cleaned, ordered events
{events}

## Starting URL
{starting_url}

Generate ONE Playwright test that replays this session in order:
- First action: `await page.goto('{starting_url}', { waitUntil: 'domcontentloaded' });`
  then `await page.waitForTimeout(2000);`
- For each `click`/`submit` event where `matched_count <= 1`:
  `const el = await page.waitForSelector('{selector}', {timeout:10000}); await el.click();`
- For each `click`/`submit` event where `matched_count > 1` (ambiguous selector —
  more than one element matched it when recorded): disambiguate with a `$$` +
  filter loop instead of trusting the bare selector, matching on `text` and/or
  `attributes` from that event:
  ```javascript
  await page.waitForSelector('{selector}', { timeout: 10000 });
  const candidates = await page.$$('{selector}');
  let el = null;
  for (const c of candidates) {
    const t = await c.textContent();
    if (t && t.includes('{text}')) { el = c; break; }
  }
  if (!el) el = candidates[0];
  await el.click();
  ```
- For each `input`/`change` event (after dedup, non-redacted):
  `const el = await page.waitForSelector('{selector}', {timeout:10000}); await el.fill('{value}');`
- For each `keydown` event: `await page.keyboard.press('{key}');`
- If an event has `is_visible: false`, it was likely a mobile/SP-only or
  hidden-at-capture-time element — Sorify runs at a desktop viewport, so
  `waitForSelector`'s default `state: 'visible'` will time out on it. Use
  `{ state: 'attached', timeout: 10000 }` for that step instead of the default,
  or skip it with a comment if it was clearly a mobile-only control.
- Follow every selector-quoting and forbidden-API rule from the execution
  model section exactly (double-quote attribute values inside selectors,
  never `page.click()`/`page.locator()`/`page.$()`, always
  `page.waitForSelector` before acting).
- Name the test `"REC: {label or session_id} — {starting hostname}"`.

Return ONE object `{name, playwright_code}` as a JSON — do NOT upload to Sorify.
```

Store as `recorded_test`.

---

## Step 7: Upload and run

```
mcp__plugin_sorify_sorify__bulk_create_tests({
  suite_id: {suite_id},
  tests: [{ ...recorded_test, uploaded_by: "{UPLOADED_BY}" }]
})

mcp__plugin_sorify_sorify__trigger_run({ suite_id: {suite_id} })
```

Poll `mcp__plugin_sorify_sorify__get_run_status({ run_id })` every ~5s until
no longer `pending`/`running`.

---

## Step 8: Output result summary

```
=== Test Generated from Recording: {session_id} ===

Recording:  {path}   ({event_count} events → {test_count} test)
Sorify:     {SORIFY_BASE_URL}

PASS:    {passed_count} ✅
FAIL:    {failed_count} ❌

View: {SORIFY_BASE_URL}/runs/{run_id}
```

---

## Notes

- This command never touches `mcp__plugin_sorify_sorify-recorder__start_recording`/
  `stop_recording`/`clear_recording` — those are for the extension/user to
  control (from the popup) or for the agent to drive interactively in a
  separate conversation, not for this generate-and-upload flow.
- Step 4's confirmation gate is the only place this command pauses — every
  step before it (list/read/parse) is read-only, and every step after it is
  a Sorify write, so it is the correct place to draw the line.
- `/sorify:gateway show suite {id}` afterward to inspect the result.

The user's input is: $ARGUMENTS
