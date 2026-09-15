---
name: sorify-setup
description: Configure Codex to authenticate with the Sorify MCP server after installing the Sorify plugin.
---

# Set up Sorify for Codex

Use this skill when Sorify MCP tools are unavailable or when the user asks to
configure Sorify authentication in Codex.

The user must have a `~/.sorify` file containing `SORIFY_URL`,
`SORIFY_USERNAME`, and `SORIFY_PASSWORD`. The setup script reads the URL from
that file (unless `SORIFY_URL` is already set) and the authentication helper
reads the credentials whenever Codex connects.

Run the bundled setup script from the installed plugin root:

```bash
"$PLUGIN_ROOT/scripts/setup-codex.sh"
```

If `PLUGIN_ROOT` is unavailable, use the absolute path to
`scripts/setup-codex.sh` in this plugin. The script reads `SORIFY_URL` from
the environment or `~/.sorify`, writes the current MCP URL and Codex
`http_headers_helper` entry without copying the Sorify password into Codex
configuration, and backs up an existing `~/.codex/config.toml` before changing
it.

After it completes, restart Codex and start a new session. Verify with:

```bash
codex mcp get sorify
```

The output must show a non-empty `http_headers_helper`.
