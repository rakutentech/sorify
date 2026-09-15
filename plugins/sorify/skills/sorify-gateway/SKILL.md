---
name: sorify-gateway
description: Explain Sorify MCP capabilities and perform read-only lookups of suites, tests, runs, and screenshots.
---

# Sorify gateway

Use this skill when the user asks what Sorify can do or requests live,
read-only information such as suites, tests, runs, run status, or screenshots.

- Use the connected Sorify MCP server for live data.
- Prefer the matching read-only tool and return its actual result.
- Do not mutate suites, tests, or runs unless the user explicitly asks for a
  separate change.
- For the complete tool reference and parameter details, read
  `../../commands/gateway.md`.

The Claude `/sorify:gateway` command remains unchanged and is still supported.
