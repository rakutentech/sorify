<h1 align="center"><img src="resources/images/sorify-icon.svg" width="192" align="center"/></h1>

<p align="center">
  Next generation QA testing platform <br>
  Zero-code test management, built for developers, QA or both<br>
  Built for AI Agents - Claude/Codex<br>
  QA Platform over MCP<br>
</p>

**Test Management:** Organize test suites and test cases, run them on demand or on a schedule.

**Test Runners:** Playwright-powered execution with full run history.

**Notifications:** Get notified when suites finish or fail.

**User Management:** Roles, permissions, and per-user IAM.

**MCP Server:** Talk to your test suites from Claude, Codex, or any MCP client.

**Agent Plugins:** Claude/Codex plugins for zero-code test generation and management.

**Versioning:** Every test edit is snapshotted, so you can review and roll back changes.

## Feature Matrix

| Feature | MCP | Dashboard | CI Webhook |
|---|---|---|---|
| Suites: list / get / create / update / delete | ✅ | ✅ | - |
| Suite Teams-notification settings | ✅ | ✅ | - |
| Suite schedule (cron): create/update/delete | ✅ | ✅ | - |
| Tests: list / get / create / update / update code / toggle status / delete / bulk-delete | ✅ | ✅ | - |
| Tests: bulk-create (up to 100) | ✅ | - | - |
| Runs: trigger | ✅ | ✅ | ✅ |
| Runs: get | ✅ | ✅ | - |
| Runs: get status | ✅ | ✅ | ✅ |
| Runs: cancel | ✅ | ✅ | - |
| Runs: delete | ✅ | ✅ | - |
| Screenshots: list / get | ✅ | ✅ | - |
| Login / Register / Logout / Password reset | - | ✅ | - |
| Profile: update name / password | - | ✅ | - |
| Dashboard analytics (stats, recent runs) | - | ✅ | - |
| Suite webhook token: regenerate | - | ✅ | - |
| Suite members: add/update/remove privileges | - | ✅ | - |
| Test code-version: restore | - | ✅ | - |
| Admin: manage users (create, list, role, delete, reset password) | - | ✅ | - |

## AI Usage

```
claude /plugin marketplace add https://github.com/rakutentech/sorify.git
```

### STEP 1 - Setup Sorify


```
╰─$ cat ~/.sorify
SORIFY_URL=http://localhost:8000/sorify
SORIFY_USERNAME=admin@sorify.local
SORIFY_PASSWORD=changeme


/sorify:gateway create a test suite called "Awesome App"

/sorify:gateway Check my access to existing test suite called "Awesome App"
/sorify:gateway Check my access to existing test suite http://localhost:8000/sorify/suites/{id}
```

### STEP 2 - Generate and Push

```
# Tests from source code (git)
/sorify:generate Scan my code and generate tests for <github.com/your/repo> <further prompt..>

# Tests from source code (path)
/sorify:generate Scan my code and generate tests for </path/to/your/source> <further prompt..>

# Tests from url (remote)
/sorify:generate Crawl site and generate tests for https://rakuten.co.jp <further prompt..>

# Tests from url (local)
/sorify:generate Crawl site and generate tests for http://localhost:3000/awesome-app <further prompt..>

# Tests from requirements
/sorify:generate Read requirements here <link to docs> <path to docs> <raw paste>
<you got the idea...>
```

## Development (local)

```bash
docker-compose up
```

or

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed #admin@sorify.local/changeme
php artisan dev

#or yourself
npm run dev
php artisan serve
php artisan queue:work --queue=sorify,default
```

## Verify

```
http://localhost:8000/sorify
```

## Running Tests

```bash
php artisan test
```

## Contributors

<table>
  <tr>
    <td align="center">
      <a href="https://github.com/asghar-ahmed">
        <img src="https://github.com/asghar-ahmed.png" width="80" height="80" alt="asghar-ahmed"/><br />
        <sub><b>asghar-ahmed</b></sub>
      </a>
    </td>
    <td align="center">
      <a href="https://github.com/kevincobain2000">
        <img src="https://github.com/kevincobain2000.png" width="80" height="80" alt="kevincobain2000"/><br />
        <sub><b>kevincobain2000</b></sub>
      </a>
    </td>
    <td align="center">
      <a href="https://github.com/pulkit-kathuria">
        <img src="https://github.com/pulkit-kathuria.png" width="80" height="80" alt="pulkit-kathuria"/><br />
        <sub><b>pulkit-kathuria</b></sub>
      </a>
    </td>
    <td align="center">
      <a href="https://github.com/HiromichiHagiwara">
        <img src="https://github.com/HiromichiHagiwara.png" width="80" height="80" alt="HiromichiHagiwara"/><br />
        <sub><b>HiromichiHagiwara</b></sub>
      </a>
    </td>
  </tr>
  <tr>
    <td align="center">
      <a href="https://github.com/MarikoKikugawa">
        <img src="https://github.com/MarikoKikugawa.png" width="80" height="80" alt="MarikoKikugawa"/><br />
        <sub><b>MarikoKikugawa</b></sub>
      </a>
    </td>
    <td align="center">
      <a href="https://github.com/chetra-tep">
        <img src="https://github.com/chetra-tep.png" width="80" height="80" alt="chetra-tep"/><br />
        <sub><b>chetra-tep</b></sub>
      </a>
    </td>
    <td align="center">
      <a href="https://github.com/chetratep">
        <img src="https://github.com/chetratep.png" width="80" height="80" alt="chetratep"/><br />
        <sub><b>chetratep</b></sub>
      </a>
    </td>
  </tr>
</table>

## License

Licensed under the [Apache License, Version 2.0](LICENSE).

# CHANGE LOG

- v1 - Intial Release
