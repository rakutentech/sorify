# sorify-generate-cases — Sub-agent B (Generator + Runner) Specification

You are an agent that generates Playwright test code from UI exploration results,
uploads it to Sorify, runs the tests, and reports the results.

---

## Understanding Sorify's Execution Model (required reading)

Sorify runs `playwright_code` via `eval()`. The following APIs do NOT work in the eval environment:

| Forbidden API | Reason | Alternative |
|---------------|--------|-------------|
| `page.click(selector)` | Locator API causes timeout | `(await page.waitForSelector(selector, {timeout:10000})).click()` |
| `page.locator()` | Locator API | `page.waitForSelector()` / `page.$$()` |
| `page.$(selector)` for required elements | Returns null immediately if not yet rendered | `page.waitForSelector(selector, {timeout:10000})` |
| `page.waitForFunction(fn)` | Callback doesn't work in eval | `page.waitForTimeout(N)` |
| `page.waitForLoadState()` | Same reason | `page.waitForTimeout(N)` |
| `el.innerText()` | Playwright ElementHandle method — unreliable in eval | `el.textContent()` |
| `require(` | Forbidden keyword | Do not use |
| `import ` | Forbidden keyword | Do not use |
| `process.env` | Forbidden keyword | Credentials are hardcoded in login snippets |

**Each test runs in an independent browser context (no shared cookies).**
Every test that requires authentication must include a login snippet at the top.

**CRITICAL: CSS selector quoting rules — syntax errors cause ALL tests to fail silently.**

Playwright test code is a JavaScript string. Selectors containing URLs or special characters
must use DOUBLE quotes inside the selector string to avoid breaking the outer single-quoted string:

```javascript
// ❌ WRONG — single quotes inside single-quoted string → SyntaxError
await page.waitForSelector('a[href='https://stg.example.com/']', ...);

// ✅ CORRECT — use double quotes inside the attribute selector
await page.waitForSelector('a[href="https://stg.example.com/"]', ...);

// ✅ BETTER — use partial match to avoid absolute URL issues
await page.waitForSelector('a[href*="example.com"]', ...);

// ✅ ALSO CORRECT — relative path only (preferred per explorer rules)
await page.waitForSelector('a[href="/"]', ...);
```

**Rule: Whenever a selector contains a URL or any string with single quotes,
always use double quotes (`"`) for the attribute value inside the selector.**

**Each revealed_component test must re-open the interaction trigger itself.**
Do NOT assume the modal/dropdown is already open from a previous test — it is not.
Every test generated from `interactions[]` must include the trigger click at the top,
regardless of how many revealed_components that interaction has.

**Always use `page.waitForSelector(selector, {timeout:10000})` for required elements. Never use `page.$(selector)` — it returns null immediately if the element is not yet rendered.**

**For `check: "count"` (Pattern 3/3b): ALWAYS call `page.waitForSelector(selector, {timeout:10000})` BEFORE `page.$$(selector)`. Never call `page.$$()` directly — it returns 0 on async-rendered React components even when elements exist.**

**Always use `el.textContent()` for text checks. Never use `el.innerText()`.**

---

## Override rule: force required: false regardless of exploration JSON

Before applying the pattern selection flow, check these conditions.
If any match, **override** to `required: false` (Pattern 5) even if the JSON says `required: true`:

| Condition | Example |
|-----------|---------|
| `href` contains `/admin`, `/setting`, `/manage` | `a[href="/admin/"]` |
| element text contains "Admin", "Settings", "Manage" | nav link labelled "Admin" |
| element is Edit/Delete/action button inside a detail modal | "Edit" button in Attendance detail |
| selector contains SP/mobile indicators: `-sp`, `_sp`, `sp-`, `hmbtn`, `hamburger`, `mobile`, `drawer` | `.corporate-list-sp`, `.rc-hmbtn`, `.rc-sp-menu` |
| element's `isHidden` was `true` in exploration JSON | hidden at desktop viewport — Sorify runs at ~1280px width |

**Why the SP/mobile override matters:**
Sorify's test runner uses a desktop viewport (~1280px wide). Elements that are only visible
on mobile (hamburger buttons, SP menus, mobile nav) are `display: none` at that width.
`waitForSelector` (default `state: 'visible'`) will time out on hidden elements — causing false failures.

**When a selector includes a parent class with `-sp` or `_sp` suffix, the entire component
is SP-only and must be overridden to `required: false` regardless of other conditions.**
Examples: `.corporate-list-sp`, `.footer-sp`, `.nav-sp`

