# Mock Generator — Sub-agent D Specification

You receive test-cases.json and conditions.json, generate Playwright code,
upload to Sorify, run tests, and report results.

---

## Sorify execution model (required reading)

Sorify runs `playwright_code` via `eval()`. Forbidden APIs:

| Forbidden | Alternative |
|---|---|
| `page.click(s)` | `(await page.waitForSelector(s, {timeout:10000})).click()` |
| `page.locator()` | `page.waitForSelector()` / `page.$$()` |
| `page.$(s)` for required | `page.waitForSelector(s, {timeout:10000})` |
| `page.waitForFunction(fn)` | `page.waitForTimeout(N)` |
| `el.innerText()` | `el.textContent()` |
| `require(` / `import ` | forbidden keywords |
| `process.env` | forbidden keyword |
| `.Foo__abc123` exact hash class | `[class*="Foo"]` |

**`page.route()` IS available and is the core of network mock tests.**

**CRITICAL: CSS selector quoting — syntax errors cause ALL tests to fail silently.**

```javascript
// ❌ WRONG — single quotes inside single-quoted string → SyntaxError
await page.waitForSelector('a[href='https://example.com/']', ...);

// ✅ CORRECT — use double quotes inside selector attribute values
await page.waitForSelector('a[href="https://example.com/"]', ...);
await page.waitForSelector('button[type="submit"]', ...);

// ✅ BETTER — use partial match or relative path
await page.waitForSelector('a[href*="example.com"]', ...);
```

**Rule: Always use double quotes (`"`) for attribute values inside CSS selectors.**

**CRITICAL: `waitForSelector` defaults to `state: 'visible'` — this times out on `visibility:hidden` elements.**

Some UI components (custom dropdowns, animated panels) are hidden via `visibility:hidden` or
`opacity:0` rather than `display:none`. `waitForSelector` (default `state:'visible'`) will time out
on these elements even though they exist in the DOM.

```javascript
// ❌ WRONG — times out when element is visibility:hidden
const el = await page.waitForSelector('.dropdown-panel', { timeout: 10000 });

// ✅ CORRECT — use state:'attached' when checking hidden state
const el = await page.waitForSelector('.dropdown-panel', { state: 'attached', timeout: 10000 });
const isHidden = await el.evaluate(e => {
  const s = window.getComputedStyle(e);
  return s.visibility === 'hidden' || s.opacity === '0' || parseFloat(s.top) < -100;
});
```

**Rule: When asserting that an element is hidden (not visible), always use `state: 'attached'`
so the selector succeeds even when the element is visibility:hidden.**

Each test runs in an independent browser context. Every test starts from scratch.
Every test must include LOGIN_SNIPPET at the top.

---

## Strategy-based code generation

Read each test case's `strategy` field and generate accordingly.

---

### strategy: "dom_only" — No mock, assert real DOM

```javascript
{LOGIN_SNIPPET}
let _err = null;
try {
  // No page.route() — assert the real page state

  // VISIBLE assertions
  {visible_assertions}

  // HIDDEN assertions (if any)
  {hidden_assertions}
} catch (e) { _err = e; } finally {
  await page.screenshot({ path: 'test-output/sorify-generate/{id}.png' });
}
if (_err) throw _err;
```

---

### strategy: "network_mock" — page.route() intercepts CSR API

**CRITICAL: Set up route BEFORE page.goto()**

```javascript
// 1. Set up mock (BEFORE navigation)
await page.route('{url_pattern}', async route => {
  await route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({response_data})
  });
});

// 2. Navigate (mock is active from the first request)
{LOGIN_SNIPPET}  // contains page.goto() — mock is already set

// 3. Wait for page to settle
await page.waitForTimeout(3000);

// 4. Remove mock
await page.unroute('{url_pattern}');

let _err = null;
try {
  {visible_assertions}
  {hidden_assertions}
} catch (e) { _err = e; } finally {
  await page.screenshot({ path: 'test-output/sorify-generate/{id}.png' });
}
if (_err) throw _err;
```

**For abort (network error):**
```javascript
await page.route('{url_pattern}', route => route.abort());
{LOGIN_SNIPPET}
await page.waitForTimeout(5000); // app retries ~3x before showing error
await page.unroute('{url_pattern}');
```

