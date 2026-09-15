# sorify

A Claude Code and Codex plugin that automatically generates Playwright E2E test cases from a URL, uploads them to Sorify, and runs them.

## Installation

```shell
claude /plugin
# → Install → sorify
```

Requires `~/.sorify` with your Sorify URL and credentials:

```
SORIFY_URL=https://your-sorify-host/sorify
SORIFY_USERNAME=you@example.com
SORIFY_PASSWORD=your-password
```

The plugin talks to Sorify over MCP (`.mcp.json` in this plugin registers the
`sorify` MCP server). `SORIFY_URL` needs to be a real shell environment variable
so the MCP client can build the server URL from it — add this to your shell
profile (`~/.zshrc`, `~/.bashrc`, etc.):

```shell
set -a; source ~/.sorify; set +a
```

`SORIFY_USERNAME`/`SORIFY_PASSWORD` don't need to be exported — a helper script
reads them straight from `~/.sorify` to build the request's Basic-Auth header.

## Codex

Codex uses the parallel compatibility manifest in `.codex-plugin/` and the
skills under `skills/`. The Claude manifest explicitly exposes only
`skills/shared/`, so the Codex-specific setup skill is not loaded by Claude.
The existing Claude commands remain unchanged. Add this repository as a Codex
plugin marketplace, install `sorify`, and start a new Codex session so the
skills and MCP servers load:

```shell
codex plugin marketplace add https://github.com/rakutentech/sorify.git
codex plugin add sorify@sorify
```

Codex CLI currently requires the HTTP header helper to be registered in its
user MCP configuration. After installing, invoke the `$sorify:sorify-setup` skill.
For a local checkout, run the bundled setup script directly:

```bash
/absolute/path/to/sorify/plugins/sorify/scripts/setup-codex.sh
```

The setup requires `~/.sorify`, discovers `SORIFY_URL` from that file or the
environment, writes the current MCP server URL, creates a backup of the
existing Codex config, and adds the helper without copying your password into
Codex configuration. If you configure it manually, use the actual Sorify URL
(Codex does not expand `${SORIFY_URL}` in this TOML value):

```toml
[mcp_servers.sorify]
url = "https://your-sorify-host/sorify/mcp"
http_headers_helper = "/absolute/path/to/sorify/plugins/sorify/scripts/mcp-auth-headers.sh"
```

Use the actual absolute path to the installed plugin's helper when configuring
manually. Verify it with `codex mcp get sorify`; it should show a non-empty
`http_headers_helper` value. Do not run `codex mcp login` for this setup;
Sorify uses Basic Auth through the helper, not OAuth.

The files under `skills/shared/` are shared instruction references used by the
Codex skills and the existing Claude workflows. The `shared` skill entry
exists only to make those references discoverable; the user-facing workflows
are `sorify-generate`, `sorify-gateway`, `sorify-recording`, and
`sorify-update`. Use `sorify-update` when changing an existing test case so
Sorify creates a code-history version instead of a duplicate test.

## Usage

```
/sorify:generate {target-url}
```

`{target-url}` is the URL of the web service or page you want to test.

Optionally pass a source repository to also generate condition-based tests:

```
/sorify:generate {target-url} {repo-url-or-local-path}
```

Natural language is also accepted:

```
/sorify:generate Please generate tests for this service. URL: https://stg.example.com/ Repo: https://github.com/your-org/your-repo
```

## How it works

1. Clears the browser session
2. Creates a TestSuite on Sorify
3. **Sub-agent A (Explorer)** — navigates the service URL, inspects the DOM, validates selectors, and returns a structured JSON of all UI components
4. **Sub-agent B (Generator)** — generates Playwright test code from the JSON
5. Uploads all tests to Sorify in a single batch, triggers a run, and polls for results
6. Reports pass/fail summary with a link to the Sorify run

When a source repository is provided, additional agents analyze the source code to generate condition-based tests (network mocks, auth state, data variations) alongside the DOM tests.