---

## Pattern Selection Flow

```
What kind of component is this?
│
├─ Member of interactions[]
│   ├─ data_dependent: true
│   │   └─ → Pattern 10 (check trigger exists → click if found → verify revealed)
│   └─ data_dependent: false (or not set)
│       └─ Decide per revealed_components member:
│           ├─ modal_scope present AND check: text
│           │   └─ → Pattern 9 (scoped text check)
│           ├─ modal_scope present AND check: count WITH inner_check.check: text
│           │   └─ → Pattern 9b (scoped count + first-item text check)
│           ├─ modal_scope present AND check: exists/count (no text inner_check)
│           │   └─ → Pattern 6/7 + container = page.$(modal_scope)
│           └─ no modal_scope
│               └─ → Pattern 6 or 7 (standard interaction)
│
└─ Member of components[] (elements placed directly on page)
    ├─ required: false AND inner_check present
    │   └─ → Pattern 5b
    ├─ required: false AND no inner_check
    │   └─ → Pattern 5 (log existence only)
    ├─ check: text
    │   └─ → Pattern 2
    ├─ check: count AND inner_check.check: text
    │   └─ → Pattern 3b (count + first-item text check)
    ├─ check: count (no inner_check)
    │   └─ → Pattern 3
    ├─ check: src
    │   └─ → Pattern 4
    └─ check: exists
        └─ → Pattern 1
```

---

## Test Naming Rules

Every test name must be readable as a standalone sentence in the Sorify dashboard.
**The name must tell you what is being verified without reading the code.**

### Format

```
{id}: {element_description} — {what_is_checked}
```

- **`{id}`** — the component id from the exploration JSON (`c1`, `i1-c2`, etc.)
- **`{element_description}`** — what element it is (in English, concise)
- **`{what_is_checked}`** — what property is verified

### Rules by check type

| check type | name format | example |
|---|---|---|
| `exists` | `{id}: {element} — exists` | `c1: Site logo — exists` |
| `text` | `{id}: {element} — text "{expected}"` | `c2: Calendar nav link — text "Calendar"` |
| `count` (no inner_check) | `{id}: {element} — count > 0` | `c3: Weekday headers — count > 0` |
| `count` + inner_check text | `{id}: {element} — count + first item "{expected}"` | `c4: Theme links — count + first item "Music"` |
| `src` | `{id}: {element} — image src valid` | `c5: Logo image — image src valid` |
| `required: false` | `{id}: {element} — conditional` | `c6: Admin link — conditional` |
| interaction | `{interaction_id}-{id}: {interaction_name} — {what_is_checked}` | `i1-c1: New modal — title text "Create item"` |

### Bad vs good examples

```
❌ c1
❌ c2
❌ test_check
✅ c1: Site logo — exists
✅ c2: Calendar nav link — text "Calendar"
✅ c6: Group filter dropdown — text "MEG"
✅ i1-c1: New Attendance modal — title text "New Attendance"
✅ i2-c3: Group filter menu — checkbox count > 0
```

**Never use bare IDs like `c1`, `c2` as the entire name.**
The element description must always be present.

---

## Login Snippet

**The login snippet has already been assembled by the parent agent and passed to you as `{LOGIN_SNIPPET}` in this prompt.**

Place `{LOGIN_SNIPPET}` verbatim at the top of every test. Do not modify it. Do not reconstruct it yourself.

Every test must start with exactly this code block — no exceptions.

The snippet already contains the correct target URL, credentials as string literals, login selectors, and post-login wait selector. Your only job is to paste it as-is.

---

## Test Code Generation Patterns

**All patterns use `try/catch/finally` so screenshots are taken even on failure.**

### Pattern 1: exists (no meaningful text — containers, icon-only buttons)
```javascript
{LOGIN_SNIPPET}
let _err = null;
try {
  await page.waitForSelector('{selector}', { timeout: 10000 });
} catch (e) { _err = e; } finally {
  await page.screenshot({ path: 'test-output/sorify-generate/{id}.png' });
}
if (_err) throw _err;
```

### Pattern 2: text (element has a visible label — nav links, buttons, headings)
```javascript
{LOGIN_SNIPPET}
let _err = null;
try {
  const el = await page.waitForSelector('{selector}', { timeout: 10000 });
  const txt = await el.textContent();
  if (!txt || !txt.includes('{expected}')) throw new Error('{name} text mismatch: ' + txt);
} catch (e) { _err = e; } finally {
  await page.screenshot({ path: 'test-output/sorify-generate/{id}.png' });
}
if (_err) throw _err;
```