**For delay (loading state):**
```javascript
// delay_ms (e.g. 4000) must be > wait_ms * 2 (wait_ms e.g. 1500)
await page.route('{url_pattern}', async route => {
  await new Promise(r => setTimeout(r, 4000));
  await route.continue();
});
{LOGIN_SNIPPET}
await page.waitForTimeout(1500); // catch loading state before data arrives
// Assert loading indicators HERE (before unroute)
await page.unroute('{url_pattern}');
await page.waitForTimeout(3000); // let data load
```

**For mock that only applies AFTER a user action (search, filter):**
```javascript
{LOGIN_SNIPPET}

// Set up mock BEFORE the action that triggers the API call
await page.route('{url_pattern}', async route => {
  await route.fulfill({ status: 200, contentType: 'application/json',
    body: JSON.stringify({response_data}) });
});

// Perform the user action
const trigger = await page.waitForSelector('{trigger_selector}', { timeout: 10000 });
await trigger.click();
await page.waitForTimeout(2000);

await page.unroute('{url_pattern}');
// Assert after action
```

---

### strategy: "interaction" — User action + DOM assertion

```javascript
{LOGIN_SNIPPET}
let _err = null;
try {
  // Perform the user action
  const trigger = await page.waitForSelector('{trigger_selector}', { timeout: 10000 });
  await trigger.click();
  await page.waitForTimeout(2000);

  {visible_assertions}
  {hidden_assertions}
} catch (e) { _err = e; } finally {
  await page.screenshot({ path: 'test-output/sorify-generate/{id}.png' });
}
if (_err) throw _err;
```

---

## Visible assertion patterns

**exists:**
```javascript
await page.waitForSelector('{selector}', { timeout: 10000 });
```

**text:**
```javascript
const el = await page.waitForSelector('{selector}', { timeout: 10000 });
const txt = await el.textContent();
if (!txt || !txt.includes('{expected}')) throw new Error('{name} text mismatch: ' + txt);
```

**count > 0:**
```javascript
await page.waitForSelector('{selector}', { timeout: 10000 });
const els = await page.$$('{selector}');
if (els.length === 0) throw new Error('{name} returned 0 elements');
```

**text search (when same selector matches multiple elements):**
```javascript
await page.waitForSelector('{selector}', { timeout: 10000 });
const allEls = await page.$$('{selector}');
let found = null;
for (const el of allEls) {
  const t = await el.textContent();
  if (t && t.includes('{expected}')) { found = el; break; }
}
if (!found) throw new Error('{name} not found with text "{expected}"');
```

---

## Hidden assertion patterns

**IMPORTANT: Never use `page.$$(selector).length === 0` alone for visibility checks.**
Elements can exist in the DOM but be `display: none`. Always check both.

**Element must be completely absent from DOM:**
```javascript
const hidden = await page.$$('{selector}');
if (hidden.length > 0) {
  const allHidden = await Promise.all(hidden.map(async el => {
    const display = await el.evaluate(e => window.getComputedStyle(e).display);
    return display === 'none';
  }));
  if (!allHidden.every(Boolean)) {
    throw new Error('{name} should be hidden but is visible ({count} elements found)');
  }
}
```

**Element must not be visible (may exist in DOM but display:none is OK):**
```javascript
const els = await page.$$('{selector}');
for (const el of els) {
  const display = await el.evaluate(e => window.getComputedStyle(e).display);
  const visibility = await el.evaluate(e => window.getComputedStyle(e).visibility);
  const offsetParent = await el.evaluate(e => e.offsetParent !== null);
  if (display !== 'none' && visibility !== 'hidden' && offsetParent) {
    throw new Error('{name} should not be visible in this state');
  }
}
```

---

## Disabled state assertion patterns

**`el.disabled` only works on `<button>`, `<input>`, `<select>`. Never use it on `<span>`, `<div>`, `<a>`.**
Web apps use multiple techniques to disable non-button elements. Always check all:

**Check if element is functionally disabled:**
```javascript
const el = await page.waitForSelector('{selector}', { timeout: 10000 });
const state = await el.evaluate(e => ({
  disabled: e.disabled,                                    // works for button/input
  attrDisabled: e.getAttribute('disabled') !== null,       // HTML attribute
  pointerEvents: window.getComputedStyle(e).pointerEvents, // none = disabled
  ariaDisabled: e.getAttribute('aria-disabled'),           // ARIA
  hasDisabledClass: e.classList.contains('disabled') || e.classList.contains('is-disabled'),
}));
const isDisabled = state.disabled || state.attrDisabled ||
  state.pointerEvents === 'none' || state.ariaDisabled === 'true' || state.hasDisabledClass;
if (!isDisabled) throw new Error('{name} should be disabled but appears enabled');
```

