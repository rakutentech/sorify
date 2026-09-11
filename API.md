## HTTP API Reference

All dashboard endpoints live under the `/sorify` prefix. Except the CI webhooks, they are session-based (Laravel session cookie) and intended for the dashboard UI — for programmatic use prefer the MCP server or CI webhooks.

### Sample curl requests

Login and keep the session cookie (non-GET requests also require the Laravel `X-CSRF-TOKEN` header):

```sh
curl -X POST https://your-host/sorify/login \
  -c cookies.txt \
  -d "email=admin@sorify.local&password=changeme"
```

Create a suite (session auth):

```sh
curl -X POST https://your-host/sorify/suites \
  -H "Cookie: sorify_session=<your-session-cookie>" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "My Suite",
    "base_url": "https://example.com",
    "browser": "chromium",
    "headless": true,
    "variables": [{"key": "USER", "value": "alice"}]
  }'
```

Trigger a run via CI webhook (token auth, no session needed):

```sh
curl -X POST https://your-host/sorify/webhooks/{suite-webhook-token}/trigger \
  -H "Content-Type: application/json" \
  -d '{"test_ids": [12, 34]}'
```

Poll run status via CI webhook:

```sh
curl https://your-host/sorify/webhooks/{suite-webhook-token}/runs/{run}/status
```

For programmatic access, prefer the MCP endpoint `POST /sorify/mcp` — see the [AI Usage](README.md#ai-usage) section in the README.

### Public

| Method | Path | Description |
| :--- | :--- | :--- |
| GET | `/sorify/login` | login view |
| GET | `/sorify/register` | register view |
| GET | `/sorify/forgot-password` | forgot password view |
| GET | `/sorify/reset-password/{token}` | reset password view |
| POST | `/sorify/login` | login |
| POST | `/sorify/register` | register |
| POST | `/sorify/forgot-password` | send reset link |
| POST | `/sorify/reset-password` | reset password |
| GET | `/sorify/auth/github/redirect` | GitHub OAuth redirect |
| GET | `/sorify/auth/github/callback` | GitHub OAuth callback |
| GET | `/sorify/.well-known/mcp.json` | MCP discovery file |
| GET | `/sorify/build/{path}` | built Vite assets |

### CI Webhooks (token guarded)

| Method | Path | Description |
| :--- | :--- | :--- |
| POST | `/sorify/webhooks/{token}/trigger` | trigger a suite run |
| GET | `/sorify/webhooks/{token}/runs/{run}/status` | run status |

### Authenticated — Suites

| Method | Path | Description |
| :--- | :--- | :--- |
| GET | `/sorify/suites` | list suites |
| POST | `/sorify/suites` | create suite |
| GET | `/sorify/suites/{suite}` | suite details |
| GET | `/sorify/suites/{suite}/review` | suite review |
| PUT | `/sorify/suites/{suite}` | update suite |
| DELETE | `/sorify/suites/{suite}` | delete suite |
| POST | `/sorify/suites/{suite}/duplicate` | duplicate suite |
| POST | `/sorify/suites/{suite}/runs` | trigger a run |
| POST | `/sorify/suites/{suite}/webhook/regenerate` | regenerate webhook token |
| DELETE | `/sorify/suites/{suite}/webhook/{token}` | delete webhook |
| PUT | `/sorify/suites/{suite}/schedule` | update cron schedule |
| DELETE | `/sorify/suites/{suite}/schedule` | delete cron schedule |
| POST | `/sorify/suites/{suite}/integrations` | add integration |
| PUT | `/sorify/suites/{suite}/integrations/{integration}` | update integration |
| DELETE | `/sorify/suites/{suite}/integrations/{integration}` | delete integration |
| POST | `/sorify/suites/{suite}/users` | add suite member |
| PUT | `/sorify/suites/{suite}/users/{user}` | update member privileges |
| DELETE | `/sorify/suites/{suite}/users/{user}` | remove member |
| POST | `/sorify/suites/{suite}/bookmark` | bookmark suite |
| DELETE | `/sorify/suites/{suite}/bookmark` | remove bookmark |

### Authenticated — Tests

| Method | Path | Description |
| :--- | :--- | :--- |
| GET | `/sorify/suites/{suite}/tests/all` | all tests |
| GET | `/sorify/suites/{suite}/tests/{test}` | test details |
| POST | `/sorify/suites/{suite}/tests` | create test |
| PUT | `/sorify/suites/{suite}/tests/{test}` | update test |
| DELETE | `/sorify/suites/{suite}/tests/{test}` | delete test |
| PUT | `/sorify/suites/{suite}/tests/{test}/code` | update test code |
| POST | `/sorify/suites/{suite}/tests/{test}/code-versions/{codeVersion}/restore` | restore code version |
| PATCH | `/sorify/suites/{suite}/tests/{test}/toggle-status` | enable / disable |
| POST | `/sorify/suites/{suite}/tests/{test}/duplicate` | duplicate test |
| DELETE | `/sorify/suites/{suite}/tests/bulk` | bulk delete |
| PATCH | `/sorify/suites/{suite}/tests/bulk/status` | bulk update status |
| POST | `/sorify/suites/{suite}/tests/bulk/duplicate` | bulk duplicate |

### Authenticated — Runs, Results, Feed, Profile

| Method | Path | Description |
| :--- | :--- | :--- |
| GET | `/sorify/runs/{run}` | run details |
| GET | `/sorify/runs/{run}/status` | run status (poll) |
| POST | `/sorify/runs/{run}/cancel` | cancel run |
| DELETE | `/sorify/runs/{run}` | delete run |
| GET | `/sorify/results/{result}/screenshots` | screenshots for a result |
| GET | `/sorify/screenshots/{screenshot}` | screenshot file |
| GET | `/sorify/feed` | activity feed |
| GET | `/sorify/feed/poll` | activity feed (poll) |
| GET | `/sorify/bookmarks` | bookmarked suites |
| GET | `/sorify/profile` | profile |
| PUT | `/sorify/profile` | update name |
| PUT | `/sorify/profile/password` | update password |
| POST | `/sorify/profile/avatar` | upload avatar |
| DELETE | `/sorify/profile/avatar` | remove avatar |
| PATCH | `/sorify/profile/locale` | update locale |
| POST | `/sorify/logout` | logout |

### Admin only

| Method | Path | Description |
| :--- | :--- | :--- |
| GET | `/sorify/admin/users` | list users |
| POST | `/sorify/admin/users` | create user |
| PUT | `/sorify/admin/users/{user}` | update user |
| DELETE | `/sorify/admin/users/{user}` | delete user |
| POST | `/sorify/admin/users/{user}/reset-password` | reset password |
| GET | `/sorify/admin/github-apps` | list GitHub Apps |
| POST | `/sorify/admin/github-apps` | create GitHub App |
| PUT | `/sorify/admin/github-apps/{githubApp}` | update GitHub App |
| DELETE | `/sorify/admin/github-apps/{githubApp}` | delete GitHub App |
| POST | `/sorify/admin/github-apps/test-connection` | test app connection |
| GET | `/sorify/admin/system` | system info |
| GET | `/sorify/admin/system/readiness` | readiness check |
| PUT | `/sorify/admin/system/mode` | update system mode |
| POST | `/sorify/admin/system/build-image` | build ephemeral image |