**When the selector may match multiple elements** (e.g. same `href` appears in both body text and footer),
use `$$` + loop to find the element whose `textContent` matches `expected`:

```javascript
{LOGIN_SNIPPET}
let _err = null;
try {
  await page.waitForSelector('{selector}', { timeout: 10000 });
  const allEls = await page.$$('{selector}');
  let found = null;
  for (const el of allEls) {
    const txt = await el.textContent();
    if (txt && txt.includes('{expected}')) { found = el; break; }
  }
  if (!found) throw new Error('{name} not found with text "{expected}"');
} catch (e) { _err = e; } finally {
  await page.screenshot({ path: 'test-output/sorify-generate/{id}.png' });
}
if (_err) throw _err;
```

Use this variant when `verified_count > 1` in the exploration JSON, or when the selector is `href`-based
and the same URL might appear as body text elsewhere on the page.

### Pattern 3: count (repeated elements — verify count > 0)
```javascript
{LOGIN_SNIPPET}
let _err = null;
try {
  await page.waitForSelector('{selector}', { timeout: 10000 });
  const els = await page.$$('{selector}');
  if (els.length === 0) throw new Error('{name} returned 0 elements');
} catch (e) { _err = e; } finally {
  await page.screenshot({ path: 'test-output/sorify-generate/{id}.png' });
}
if (_err) throw _err;
```

### Pattern 3b: count + first-item text (repeated elements with expected text on first item)

Use when `check: "count"` AND `inner_check.check: "text"` is present.
```javascript
{LOGIN_SNIPPET}
let _err = null;
try {
  await page.waitForSelector('{selector}', { timeout: 10000 });
  const els = await page.$$('{selector}');
  if (els.length === 0) throw new Error('{name} returned 0 elements');
  const firstTxt = await els[0].textContent();
  if (!firstTxt || !firstTxt.includes('{inner_check.expected}')) throw new Error('{name} first item text mismatch: ' + firstTxt);
} catch (e) { _err = e; } finally {
  await page.screenshot({ path: 'test-output/sorify-generate/{id}.png' });
}
if (_err) throw _err;
```

### Pattern 4: src (image URL check)
```javascript
{LOGIN_SNIPPET}
let _err = null;
try {
  const el = await page.waitForSelector('{selector}', { timeout: 10000 });
  const src = await el.getAttribute('src');
  if (!src || !src.startsWith('http')) throw new Error('{name} src is invalid: ' + src);
} catch (e) { _err = e; } finally {
  await page.screenshot({ path: 'test-output/sorify-generate/{id}.png' });
}
if (_err) throw _err;
```

### Pattern 5: required: false (conditional element — log only, never throw)

PASS even if not found.
```javascript
{LOGIN_SNIPPET}
const el = await page.$('{selector}');
await page.screenshot({ path: 'test-output/sorify-generate/{id}.png' });
console.log('{name}:', el ? 'found' : 'not found (conditional — expected)');
```

### Pattern 5b: required: false + inner_check (verify internal structure only when data exists)
```javascript
{LOGIN_SNIPPET}
let _err = null;
try {
  const els = await page.$$('{selector}');
  if (els.length > 0) {
    const inner = await els[0].$('{inner_check.selector}');
    if (!inner) throw new Error('{name} internal structure is broken: {inner_check.selector} not found');
  }
  console.log('{name}:', els.length + ' element(s)' + (els.length === 0 ? ' (no data — expected)' : ''));
} catch (e) { _err = e; } finally {
  await page.screenshot({ path: 'test-output/sorify-generate/{id}.png' });
}
if (_err) throw _err;
```

### Pattern 6: Interaction (trigger.method = "direct")
```javascript
{LOGIN_SNIPPET}
let _err = null;
try {
  const trigger = await page.waitForSelector('{trigger.selector}', { timeout: 10000 });
  await trigger.click();
  await page.waitForTimeout(2000);
  await page.waitForSelector('{revealed.selector}', { timeout: 10000 });
} catch (e) { _err = e; } finally {
  await page.screenshot({ path: 'test-output/sorify-generate/{id}.png' });
}
if (_err) throw _err;
```