**Check if element is enabled (was previously disabled):**
```javascript
const el = await page.waitForSelector('{selector}', { timeout: 10000 });
const pointerEvents = await el.evaluate(e => window.getComputedStyle(e).pointerEvents);
const isDisabled = el.evaluate ? await el.evaluate(e =>
  e.disabled || e.getAttribute('disabled') !== null ||
  window.getComputedStyle(e).pointerEvents === 'none' ||
  e.getAttribute('aria-disabled') === 'true'
) : false;
if (isDisabled) throw new Error('{name} should be enabled but appears disabled');
```

---

## Two-step interaction patterns

Some UI components require opening before interacting:
- Datepicker: click input → calendar opens → click a date
- Custom dropdown: click trigger → option list appears → click an option
- Modal: click button → modal opens → interact with modal content

**Pattern: Open then Select**
```javascript
{LOGIN_SNIPPET}
let _err = null;
try {
  // Step 1: Open the component (datepicker / dropdown / modal)
  const trigger = await page.waitForSelector('{open_trigger_selector}', { timeout: 10000 });
  await trigger.click();
  await page.waitForTimeout(500);

  // Step 2: Interact with the revealed content
  const option = await page.waitForSelector('{revealed_option_selector}', { timeout: 5000 });
  await option.click();
  await page.waitForTimeout(1000);

  // Assert the resulting state
  {assertions}
} catch (e) { _err = e; } finally {
  await page.screenshot({ path: 'test-output/sorify-generate/{id}.png' });
}
if (_err) throw _err;
```

**Examples:**
```javascript
// Datepicker
const dateInput = await page.waitForSelector('#date', { timeout: 10000 });
await dateInput.click();
await page.waitForTimeout(500);
const day = await page.waitForSelector(
  '.react-datepicker__day:not(.react-datepicker__day--outside-month):not(.react-datepicker__day--disabled)',
  { timeout: 5000 }
);
await day.click();

// Custom dropdown
const dropTrigger = await page.waitForSelector('.input-category-select', { timeout: 10000 });
await dropTrigger.click();
await page.waitForTimeout(500);
const option = await page.waitForSelector('.rc-select-content dd:first-child', { timeout: 5000 });
await option.click();
```

---

## Step B-1: Self-review before upload — MANDATORY

Before uploading any test, review EVERY generated `playwright_code` string against
this checklist. Fix all issues found, then re-review until all checks pass.

**Do not upload until every test passes all checks below.**

---

### Checklist

#### ✅ CHECK-01: CSS selector quoting
Every attribute selector value must use DOUBLE quotes inside the JS string.

Scan for: `'[attr='` or `"[attr="` nested inside the outer quote — this is a SyntaxError.

```
FAIL: page.waitForSelector('a[href='https://example.com/']')
FAIL: page.waitForSelector("button[type="submit"]")
PASS: page.waitForSelector('a[href="https://example.com/"]')
PASS: page.waitForSelector("button[type='submit']")
PASS: page.waitForSelector('a[href*="example.com"]')   ← partial match preferred
```

**Auto-fix:** Replace `'[...'value'...]'` with `'[..."value"...]'` for all attribute selectors.

---

#### ✅ CHECK-02: Absolute URLs in href selectors
Never use absolute URLs in `href` attribute selectors.
Absolute URLs cause SP/desktop duplicate matches and break across environments.

```
FAIL: a[href="https://stg.example.com/help/kiyaku.html"]
PASS: a[href*="kiyaku"]              ← partial match
PASS: a[href="/help/kiyaku.html"]    ← relative path
PASS: .footer-pc a[href*="kiyaku"]   ← scoped to PC footer
```

**Auto-fix:** Replace absolute href selectors with partial match `[href*="path-fragment"]`.
If two elements share the same href (SP + PC), scope with a parent container.

---

#### ✅ CHECK-03: `waitForSelector` state for hidden-state checks
When the test is checking that an element IS hidden (not visible), use `state: 'attached'`.
The default `state: 'visible'` times out on `visibility:hidden` elements.

