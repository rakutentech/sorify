---
description: >
  Explain the Sorify plugin and its MCP server in detail, and answer
  questions about available MCP tools. If the question asks for live data
  (e.g. "list test suites", "show suite 5", "what tests are in suite 12"),
  calls the relevant read-only MCP tool and returns real results instead of
  just describing them.

  Examples:
    /sorify:gateway
    /sorify:gateway what MCP tools are available for tests?
    /sorify:gateway list my test suites
    /sorify:gateway show suite 3
allowed-tools: ["Bash", "Read", "mcp__plugin_sorify_sorify__list_suites", "mcp__plugin_sorify_sorify__get_suite", "mcp__plugin_sorify_sorify__list_tests", "mcp__plugin_sorify_sorify__get_test", "mcp__plugin_sorify_sorify__get_run", "mcp__plugin_sorify_sorify__get_run_status", "mcp__plugin_sorify_sorify__list_screenshots"]
---

# sorify:gateway

## Trigger

Invoked as `/sorify:gateway {question}`. The question may be empty (show full
overview), about how the plugin works, about a specific MCP tool, or a
request for live data from Sorify.

---

## Step 1: Classify the input

```
empty / "help" / "overview" / "how does this work"
  → Full Overview (Step 2)

mentions a specific tool name, or "what tools", "what can I do with X"
  → Tool Reference (Step 3), scoped to the matching section if one is named

asks for live data: "list suites", "show suite {id}", "what tests are in
suite {id}", "status of run {id}", "screenshots for result {id}"
  → Live Lookup (Step 4)
```

If the request is ambiguous, do the closest of these three rather than
asking a clarifying question — this is a read-only help command, so a wrong
guess costs nothing.

---

## Step 2: Full Overview

Output this, filling in `SORIFY_BASE_URL` from `~/.sorify` if present:

```
=== Sorify Plugin ===

Sorify is a Claude Code plugin that generates Playwright E2E tests from a
URL (optionally cross-referenced with source code), uploads them to a
Sorify TestSuite, runs them, and reports pass/fail.

It talks to the Sorify web app over MCP — every action available on the
Sorify dashboard (suites, tests, runs, screenshots) is also callable
as an MCP tool by Claude directly, without the dashboard UI.

Commands
──────────────────────────────────────────────
/sorify:generate {url} [source]      Generate + upload + run tests for a URL
/sorify:gateway [question]           This command — docs + live MCP lookups

Setup (~/.sorify)
──────────────────────────────────────────────
SORIFY_URL=https://your-sorify-host/sorify
SORIFY_USERNAME=you@example.com
SORIFY_PASSWORD=your-password

SORIFY_URL must be a real shell env var (source ~/.sorify with `set -a` in
your shell profile) so the MCP client can build the server URL from it.
USERNAME/PASSWORD are read directly by a helper script to build the
request's Basic-Auth header — they don't need to be exported.

MCP Server
──────────────────────────────────────────────
Name:    Sorify (registered in this plugin's .mcp.json as "sorify")
Purpose: Manage Sorify test suites, tests, runs, and screenshots
Tools:   23 total across 4 resource groups — run `/sorify:gateway tools` for
         the full reference, or `/sorify:gateway {topic}` for one group
         (suites / tests / runs / screenshots)

Dashboard: {SORIFY_BASE_URL}
```

---

## Step 3: Tool Reference

Use this table as ground truth — it mirrors `app/Mcp/Servers/SorifyServer.php`
and each tool's schema. If asked about a specific group, print only that
section; if asked "what tools exist" generally, print all five.

**Suites** — `App\Mcp\Tools\Suites\*`

| Tool | Params | Description |
|---|---|---|
| `list_suites` | `search?`, `per_page?` (10/50/100), `page?` | List suites with pass-rate stats; optional name/description search |
| `get_suite` | `suite_id` | One suite with stats, its tests, and 10 most recent runs. Includes `created_by` (user id, set automatically at creation time) |
| `create_suite` | `name`, `description?`, `base_url?`, `browser?` (chromium/firefox/webkit), `headless?`, `playwright_proxy?`, `proxy_rules?` (array of `{domain, proxy}`), `history_retention?` (3/5/10), `timeout_ms?` (10000/30000/60000/120000), `take_screenshot?`, `teams_webhook_url?`, `teams_webhook_proxy?`, `teams_notify_on_success?`, `teams_notify_on_failure?` | Create a suite. `created_by` is set automatically to the authenticated MCP user — not a caller-supplied param. `proxy_rules` entries are checked in order against each request's hostname (see **Proxy rule `domain` patterns** below); the first match wins, falling back to `playwright_proxy` |
| `update_suite` | `suite_id`, `name`, `description?`, `base_url?`, `browser?`, `headless?`, `playwright_proxy?`, `proxy_rules?` (array of `{domain, proxy}`), `history_retention?`, `timeout_ms?`, `take_screenshot?`, `teams_webhook_url?`, `teams_webhook_proxy?`, `teams_notify_on_success?`, `teams_notify_on_failure?` | Update a suite, including MS Teams run-completion notification settings. Changing `history_retention` prunes older runs/screenshots automatically. Passing `proxy_rules` replaces the suite's full rule set; omit it to leave existing rules untouched |