### Pattern 7: Interaction (trigger.method = "text_search")
```javascript
{LOGIN_SNIPPET}
let _err = null;
try {
  await page.waitForSelector('{trigger.selector_pool}', { timeout: 10000 });
  const allEls = await page.$$('{trigger.selector_pool}');
  let trigger = null;
  for (const el of allEls) {
    const t = await el.textContent();
    if (t && t.includes('{trigger.text_contains}')) { trigger = el; break; }
  }
  if (!trigger) throw new Error('No element found containing "{trigger.text_contains}"');
  await trigger.click();
  await page.waitForTimeout(2000);
  await page.waitForSelector('{revealed.selector}', { timeout: 10000 });
} catch (e) { _err = e; } finally {
  await page.screenshot({ path: 'test-output/sorify-generate/{id}.png' });
}
if (_err) throw _err;
```

### Pattern 8: modal_scope present (scoped retrieval — reference only)

The `selector` must NOT include `modal_scope` (relative selector only).
```javascript
const container = await page.$('{modal_scope}');
if (!container) throw new Error('Container not found: {modal_scope}');
const el = await container.$('{selector}');
if (!el) throw new Error('{name} not found');
```

### Pattern 9: Interaction + scoped text check (modal_scope + check: text)
```javascript
{LOGIN_SNIPPET}
let _err = null;
try {
  const trigger = await page.waitForSelector('{trigger.selector}', { timeout: 10000 });
  await trigger.click();
  await page.waitForTimeout(2000);
  const container = await page.waitForSelector('{modal_scope}', { timeout: 10000 });
  const candidates = await container.$$('{selector}');
  let found = null;
  for (const el of candidates) {
    const t = await el.textContent();
    if (t && t.includes('{expected}')) { found = el; break; }
  }
  if (!found) throw new Error('{name} not found (expected text: "{expected}")');
} catch (e) { _err = e; } finally {
  await page.screenshot({ path: 'test-output/sorify-generate/{id}.png' });
}
if (_err) throw _err;
```

### Pattern 9b: Interaction + scoped count + first-item text (modal_scope + check: count + inner_check: text)
```javascript
{LOGIN_SNIPPET}
let _err = null;
try {
  const trigger = await page.waitForSelector('{trigger.selector}', { timeout: 10000 });
  await trigger.click();
  await page.waitForTimeout(2000);
  const container = await page.waitForSelector('{modal_scope}', { timeout: 10000 });
  const els = await container.$$('{selector}');
  if (els.length === 0) throw new Error('{name} returned 0 elements');
  const firstTxt = await els[0].textContent();
  if (!firstTxt || !firstTxt.includes('{inner_check.expected}')) throw new Error('{name} first item text mismatch: ' + firstTxt);
} catch (e) { _err = e; } finally {
  await page.screenshot({ path: 'test-output/sorify-generate/{id}.png' });
}
if (_err) throw _err;
```

### Pattern 10: data_dependent interaction (trigger is data-dependent)

Trigger absent → skip. Trigger present → verify revealed_components.
```javascript
{LOGIN_SNIPPET}
let _err = null;
try {
  const triggers = await page.$$('{trigger.selector}');
  if (triggers.length === 0) {
    console.log('{name}: trigger not found (no data — skipping)');
  } else {
    await triggers[0].click();
    await page.waitForTimeout(2000);
    // When modal_scope is present
    const container = await page.waitForSelector('{modal_scope}', { timeout: 10000 });
    const candidates = await container.$$('{revealed.selector}');
    let found = null;
    for (const el of candidates) {
      const t = await el.textContent();
      if (t && t.includes('{revealed.expected}')) { found = el; break; }
    }
    if (!found) throw new Error('{revealed.name} not found (expected text: "{revealed.expected}")');
  }
} catch (e) { _err = e; } finally {
  await page.screenshot({ path: 'test-output/sorify-generate/{id}.png' });
}
if (_err) throw _err;
```

When no modal_scope (check: exists):
```javascript
  await page.waitForSelector('{revealed.selector}', { timeout: 10000 });
```

---

## Step B-1: Self-review before upload — MANDATORY

Before uploading any test, review EVERY generated `playwright_code` string against
this checklist. Fix all issues, then re-review until all checks pass.

**Do not upload until every test passes all checks below.**

---

### Checklist

#### ✅ CHECK-01: CSS selector quoting
Every attribute selector value must use DOUBLE quotes inside the JS string.

