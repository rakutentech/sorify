---
description: >
  Generate Playwright E2E tests and upload them to Sorify.
  Accepts free-form natural language input in English or Japanese.
  Sorify connection is determined automatically from ~/.sorify.

  Examples:
    /sorify:generate https://stg.example.com/dashboard/
    /sorify:generate https://stg.example.com/ https://github.com/your-org/your-repo
    /sorify:generate https://stg.example.com/ /path/to/local/repo
allowed-tools: ["Bash", "Read", "Write", "Edit", "mcp__playwright__browser_navigate", "mcp__playwright__browser_evaluate", "mcp__playwright__browser_click", "mcp__playwright__browser_wait_for", "mcp__playwright__browser_close", "mcp__playwright__browser_snapshot", "mcp__playwright__browser_take_screenshot", "mcp__plugin_sorify_sorify__create_suite", "mcp__plugin_sorify_sorify__update_suite", "mcp__plugin_sorify_sorify__get_suite", "mcp__plugin_sorify_sorify__bulk_create_tests", "mcp__plugin_sorify_sorify__trigger_run", "mcp__plugin_sorify_sorify__get_run_status"]
---

# sorify

## Trigger

Invoked from the `generate` command with natural language or structured arguments.

---

## Step 1: Understand the input and determine mode

The input may be plain arguments, structured text, or natural language in any language.
Read the entire input and extract:

| What | How to identify |
|---|---|
| `target_url` | Any `https://` or `http://` URL that is clearly the service being tested (NOT a GHE/GitHub repo URL) |
| `source` | A GHE/GitHub URL, or a local filesystem path (`/`, `~/`, `./` prefix) — optional |

**Examples:**
```
https://stg.example.com/
  → target_url = https://stg.example.com/, source = none

https://stg.example.com/ https://github.com/your-org/your-repo
  → target_url = https://stg.example.com/, source = ghe (order does not matter)

https://github.com/your-org/your-repo https://stg.example.com/
  → same result

このサービスのテストを作成して。URL: https://stg.example.com/ リポジトリ: https://github.com/your-org/your-repo
  → target_url = https://stg.example.com/, source = ghe

/Users/hiro/projects/myapp https://stg.example.com/
  → target_url = https://stg.example.com/, source = /Users/hiro/projects/myapp
```

**If target_url cannot be identified** → abort with error:
```
Error: No target URL found. Please provide the URL of the service to test.
```

**Determine mode:**
```
source absent or empty  → DOM Mode
source is GHE/GitHub URL (contains 'ghe.' / 'github.com' / 'your-ghe-domain')  → GHE Source Mode
source is local path (starts with '/', '~/', './')  → Local Source Mode
```

---

## Step 2: Determine the Sorify base URL