**Proxy rule `domain` patterns** — each rule's `domain` is a regular expression tested against the request's hostname (case-insensitive), checked on every request including each hop of a redirect chain:

| Pattern | Behavior |
|---|---|
| `^example\.com$` | **Exact host only** — matches `example.com`, not `foo.example.com` |
| `(^|\.)example\.com$` | **Host or any subdomain** — matches `example.com` and `foo.example.com`, but not `notexample.com` |
| `example\.com$` | Avoid — unanchored at the start, so it also matches unrelated hosts like `notexample.com` |
| `delete_suite` | `suite_id` | Delete a suite and all of its tests and runs |
| `update_suite_schedule` | `suite_id`, `cron_expression`, `timezone?`, `is_enabled?` | Create or update the cron schedule that runs a suite automatically |
| `delete_suite_schedule` | `suite_id` | Remove a suite's cron schedule |

**Tests** — `App\Mcp\Tools\Tests\*`

| Tool | Params | Description |
|---|---|---|
| `list_tests` | `suite_id` | List tests in a suite (no Playwright code) |
| `get_test` | `suite_id`, `test_id` | One test with its Playwright code and last 10 run results |
| `create_test` | `suite_id`, `name`, `playwright_code`, `description?`, `uploaded_by?` (must be an existing user's email), `status?` (active/disabled) | Create one test |
| `bulk_create_tests` | `suite_id`, `tests[]` (1–100, each `{name, playwright_code, description?, uploaded_by?, status?}`) | Create up to 100 tests in one call |
| `update_test` | `suite_id`, `test_id`, `name`, `description` (min 10 chars), `uploaded_by?` (must be an existing user's email) | Update metadata only — not the code |
| `update_test_code` | `suite_id`, `test_id`, `playwright_code` | Replace a test's code; reactivates the test |
| `toggle_test_status` | `suite_id`, `test_id` | Flip active ⇄ disabled |
| `delete_test` | `suite_id`, `test_id` | Delete one test |
| `bulk_delete_tests` | `suite_id`, `test_ids[]` | Delete multiple tests |

**Runs** — `App\Mcp\Tools\Runs\*`

| Tool | Params | Description |
|---|---|---|
| `trigger_run` | `suite_id`, `test_ids?` | Queue a run; omit `test_ids` to run all active tests. `triggered_by_user_id` is set automatically to the authenticated MCP user |
| `get_run_status` | `run_id` | Lightweight poll: status, passed/failed/error counts, duration — prefer this while polling |
| `get_run` | `run_id` | Full run with every test result, error messages/stacks, stdout, screenshots. Includes `triggered_by_user_id` |
| `cancel_run` | `run_id` | Cancel a pending or running run |
| `delete_run` | `run_id` | Delete a run and its results |

**Screenshots** — `App\Mcp\Tools\Screenshots\*`

| Tool | Params | Description |
|---|---|---|
| `list_screenshots` | `result_id` | List screenshots captured for one test result |
| `get_screenshot` | `screenshot_id` | Fetch a screenshot as inline viewable image content |

---

## Step 4: Live Lookup

For requests asking about actual current data, call the matching read-only
tool directly and present the result — do not just describe the tool.

```
"list suites" / "show test suites" / "what suites exist"
  → mcp__plugin_sorify_sorify__list_suites({})
  Present as a table: id, name, base_url, tests count, pass rate

"show suite {id}" / "details for suite {id}"
  → mcp__plugin_sorify_sorify__get_suite({ suite_id: {id} })

"list tests in suite {id}" / "what tests does {id} have"
  → mcp__plugin_sorify_sorify__list_tests({ suite_id: {id} })

"show test {test_id} in suite {suite_id}"
  → mcp__plugin_sorify_sorify__get_test({ suite_id, test_id })

"status of run {id}" / "did run {id} pass"
  → mcp__plugin_sorify_sorify__get_run_status({ run_id: {id} })

"full results for run {id}" / "why did run {id} fail"
  → mcp__plugin_sorify_sorify__get_run({ run_id: {id} })

"screenshots for result {id}"
  → mcp__plugin_sorify_sorify__list_screenshots({ result_id: {id} })
```

If an ID is required but missing from the question, run the closest list
tool first (e.g. `list_suites`) so the user can pick one, rather than
asking them to look it up elsewhere.

This command never calls the mutating tools (`create_*`, `update_*`,
`delete_*`, `trigger_run`, `cancel_run`, `bulk_*`) — those belong to
`/sorify:generate` or direct tool use with explicit user intent, not to a
help/lookup command.

---

## Notes

- This command is read-only by design (`allowed-tools` excludes every
  mutating MCP tool) — safe to run freely for exploration.
- Keep the Tool Reference table in sync with `app/Mcp/Servers/SorifyServer.php`
  and the individual tool classes under `app/Mcp/Tools/` if tools change.

The user's question is: $ARGUMENTS