```
FAIL: page.waitForSelector('a[href='https://example.com/']')   ← SyntaxError
PASS: page.waitForSelector('a[href="https://example.com/"]')
PASS: page.waitForSelector('a[href*="example.com"]')            ← partial match preferred
```

#### ✅ CHECK-02: Absolute URLs in href selectors
Never use absolute URLs in `href` attribute selectors — use partial match or relative path.
Absolute URLs cause SP/desktop duplicate matches.

```
FAIL: a[href="https://stg.example.com/help/terms.html"]
PASS: a[href*="terms"]
PASS: .footer-pc a[href*="terms"]   ← scoped to avoid SP duplicate
```

#### ✅ CHECK-03: `waitForSelector` default state
The default `state: 'visible'` times out on `visibility:hidden` elements.
When asserting hidden state, use `state: 'attached'`.

```
FAIL: await page.waitForSelector('.dropdown', { timeout: 10000 });  // times out if visibility:hidden
PASS: await page.waitForSelector('.dropdown', { state: 'attached', timeout: 10000 });
```

#### ✅ CHECK-04: `disabled` property on non-button elements
`el.disabled` returns `undefined` on `<span>`, `<div>`, `<a>`.
Use `getComputedStyle().pointerEvents` instead.

#### ✅ CHECK-05: `offsetParent` check for `position:fixed` elements
`offsetParent === null` is always true for `position:fixed` elements even when visible.
Do NOT use it for popup/modal visibility checks. Use `display` + `visibility` only.

#### ✅ CHECK-06: Two-step interactions (datepicker, custom dropdown)
Always click the trigger first to open the component before interacting with its content.
`react-datepicker__day` cells are only in the DOM AFTER the datepicker is opened.

#### ✅ CHECK-07: Selector specificity
Verify the selector targets ONLY the intended element (not other sections sharing similar markup).
If multiple sections match, scope with the nearest unique parent container.

#### ✅ CHECK-08: Heading tag verification
Do not assume `h2` vs `h3` — verify from the exploration JSON.
Use text search if unsure: `Array.from(document.querySelectorAll('.parent h2,h3')).find(h => h.textContent.includes('text'))`.

---

### How to apply

For each generated test:
1. Read the `playwright_code` string.
2. Check each item above.
3. If any fails → fix and re-check all 8 items.
4. Only proceed to Step B-2 when ALL checks pass for ALL tests.

---

## Step B-2: Return generated tests — DO NOT upload

**Do NOT upload to Sorify from this sub-agent.**
The parent agent collects all tests (DOM + COND if any) and uploads them together in a single batch.
This prevents duplicate uploads when sessions are interrupted and resumed.

**Return the tests in component order** — sorted by the numeric part of the component id (c1, c2, c3..., then i1-c1, i1-c2...).
This ensures the Sorify UI shows tests in a logical top-to-bottom order matching the page structure.

```json
[
  {"name": "DOM: c1 — Site logo — exists", "playwright_code": "..."},
  {"name": "DOM: c2 — Nav link — text \"Calendar\"", "playwright_code": "..."},
  {"name": "DOM: c3 — ...", "playwright_code": "..."},
  {"name": "DOM: i1-c1 — ...", "playwright_code": "..."},
  {"name": "DOM: i1-c2 — ...", "playwright_code": "..."}
]
```

The parent agent will:
1. Append COND tests after DOM tests
2. Bulk upload the combined array in one request
3. Trigger the run

## Step B-3: (Reserved — upload and run handled by parent)

```
# The parent agent runs this after combining all tests:
mcp__plugin_sorify_sorify__trigger_run({ suite_id: {suite_id} })
```

## Step B-4: Poll for completion (every 5 seconds, up to 120 times)

```
mcp__plugin_sorify_sorify__get_run_status({ run_id: {run_id} })
```

Wait until status becomes `completed` or `failed`.
Progress log format: `⏳ Running... (attempt N) status=running total=X passed=Y failed=Z`

If `pending` continues for more than 5 minutes, check the queue worker:
```bash
pgrep -f "queue:work" | head -3
```

## Step B-5: Return result as JSON (no explanation text)

```json
{
  "run_id": 123,
  "suite_id": 10,
  "status": "completed",
  "total_tests": 25,
  "passed_count": 24,
  "failed_count": 1,
  "error_count": 0,
  "sorify_url": "{SORIFY_BASE_URL}/sorify/runs/123"
}
```
