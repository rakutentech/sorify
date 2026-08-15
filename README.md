<h1 align="center"><img src="resources/images/sorify-icon.svg" width="192" align="center"/></h1>

<p align="center">
  Next generation AI First - Browser Testing platform <br><br>
  Low Tokens consumption<br>
  Built for AI Agents - Claude/Codex<br><br>
  Built for developers, QA or both<br>
  Control entire QA via MCP<br><br>
  <b>Bonus</b> Chrome Extension & MCP...<br> ...with Recorder, AI Test Writer
</p>

<p align="center">
  <img src="https://i.imgur.com/xxVw65d.png" alt="Sorify screenshot"/>
</p>

★ **Automate:** Automate browser testing, using claude, codex - with skills, mcp, test runners & dashboard.

★ **Chrome Extension:** Capture browser actions, send to AI to write tests, upload, self heal.

✴︎ **Test Management:** Organize test suites and test cases, run them on demand or on a schedule.

✴︎ **Test Runners:** Playwright-powered execution with full run history.

✴︎ **Notifications:** Get notified when suites finish or fail.

✴︎ **User Management:** Roles, permissions, and per-user IAM.

✴︎ **MCP Server:** Low token consumption over MCP layer, control all aspects of tests cases, code.

✴︎ **Agent Plugins:** Claude/Codex plugins for zero-code test generation and management.

## Feature Matrix

| Feature                                                                                  | MCP | Dashboard | CI Webhook |
| :--------------------------------------------------------------------------------------- | :-- | :-------- | :--------- |
| Suites: list / get / create / update / delete  / edit                                    | ✅  | ✅        | -          |
| **Tests**: list / get / create / update / update code / delete / bulk-delete             | ✅  | ✅        | -          |
| **Tests**: bulk-create                                                                   | ✅  | -         | -          |
| **Runs**: get / trigger / cancel / delete / logs                                         | ✅  | ✅        | -          |
| **Runs**: status                                                                         | ✅  | ✅        | ✅         |
| **Screenshots**: list / get                                                              | ✅  | ✅        | -          |
| **Recorder**: chrome://extension events                                                  | ✅  | -         | -          |
| Login / Register / Logout / Password reset                                               | -   | ✅        | -          |
| Profile: update name / password                                                          | -   | ✅        | -          |
| Dashboard analytics (stats, recent runs)                                                 | -   | ✅        | -          |
| Suite webhook token: regenerate                                                          | -   | ✅        | -          |
| Suite members: add/update/remove privileges                                              | -   | ✅        | -          |
| Test code-version: restore                                                               | -   | ✅        | -          |
| **Admin**: manage users (create, list, role, delete, reset password)                     | -   | ✅        | -          |

## AI Usage

```
claude /plugin marketplace add https://github.com/rakutentech/sorify.git
codex plugin marketplace add https://github.com/rakutentech/sorify.git
```

<p align="center">
  <img src="https://i.imgur.com/Yh2Fos3.png" width="650" alt="Sorify workflow"/>
</p>

### SKILL 1 - Sorify MCP


```sh
╰─$ cat ~/.sorify
SORIFY_URL=http://localhost:8000/sorify
SORIFY_USERNAME=admin@sorify.local
SORIFY_PASSWORD=changeme
```

```sh
/sorify:gateway Create a test suite called "Awesome App"
/sorify:gateway List latest failing tests

/sorify:gateway Create new test suite. Get requirements from JIRA/GHE/Excel. Write using /sorify:generate
/sorify:gateway Update the test suite http://localhost:8000/sorify/suites/{id} by adding retry twice
```

### SKILL 2 - Inbuilt QA Skills

```sh
# Tests from source code (git)
/sorify:generate Scan my code and generate tests for <github.com/your/repo>

# Tests from source code (path)
/sorify:generate Scan my code and generate tests for </path/to/your/source>

# Tests from url (remote)
/sorify:generate Crawl site and generate tests for https://rakuten.co.jp

# Tests from url (local)
/sorify:generate Crawl site and generate tests for http://localhost:3000/my-awesome-app

# Tests from requirements
/sorify:generate Read requirements here <link to docs> <path to docs> <raw paste>
<you got the idea...>
```