The Sorify MCP server (configured in this plugin's `.mcp.json`) already handles
authentication transparently — no login step is needed here. Read `SORIFY_URL`
from `~/.sorify` only for building the human-readable run link in the final summary:

```bash
if [ ! -f ~/.sorify ]; then
  echo "Error: ~/.sorify not found. Please create it with your Sorify credentials."
  echo "See the plugin README for the required format."
  exit 1
fi

SORIFY_BASE_URL=$(grep "^SORIFY_URL" ~/.sorify | cut -d= -f2)
UPLOADED_BY=$(grep "^SORIFY_USERNAME" ~/.sorify | cut -d= -f2)
```

---

## Step 4: Clear browser session

This clears localStorage, sessionStorage, and cookies in the exploration
browser so the agent sees the page as a first-time visitor. Note: if the
suite has cookies configured (via the dashboard or `upload_suite_cookies`),
they are injected by Sorify at test **run** time — not during this
exploration step — so the generated test code does not need to perform a
login flow itself. The exploration browser state and the suite's stored
cookies are independent.

**Run directly in the parent — do not delegate.**

```javascript
// mcp__playwright__browser_navigate to {scheme}://{hostname}/
// then mcp__playwright__browser_evaluate:
() => {
  localStorage.clear();
  sessionStorage.clear();
  document.cookie.split(';').forEach(c => {
    const key = c.trim().split('=')[0];
    const host = location.hostname;
    const parts = host.split('.');
    for (let i = 0; i < parts.length - 1; i++) {
      const domain = '.' + parts.slice(i).join('.');
      document.cookie = key + '=;expires=Thu, 01 Jan 1970 00:00:00 UTC;path=/;domain=' + domain;
    }
    document.cookie = key + '=;expires=Thu, 01 Jan 1970 00:00:00 UTC;path=/';
  });
  return 'cleared';
}
```

---

## Step 5: Create Sorify suite

**Determine suite variables first.** Collect any service-specific values the
tests will need — typically login credentials, the target base URL, and any
environment-specific values. These become **suite variables**: stored on the
suite and injected into every test run as a `variables` object in the
Playwright code scope (referenced as `variables.KEY`). Prefer variables over
hardcoding secrets/values in the generated test code.

- Variable `key`s must be valid JavaScript identifiers (`^[A-Za-z_][A-Za-z0-9_]*$`).
- Target-webpage test-account credentials come from the user (supplied in the
  `/sorify:generate` prompt) and/or are already configured on a reused suite.
  Call `get_suite` first when reusing a suite to read its existing `variables`;
  reuse any existing keys and only add missing ones, preferring existing suite
  keys. Never redefine a key that already exists.
- NEVER read `SORIFY_USERNAME`/`SORIFY_PASSWORD` from `~/.sorify` into suite
  variables — those are private MCP-connection credentials, not test-account
  credentials. The two are unrelated even when keys look similar.
- If no test credentials are supplied and none exist on the suite, treat the
  page as public: omit `USERNAME`/`PASSWORD` and still define `BASE_URL`.

**If reusing an existing suite instead of creating a new one:** call
`get_suite` first to read its existing `variables`, reuse any that are already
present, and only call `update_suite` (passing `variables`) to add the missing
ones. Never duplicate a key that already exists on the suite — extend the set.

> ⚠️ **Credential boundary:** suite `variables` hold test-account credentials for
> the target webpage ONLY. Never copy anything from `~/.sorify`
> (`SORIFY_USERNAME`/`SORIFY_PASSWORD` are MCP-connection auth, private) into a
> suite variable value.

Call the `create_suite` tool with `variables`:

```
mcp__plugin_sorify_sorify__create_suite({
  name: "{hostname} - {path} - {YYYY-MM-DD}",
  description: "/sorify:generate {target_url}",
  browser: "chromium",
  base_url: "{scheme}://{hostname}",
  variables: [
    { key: "BASE_URL",  value: "{scheme}://{hostname}" },
    { key: "USERNAME",  value: "<actual login email>" },   // omit if no login
    { key: "PASSWORD",  value: "<actual login password>" } // omit if no login
  ]
})
```

The result's `structuredContent.suite.id` is the `suite_id` — extract and keep it.
Then **build LOGIN_SNIPPET** that references `variables.*` (no hardcoded secrets):

```javascript
// If credentials are available (reference suite variables — no hardcoded secrets):
await page.goto(variables.BASE_URL + '/', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(2000);
if (page.url().includes('/login') || page.url().includes('/auth') || page.url().includes('/signin')) {
  await page.waitForSelector('actual-username-selector', { timeout: 15000 });
  await page.fill('actual-username-selector', variables.USERNAME);
  await page.fill('actual-password-selector', variables.PASSWORD);
  await page.keyboard.press('Enter');
  await page.waitForSelector('actual-post-login-selector', { timeout: 30000 });
}

// If no credentials are configured (public page):
await page.goto(variables.BASE_URL + '/', { waitUntil: 'domcontentloaded' });
await page.waitForTimeout(2000);
```

> The generated DOM and condition-based test code (Steps 6) MUST reference
> `variables.KEY` for any credential / URL / env-specific value rather than
> hardcoding it — `variables` is in scope at run time.

---

## Step 6: Execute the appropriate mode

---

### DOM Mode (no source)

**Sub-agent A → Explorer**

```
Read: skills/shared/explorer-instructions.md

## Target URL
{target_url}

Return ONLY a JSON string.
```

Store as `exploration_json`.

**Sub-agent B → DOM Generator**

```
Read: skills/shared/generator-patterns.md

## Exploration result JSON
{exploration_json}

## Login snippet
```javascript
{LOGIN_SNIPPET}
```

Generate DOM-based test code from the exploration JSON.
Prefix every test name with "DOM: ".
Return the tests as a JSON array — do NOT upload to Sorify.
```

Store result as `dom_tests` (JSON array of `{name, playwright_code}` objects).

**Upload and run (parent agent, DOM Mode only):**

Add `uploaded_by: "{UPLOADED_BY}"` to every entry in `dom_tests` (already sorted
c1,c2,...,i1-c1,i1-c2,...), then call the `bulk_create_tests` and `trigger_run` tools:

```
mcp__plugin_sorify_sorify__bulk_create_tests({
  suite_id: {suite_id},
  tests: dom_tests   // each entry: {name, playwright_code, uploaded_by}
})

mcp__plugin_sorify_sorify__trigger_run({ suite_id: {suite_id} })
```

The `trigger_run` result's `structuredContent.run_id` is the `run_id`.
Poll for completion and report results (see Step 7).

---

### GHE Source Mode / Local Source Mode

Both DOM-based tests and condition-based tests are generated and uploaded to the SAME suite.

**Sub-agent A → Explorer (DOM)**

Run this FIRST. Focus entirely on thorough exploration — do NOT generate test code here.

```
Read: skills/shared/explorer-instructions.md

## Target URL
{target_url}

## Additional instructions for Source Mode
In addition to the normal exploration steps, also collect:

1. SSR detection — run this after page load:
   () => {
     const el = document.getElementById('__NEXT_DATA__');
     const nuxtData = window.__NUXT__;
     return {
       hasNextData: !!el,
       nextDataKeys: el ? Object.keys(JSON.parse(el.textContent).props?.pageProps || {}) : [],
       hasNuxt: !!nuxtData,
       isNextJs: !!window.__NEXT_DATA__,
     };
   }

2. CSR API detection — after waiting 3 seconds post-load:
   () => { return window.__sorify_requests__ || 'not_tracked'; }

3. For every interactive element that triggers UI changes:
   Note what network requests were made (if observable).

Return ONLY a JSON string including the standard exploration format plus
`ssr_detection` and any observed `csr_endpoints`.
```

Store as `exploration_json`.

**Sub-agent A-2 → DOM Test Generator**

Receives `exploration_json` and generates DOM-based test code.

```
Read: skills/shared/generator-patterns.md

## Input
### Exploration result JSON
{exploration_json}

### Login snippet
```javascript
{LOGIN_SNIPPET}
```

Generate DOM-based test code from the exploration JSON.
Prefix every test name with "DOM: " (e.g. "DOM: c1 — Site logo — exists").
Return the tests as a JSON array — do NOT upload to Sorify.
```

Store as `dom_tests` (JSON array of `{name, playwright_code}` objects).

**Sub-agent B → Analyzer (Source Code)**

```
Read: skills/shared/analyzer-instructions.md

## Target URL
{target_url}

## Source
{source}
(GHE URL: use gh CLI to clone / Local path: read directly)

## DOM exploration results (from Explorer)
{exploration_json}

Thoroughly explore the source code to discover ALL condition axes.
Use the DOM exploration results to:
- Validate which selectors actually exist in the live page
- Identify which elements are SP-only (isHidden: true in exploration_json)
- Cross-check SSR vs CSR rendering mode
- Annotate each condition axis with a test strategy

Return ONLY a JSON string (conditions.json format with strategy annotations).
```

Store as `conditions_json`.

**Sub-agent C → Designer**

```
Read: skills/shared/designer-instructions.md

## conditions.json
{conditions_json}

## exploration_json (for selector validation)
{exploration_json}

Design test cases for each condition axis:
- strategy "network_mock" → design with page.route() mock
- strategy "dom_only" → design without mock (assert real DOM state)
- strategy "interaction" → design with click trigger + DOM observation
- strategy "skip" → omit entirely

Return ONLY a JSON string (test-cases.json format).
```

Store as `test_cases_json`.

**Sub-agent D → Condition Generator**

Generates condition-based test code only — does NOT upload or run.

```
Read: skills/shared/generator-patterns-mock.md

## Condition-based test cases
{test_cases_json}

## conditions.json (for selector reference)
{conditions_json}

## Login snippet
```javascript
{LOGIN_SNIPPET}
```

Generate condition-based test code.
Prefix every test name with "COND: ".
Return the tests as a JSON array — do NOT upload to Sorify.
```

Store as `cond_tests` (JSON array of `{name, playwright_code}` objects).

---

**Upload and run (parent agent, Source Mode):**

After both `dom_tests` and `cond_tests` are collected, combine them — DOM first (in
component order c1,c2...,i1-c1,...), then COND (in TC-01,TC-02... order) — add
`uploaded_by: "{UPLOADED_BY}"` to every entry, and call the `bulk_create_tests` and
`trigger_run` tools:

```
mcp__plugin_sorify_sorify__bulk_create_tests({
  suite_id: {suite_id},
  tests: dom_tests.concat(cond_tests)   // each entry: {name, playwright_code, uploaded_by}
})

mcp__plugin_sorify_sorify__trigger_run({ suite_id: {suite_id} })
```

The `trigger_run` result's `structuredContent.run_id` is the `run_id`.
Poll for completion and report results (see Step 7).

---

## Step 7: Poll for completion and output result summary

Call `get_run_status` repeatedly until the status is no longer `pending`/`running`:

```
mcp__plugin_sorify_sorify__get_run_status({ run_id: {run_id} })
```

Wait ~5 seconds between calls. The final result's `structuredContent` provides
`status`, `passed_count`, `failed_count`, `error_count`, `total_tests`, and
`duration_ms` for the summary below.

```
=== Test Results: {target_url} ===

Mode:   {DOM exploration / Source analysis (GHE) / Source analysis (Local)}
Sorify: {SORIFY_BASE_URL}

Phase                      Status
──────────────────────────────────────────────
① Explore (DOM)            ✅ Done
② Generate DOM tests       ✅ Done  ({dom_count} tests)
③ Analyze (Source)         ✅ Done  (source mode only)
④ Design condition tests   ✅ Done  ({cond_count} tests)  (source mode only)
⑤ Upload all tests         ✅ Done  ({total} tests in one batch)
⑥ Run                      ✅ Done

Test Results
──────────────────────────────────────────────
Total:              {total_tests}
  DOM-based:        {dom_count}   (prefix "DOM: ")
  Condition-based:  {cond_count}  (prefix "COND: ")
PASS:    {passed_count} ✅
FAIL:    {failed_count} ❌

View: {SORIFY_BASE_URL}/runs/{run_id}
```

---

## Notes

- Sub-agents run serially (each waits for the previous)
- Screenshot path: `test-output/sorify-generate/{id}.png`
- `page.route()` mocks do NOT affect SSR-rendered data — the analyzer determines this automatically
- **Suite variables:** any credential, URL, or environment-specific value used
  by a test MUST be defined as a suite `variable` and referenced in code as
  `variables.KEY` (never hardcoded). When adding tests to an existing suite,
  call `get_suite` first to reuse its existing variables and only `update_suite`
  to add missing ones — do not redefine keys that already exist.
- **Never publish `~/.sorify` values** (`SORIFY_USERNAME`/`SORIFY_PASSWORD`) as
  suite variables or in test code — those are MCP-connection auth only; suite
  variables hold target-webpage test-account credentials supplied by the user.

The user's input is: $ARGUMENTS
