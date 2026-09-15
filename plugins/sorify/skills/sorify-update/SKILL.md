---
name: sorify-update
description: Update an existing Sorify test case with new Playwright code while preserving its code-version history.
---

# Update an existing Sorify test

Use this skill when the user wants to revise, replace, or upload new code to an
existing Sorify test case. This is different from creating a new test with
`bulk_create_tests`.

## Workflow

1. Resolve the target suite and test. Use `list_suites`, `get_suite`,
   `list_tests`, or `get_test` as needed. If the name is ambiguous, ask the
   user to choose rather than updating the wrong test.
2. Call `get_test` before changing the code so the current test and its target
   are understood.
3. Generate or revise the Playwright code using the shared guidance in
   `../shared/generator-patterns.md` and, for source-repository mode, the
   analyzer, designer, and mock-generation references in that directory.
4. Replace the existing code with the Sorify MCP tool:

   ```text
   update_test_code({
     suite_id: <suite id>,
     test_id: <test id>,
     playwright_code: <new code>,
     ai_model: <the actual model name>
   })
   ```

5. Report the updated test and explain that Sorify archived the previous code
   as a restorable version. Do not use `bulk_create_tests` for this workflow;
   that creates new test cases instead of adding a version to the existing
   case.

The `update_test_code` tool validates the new code, reactivates the test, and
records the model attribution. Never copy the private MCP credentials from
`~/.sorify` into suite variables or test code.

If the user asks to restore a historical version, explain that the current
MCP server exposes version creation through `update_test_code`; historical
version restoration remains a dashboard operation unless a dedicated restore
tool is available from the connected server.
