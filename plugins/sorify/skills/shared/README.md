# Shared Sorify skill references

These files contain reusable exploration, analysis, design, and Playwright
generation instructions. Claude command workflows and Codex `SKILL.md`
workflows can read them.

Keep host-specific invocation details in the surrounding workflow. In
particular, do not assume Claude's `mcp__plugin_sorify_*` tool namespace when
using these references from Codex; select the equivalent tools exposed by the
connected MCP server.
