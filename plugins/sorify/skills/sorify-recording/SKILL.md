---
name: sorify-recording
description: Turn a Sorify Recorder browser session into a Playwright test and upload it to Sorify.
---

# Generate from a recording

Use this skill when the user asks to generate a test from a Sorify Recorder
session.

1. Confirm the `sorify-recorder` MCP server is connected and resolve the
   requested recording, using the newest recording for `latest`.
2. If the recorder binary is unavailable, explain that it must be installed
   at `~/.sorify-bin/sorify-recorder-mcp` and that the browser extension must
   be loaded.
3. Read the recording metadata and present a numbered interpretation of the
   actions. Do not create anything in Sorify until the user confirms.
4. After confirmation, generate the replay test, upload relevant stop-phase
   cookies when requested, create the suite/test, trigger the run, and report
   the result.

For the full event-field reference, cookie handling, confirmation gate, and
test-generation rules, read `../../commands/recording.md`.
