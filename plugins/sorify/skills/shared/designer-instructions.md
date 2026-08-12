# Designer — Sub-agent C Specification

You receive conditions.json and exploration_json, and design test cases.
You do NOT read source code. Your only inputs are these two JSON files.
Return ONLY a JSON string (test-cases.json format). No explanation text.

---

## Core principle: 1 condition value = 1 test case

```
Each test case:
  1. Sets up the condition (login, mock, interaction)
  2. Loads the page
  3. Asserts ALL parts in visible[] are present and visible
  4. Asserts ALL parts in hidden[] are absent or not visible

→ One test covers the full page state for one condition value.
```

---

## Strategy-based test design

**You MUST check the strategy of each condition axis before designing tests.**

### strategy: "network_mock"
- Use `page.route()` to intercept the API and return mock data
- Set up mock BEFORE `page.goto()`
- Design separate tests for each value (empty, has_data, error, loading)

### strategy: "dom_only"
- DO NOT use `page.route()` — mocks won't affect SSR-rendered data
- Navigate to the page and assert the real current DOM state
- Note: the test depends on real STG data — document this in the test description
- Design: one test asserting "the real page loads and key elements are present"

### strategy: "interaction"
- The user performs an action (click, type, select)
- Test by performing the action and asserting the resulting DOM state
- May combine with network_mock if the action triggers a CSR API call

### strategy: "skip"
- DO NOT design any test cases for this axis
- It is SP/mobile-only and not testable at desktop viewport

---

## Combining rules

**Keep separate:**
- Each condition value → always one separate test case
- Network states (error, loading, empty, success) → always separate

**Combine into one (meaningful combinations):**
- `logged_in + has_data` → baseline "works normally" test
- `logged_in + field_test_X` → field tests always assume logged in + has data

**Never combine:**
- Independent field axes (imagePresent × titleLong → keep separate)
- Boundary values (each is its own test)

---

## open_trigger — MANDATORY for components requiring two-step interaction

Some UI components are only accessible after opening a parent trigger first
(datepicker, custom dropdown, accordion, modal).

**When a part in `parts_registry` has an `open_trigger` field, the test MUST:**
1. Click `open_trigger` first
2. Wait for the component to open (`page.waitForTimeout(500)`)
3. Then interact with the revealed content

**This applies to:**
- datepicker day cells and navigation buttons → trigger: `#date` or similar input
- custom dropdown options → trigger: the dropdown input/button
- modal content → trigger: the button that opens the modal

If `open_trigger` is present in `parts_registry`, copy it verbatim into the test case
as `open_trigger_selector`. Generator will use it to open the component first.

**If `open_trigger` is NOT in `parts_registry` but the selector starts with
`.react-datepicker` → always assume the trigger is the adjacent date input.**

---

## Selector validation

Before using a selector in a test case:
1. Look it up in `parts_registry` — use the `playwright_code` field verbatim
2. Check `desktop_visible` — if `false`, the part cannot be asserted with `waitForSelector`
3. Check `open_trigger` — if present, include it in the test case

**For hidden assertions:**
- If the element is expected to be absent: use `page.$$()` + count === 0
- If the element exists but should not be VISIBLE: use computed style check
  ```javascript
  const el = await page.$('selector');
  if (el) {
    const display = await el.evaluate(e => window.getComputedStyle(e).display);
    if (display !== 'none') throw new Error('element should be hidden');
  }
  ```

---

## Return test-cases.json

```json
{
  "target_url": "https://stg.example.com/",
  "generated_at": "YYYY-MM-DD",
  "rendering_mode": "SSR+CSR",
  "summary": {
    "total": 6,
    "network_mock": 3,
    "dom_only": 1,
    "interaction": 2,
    "skipped": 1
  },
  "test_cases": [
    {
      "id": "TC-01",
      "strategy": "dom_only",
      "axis_id": "CA-01",
      "axis_value": "real_page_load",
      "description": "Page loads with SSR data — theme list and category dropdown present",
      "setup": "no_login",
      "mock": null,
      "assert_visible": ["Theme list item", "Category dropdown button"],
      "assert_hidden": [],
      "note": "SSR data — asserts real STG state. Assumes themes exist in STG environment."
    },
    {
      "id": "TC-02",
      "strategy": "network_mock",
      "axis_id": "CA-02",
      "axis_value": "search_no_results",
      "description": "Search returns no results — empty state message shown",
      "setup": "no_login",
      "mock": {
        "url_pattern": "**/api/search**",
        "trigger": "user_action",
        "type": "fulfill",
        "response": { "results": [] },
        "intercept_timing": "before_action"
      },
      "user_action": {
        "type": "click",
        "selector_name": "Search submit button",
        "wait_ms": 2000
      },
      "assert_visible": ["Empty results message"],
      "assert_hidden": ["Search result item"],
      "note": "Search API is CSR — mock intercepts after user submits"
    },
    {
      "id": "TC-03",
      "strategy": "interaction",
      "axis_id": "CA-03",
      "axis_value": "date_selected",
      "description": "User selects a date — date input reflects selection",
      "setup": "no_login",
      "mock": null,
      "user_action": {
        "type": "click",
        "selector_name": "Date picker",
        "wait_ms": 1000
      },
      "assert_visible": ["Date clear button"],
      "assert_hidden": [],
      "note": ""
    }
  ]
}
```

---

## Constraints

- Every part name in `assert_visible`/`assert_hidden` MUST exist in `parts_registry`
- If a part is missing → add to `note` field, do NOT invent a selector
- `strategy: "skip"` → no test case at all
- `strategy: "dom_only"` → no `mock` field, assert real page state