#### OR SKILL BYO - Bring your own QA Skills

```sh
/my:qa:skill Create detailed tests <...>
...then /sorify:gateway once done - upload, run and invetigate failed tests
```

### SKILL 3 - Record, Capture, Generate, upload (Chrome extension)

Generate tests from a real recorded session instead of DOM exploration — chrome-mcp-playwright `sorify-recorder-mcp`


**No 1.** Load the extension


`chrome://extensions/` → enable **"Developer mode"** → "Load unpacked" → select `extension/` folder

**No 2.** Install the mcp

```sh
curl -fsSL https://raw.githubusercontent.com/rakutentech/sorify/master/mcp/install-mcp.sh | sh
```

*we don't have npx, sorry*

**No 3.** Recorder start

Sorify recorder should start automatically, when you launch claude/codex

You can check using `/mcp` → `plugin:sorify:sorify-recorder`

If not, then you can using `~/.sorify-bin/sorify-recorder-mcp`.

**No 4.** Click the extension icon → Connect → Start recording

![Chrome extension recorder](https://i.imgur.com/59imHA1.png)

*Perform the clicks/inputs you want turned into a test, then Stop recording.*

**Tip:** to check the port `lsof -nP -iTCP:7420 -sTCP:LISTEN`

**No 5.** Generate tests

```sh
/sorify:recording latest
```

**AI Healing**

```sh
/sorify:gateway why my test is failing <name of test>
/sorify:gateway why my test is failing <or-link-to-test>
```

# Quick Start

## Development (local)

**Option 1** - Simple

```sh
# Update: APP_KEY, DB_PASSWORD, MYSQL_ROOT_PASSWORD
cp .env.example .env

composer setup
php artisan dev
```

**Option 2** - Detailed

```sh
npm run dev
php artisan serve
php artisan queue:work --queue=sorify,default
php artisan schedule:work
```

## Self Hosting

```bash
cp .env.docker.example .env.docker
# Update: APP_KEY, DB_PASSWORD, MYSQL_ROOT_PASSWORD
# Change: APP_ENV=prod for prod ready
docker compose --env-file .env.docker up
```

## Verify

```sh
http://localhost:8000/sorify
```

- **User:** admin@sorify.local
- **Password:** changeme

## Notes for users

- Fully self hosted, no telemetry
- What your AI does in yolo mode is your responsibility
- Files and folders on your system, that this system creates/requires
  - `~/.sorify`: your credentials
  - `~/.sorify-bin/`: chrome extension mcp
  - `~/.sorify-recordings/`: chrome extension mcp's events recordings

---

## Owners

<table>
  <tr>
    <td align="center">
      <a href="https://github.com/MarikoKikugawa">
        <img src="https://github.com/MarikoKikugawa.png" width="80" height="80" alt="MarikoKikugawa"/><br />
        <sub><b>MarikoKikugawa</b></sub>
      </a>
    </td>
    <td align="center">
      <a href="https://github.com/asghar-ahmed">
        <img src="https://github.com/asghar-ahmed.png" width="80" height="80" alt="asghar-ahmed"/><br />
        <sub><b>asghar-ahmed</b></sub>
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

## Contribution

- Raise an issue or PR to `master`

## License

Licensed under the [Apache License, Version 2.0](LICENSE).

The Chrome extension in [`extension/`](extension/) is licensed separately
under the [BSD 3-Clause License](extension/LICENSE), which prohibits
publishing it (or a derivative) under the "Sorify Recorder" name without
permission. See [`extension/README.md`](extension/README.md) for details.

# CHANGE LOG

- v1 - Intial Release
- v1.1 - Batteries added on user management, mcp enhancement
- v1.2 - Chrome Extension to record and write playwright tests
