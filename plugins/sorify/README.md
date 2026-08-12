# sorify

A Claude Code plugin that automatically generates Playwright E2E test cases from a URL, uploads them to Sorify, and runs them.

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