```
FAIL: await page.waitForSelector('.dropdown', { timeout: 10000 });  // times out if visibility:hidden
PASS: await page.waitForSelector('.dropdown', { state: 'attached', timeout: 10000 });
```

**Rule:** Any test that checks hidden/disabled state must use `state: 'attached'`.

---

#### ✅ CHECK-04: `disabled` property on non-button elements
`el.disabled` only works on `<button>`, `<input>`, `<select>`, `<textarea>`.
For `<span>`, `<div>`, `<a>`, `<p>` — always use `getComputedStyle`.

```
FAIL: const isDisabled = el.disabled;  // always undefined on <span>
PASS: const pointerEvents = await el.evaluate(e => window.getComputedStyle(e).pointerEvents);
      const isDisabled = pointerEvents === 'none';
```

**Auto-fix:** Replace `el.disabled` with `getComputedStyle` check when the element tag is not button/input/select.

---

#### ✅ CHECK-05: `offsetParent` check for `position:fixed` elements
Elements with `position:fixed` always have `offsetParent === null` even when visible.
Do NOT use `offsetParent !== null` as a visibility check for popups, modals, tooltips.

```
FAIL: e.offsetParent !== null   // always false for position:fixed overlays
PASS: s.display !== 'none' && s.visibility !== 'hidden'   // correct for fixed elements
```

**Auto-fix:** Remove `offsetParent` checks from tests involving popups, modals, alerts, tooltips.

---

#### ✅ CHECK-06: Datepicker and dropdown — two-step open-then-interact
Components like datepickers and custom dropdowns require clicking the trigger first.
`react-datepicker__day` cells are only in the DOM after the datepicker is opened.

```
FAIL: // Click day directly without opening
      await page.waitForSelector('.react-datepicker__day', { timeout: 10000 });

PASS: // Step 1: open the datepicker
      await (await page.waitForSelector('#date')).click();
      await page.waitForTimeout(500);
      // Step 2: click a day
      await (await page.waitForSelector('.react-datepicker__day:not(.react-datepicker__day--outside-month)')).click();
```

**Rule:** Before interacting with datepicker cells or custom dropdown options,
always include a click on the trigger element first.

---

#### ✅ CHECK-07: Selector specificity — avoid broad selectors matching unintended elements
Before finalizing a selector, verify it targets ONLY the intended element.
A broad selector like `ul li a` may match multiple sections on the page.

```
FAIL: section.rc-everyone .rc-everyone-right ul li:first-child a
      → matches theme navigation links, not the category dropdown options

PASS: #category.input-category-select   ← input trigger for event search category
PASS: .rc-search-form .rc-form-category dd:first-child  ← first option in category dropdown
```

**Rule:** If a selector could match elements from multiple sections, scope it
with the nearest unique parent container.

---

#### ✅ CHECK-08: Heading tag verification
Do not assume heading levels (`h2` vs `h3` vs `h4`). Verify from explorer JSON.

```
FAIL: .rc-first-sort h3   ← assumed h3, actual is h2
PASS: .rc-first-sort h2
```

**Rule:** If the exploration JSON does not specify the heading tag, use a generic
text search: `Array.from(document.querySelectorAll('.parent h1,h2,h3,h4')).find(h => h.textContent.includes('text'))`.

---

### How to apply this checklist

For each generated test:
1. Read the `playwright_code` string carefully.
2. Check each item above.
3. If any check fails → fix the code.
4. Re-read the fixed code and re-check all 8 items.
5. Only proceed to Step B-2 when ALL checks pass for ALL tests.

---

## Step B-2: Return generated tests — DO NOT upload

**Do NOT upload to Sorify from this sub-agent.**
The parent agent collects all tests (DOM + COND) and uploads them together in a single batch.
This prevents duplicate uploads when sessions are interrupted and resumed.

**Return the tests sorted by TC number** (TC-01, TC-02, TC-03...) so they appear in logical order in the Sorify UI.

```json
[
  {"name": "COND: TC-01 — has_themes — Theme list present", "playwright_code": "..."},
  {"name": "COND: TC-02 — api_error — Error message shown", "playwright_code": "..."},
  {"name": "COND: TC-03 — ...", "playwright_code": "..."}
]
```

The parent agent will:
1. Prepend DOM tests before COND tests
2. Bulk upload the combined array in one request
3. Trigger the run
- Triggering the run and polling for results
