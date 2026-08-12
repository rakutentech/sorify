# Analyzer — Sub-agent B Specification (Source Mode)

You receive DOM exploration results (from Sub-agent A Explorer) AND the source repository.
Your job is to discover ALL condition axes from source code, validate them against the live DOM,
and annotate each axis with a `strategy` that determines how to test it.
Return ONLY a JSON string (conditions.json format). No explanation text.

---

## Critical: Read the DOM exploration results first

Before reading any source code, read `exploration_json` carefully:
- `ssr_detection` — tells you if this is SSR (Next.js, Nuxt, etc.)
- `isHidden` flags on elements — tells you what is SP/mobile-only
- Validated selectors from the live page

This prevents generating test cases for elements that don't exist or are hidden at desktop viewport.

---

## Step 1: Determine rendering mode

Use `exploration_json.ssr_detection` to determine rendering mode:

```
hasNextData: true  → Next.js SSR page
  → page.route() will NOT intercept data already embedded in __NEXT_DATA__
  → Network mocks are limited to CSR-fetched data only

hasNuxt: true      → Nuxt SSR
  → Same limitation as Next.js SSR

Neither           → Likely CSR (React, Vue SPA)
  → page.route() will intercept API calls normally
```

**If SSR is detected:**
Check if there are ALSO client-side fetches by looking at:
1. `useEffect` / `onMounted` hooks that call APIs
2. Infinite scroll / lazy loading that fetches more data
3. User-triggered actions (search, filter) that call APIs after page load

These CSR calls CAN be mocked even on SSR pages.

---

## Step 2: Locate and thoroughly read source code

### If GHE URL
```bash
gh repo clone {ghe-url} /tmp/analyzer-source -- --depth=1 2>/dev/null || \
  gh api repos/{owner}/{repo}/git/trees/HEAD?recursive=1 --jq '.tree[].path' | head -50
```

### If local path
Read directly from the provided path.

### What to read (in order of priority)

1. **Entry point** — Find the file that renders the target URL path
   - Next.js: `pages/{path}/index.tsx` or `app/{path}/page.tsx`
   - Nuxt: `pages/{path}/index.vue`
   - SPA: trace from router config

2. **All imported components** — Recursively read imports (depth ≤ 4)

3. **API calls** — Find all `fetch`, `axios`, `useQuery`, `useSWR` calls
   - Record exact URL patterns
   - Note if called in SSR context (`getServerSideProps`, `getStaticProps`) or client context (`useEffect`, event handlers)

4. **Custom hooks** — Read all `use*.ts` files referenced by the page

5. **Type definitions / API interfaces** — Understand response shapes

**Be thorough.** Read all files. Do not skim. Missing a condition axis means a missing test.

---

## Step 3: Discover condition axes from 5 sources

### Source 1: UI conditional expressions

In every component file, find:
- `&&` operator: `isLoggedIn && <UserMenu />`
- Ternary: `data.length === 0 ? <Empty /> : <List />`
- `if`/`switch` in render
- Dynamic className/style based on state

For each: record what is visible/hidden in each branch.

### Source 2: Business logic utility functions

Find functions like:
- `isAfterDays(x, N)` → boundary value N
- `isValidUrl(x)` → valid / invalid / null
- `isEmpty(x)` → empty / non-empty

Each reveals boundary values that need separate test cases.

### Source 3: API field → UI trace

For each API response field:
1. Is it passed to a component?
2. Does that component use it in a conditional render?
3. Is the conditional visible to the user?

Exclude fields that are used only for logic (IDs, keys) with no visual impact.

### Source 4: Network / API states (always required)

For every API call, record:
- Loading state (skeleton, spinner)
- Error state (error message)
- Empty state (no results)
- Success state (data shown)

**Identify whether each is SSR or CSR:**
- `getServerSideProps` / `getStaticProps` / Nuxt `asyncData` → SSR (mock not effective)
- `useEffect` / `onMounted` / event handlers → CSR (mock effective)

### Source 5: Auth / localStorage / URL params

- Auth: does login state change visible UI?
- localStorage: any keys read that affect display?
- URL params: do query params change what is shown?

---

## Step 4: Validate against DOM exploration results

For every condition axis and every selector:

1. **Check isHidden in exploration_json**
   - If `isHidden: true` → set `strategy: "skip"` (SP/mobile only, not testable at desktop viewport)

2. **Check selector exists in live DOM**
   - If the selector from source code doesn't appear in `exploration_json.components` → flag it as `"needs_verification"`

3. **Check rendering mode**
   - If the API call is SSR → set `strategy: "dom_only"` (cannot mock, test actual rendered state only)
   - If the API call is CSR → set `strategy: "network_mock"`
   - If condition is triggered by user interaction → set `strategy: "interaction"`

---

## Step 5: Assign strategy to each condition axis value

```
strategy: "network_mock"
  → The API is called client-side after page load.
  → page.route() can intercept it.
  → Test by mocking the API response.

strategy: "dom_only"
  → The data is rendered server-side (SSR) and embedded in HTML.
  → page.route() CANNOT change what's already in the HTML.
  → Test by asserting the real DOM state (no mock needed).
  → Note: the real STG data determines what's visible.

strategy: "interaction"
  → A user action (click, type, scroll) triggers a UI change.
  → Test by performing the action and asserting the result.
  → May or may not need a network mock.

strategy: "skip"
  → Element is SP/mobile-only (isHidden: true at desktop viewport).
  → Element cannot be tested in Sorify's desktop viewport.
  → Do not generate test cases for these.
```

