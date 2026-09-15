---
name: sorify-generate
description: Generate Playwright E2E tests from a target URL, optionally using a source repository, then upload and run them in Sorify.
---

# Generate Sorify tests

Use this skill when the user asks to crawl or explore a service and create
Playwright tests in Sorify. The user may provide a target URL and optionally a
GitHub/GHE URL or local source path.

## Workflow

1. Extract the target service URL and optional source repository. If no target
   URL is present, ask for one.
2. Confirm the Sorify MCP server is connected. The MCP connection uses
   `SORIFY_URL` plus credentials from `~/.sorify`; never copy those connection
   credentials into Sorify suite variables.
3. Clear the exploration browser session, then read and follow
   `../shared/explorer-instructions.md`.
4. Create or reuse a Sorify suite. Store target-webpage credentials and
   environment-specific values as suite variables, and reference them as
   `variables.KEY` in generated code.
5. Generate DOM tests from the exploration result using
   `../shared/generator-patterns.md`. For source-repository mode, also follow
   the analyzer/designer instructions and the mock generator patterns in the
   same shared directory.
6. Upload all generated tests in one batch, including the actual model name in
   the `ai_model` field, trigger a run, poll its status, and report the result
   link and summary.

Use the connected Playwright MCP server for browser work and the connected
Sorify MCP server for `create_suite`, `get_suite`, `update_suite`,
`bulk_create_tests`, `list_tests`, `get_test`, `update_test_code`, `trigger_run`,
and `get_run_status`. Use `bulk_create_tests` only for new test cases. When the
user wants to revise an existing test case, follow the `sorify-update` skill so
`update_test_code` creates a code-history version instead of a duplicate test.
Tool prefixes vary by host; select the tools by server and tool name rather
than assuming Claude's `mcp__plugin_sorify_*` namespace.

The original Claude command remains available at
`../../commands/generate.md` as the detailed compatibility reference.