---

## Step 6: Build parts registry

For every UI part in visible/hidden lists:
- Find the selector from source code
- Cross-check that it exists in `exploration_json`
- Use the most stable selector (id > href > role > data-testid > partial class)
- Note if it's hidden at desktop (from `isHidden` in exploration)

**Disambiguation rule — when similar elements co-exist on the same page:**

Pages often have multiple sections with visually similar elements (e.g. multiple "search" forms,
multiple "category" selectors, multiple lists of links). A broad selector like `ul li a` will
match ALL of them. Always scope to the correct container.

```
Bad:  ul li:first-child a                   → matches theme links AND category links
Good: .rc-search-form .rc-form-category dd  → specific to the event search category dropdown
Good: .rc-everyone-right ul li a            → specific to the theme section

Always verify with exploration_json that the selector matches ONLY the intended element.
If count > 1 and text differs, narrow the selector with a parent container.
```

**For interactive components that require opening before content appears (datepicker, custom dropdown):**
Document both the trigger AND the revealed selector in the parts registry:

```json
{
  "name": "Event search category option",
  "open_trigger": "#category.input-category-select",
  "playwright_code": "page.locator('.rc-select-content dd:first-child')",
  "source_file": "components/EventSearch.tsx:42",
  "desktop_visible": true,
  "interaction_type": "open_then_select",
  "note": "Must click #category input first to reveal the option list"
}
```

**For disabled/enabled state checks, record HOW the element is disabled:**

```json
{
  "name": "Clear button (disabled state)",
  "playwright_code": "page.locator('.rc-search-button-wrap .clear-btn1')",
  "disabled_via": "pointer-events:none",
  "note": "This is a <span>, not <button> — use getComputedStyle().pointerEvents, not el.disabled"
}
```

```json
{
  "name": "Theme list item",
  "playwright_code": "page.locator('[class*=\"ThemeItem_item\"]')",
  "source_file": "components/ThemeList.tsx:28",
  "desktop_visible": true,
  "note": "CSS Modules hash — use partial class match"
}
```

---

## Step 7: Return conditions.json

```json
{
  "target_url": "https://stg.example.com/",
  "analyzed_at": "YYYY-MM-DD",
  "source": "https://ghe.example.com/org/repo",
  "rendering_mode": "SSR+CSR",
  "ssr_framework": "Next.js",
  "ssr_data_keys": ["themes", "categories"],
  "csr_api_endpoints": [
    {
      "url_pattern": "**/api/search**",
      "trigger": "user_search_submit",
      "can_be_mocked": true
    }
  ],
  "parts_registry": [
    {
      "name": "Theme list item",
      "playwright_code": "page.locator('[class*=\"ThemeItem_item\"]')",
      "source_file": "components/ThemeList.tsx:28",
      "desktop_visible": true,
      "note": ""
    },
    {
      "name": "SP category modal",
      "playwright_code": "page.locator('.rc-sp-category-modal')",
      "source_file": "components/SPCategoryModal.tsx:12",
      "desktop_visible": false,
      "note": "SP-only — display:none at desktop viewport"
    }
  ],
  "condition_axes": [
    {
      "id": "CA-01",
      "axis": "Theme list data",
      "category": "api_data",
      "rendering": "SSR",
      "strategy": "dom_only",
      "strategy_reason": "Theme data is embedded in __NEXT_DATA__ — page.route() cannot affect it",
      "discovery_source": "pages/index.tsx:getServerSideProps → fetchThemes()",
      "values": [
        {
          "value": "has_themes",
          "setup": "no_mock",
          "visible": ["Theme list item"],
          "hidden": ["Empty state message"],
          "note": "Asserts real STG data — assumes themes exist in STG"
        }
      ]
    },
    {
      "id": "CA-02",
      "axis": "Search results",
      "category": "api_data",
      "rendering": "CSR",
      "strategy": "network_mock",
      "strategy_reason": "Search API is called client-side on form submit",
      "discovery_source": "hooks/useSearch.ts:submitSearch() → fetch('/api/search')",
      "api_endpoint": "**/api/search**",
      "values": [
        {
          "value": "no_results",
          "setup": "mock_empty",
          "mock": { "type": "fulfill", "response": { "results": [] } },
          "visible": ["Empty results message"],
          "hidden": ["Search result item"]
        },
        {
          "value": "has_results",
          "setup": "mock_data",
          "mock": { "type": "fulfill", "response": { "results": [{"title": "Test Event"}] } },
          "visible": ["Search result item"],
          "hidden": ["Empty results message"]
        }
      ]
    },
    {
      "id": "CA-03",
      "axis": "SP category modal",
      "category": "interaction",
      "rendering": "client",
      "strategy": "skip",
      "strategy_reason": "SP-only element — display:none at desktop viewport (isHidden: true in DOM exploration)",
      "values": []
    }
  ],
  "excluded": [
    {
      "name": "pageId (API param)",
      "reason": "Used in routing only — no visible UI change",
      "verified": true
    }
  ]
}
```

---

## Rules

- **Never guess selectors** — verify from both source code AND exploration_json
- **Assign strategy to every condition axis** — never leave it blank
- **Skip SP/mobile-only** — if isHidden: true in exploration_json → strategy: "skip"
- **SSR data cannot be mocked** — always use "dom_only" for getServerSideProps data
- **Be exhaustive** — read all component files; missing a condition = missing coverage
