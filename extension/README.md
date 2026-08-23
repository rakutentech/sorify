# Sorify Recorder (Chrome Extension)

Records clicks, inputs, and navigation so Sorify can generate Playwright tests
from a real browser session. See the root [README](../README.md) for how it
fits into the Sorify workflow.

## Load unpacked

`chrome://extensions/` → enable **Developer mode** → **Load unpacked** →
select this `extension/` folder.

## Cookie capture

The extension captures cookie snapshots during a recording session and
writes them to the JSONL alongside click/input/navigation events:

- **At recording start** — cookies for the active tab's current domain
  (the pre-auth baseline).
- **At recording stop** — cookies for all top-frame domains visited during
  the session (the final authenticated state).

Cookie snapshots use the Playwright `addCookies()` shape (`name`, `value`,
`domain`, `path`, `expires`, `httpOnly`, `secure`, `sameSite`) so they can
be uploaded directly to a Sorify suite via the `upload_suite_cookies` MCP
tool. This requires the `cookies` permission and `<all_urls>` host
permission (both declared in `manifest.json`).

The `/sorify:recording` command detects cookie snapshots in the JSONL and
offers to upload the `phase: "stop"` snapshot to the suite, so every test
run starts already authenticated.

## License and naming

This extension is licensed under the [BSD 3-Clause License](LICENSE), unlike
the rest of this repository which is under Apache 2.0. The BSD 3-Clause
license's non-endorsement clause explicitly prohibits publishing this
extension, or any derivative of it, under the name "Sorify Recorder" (or any
confusingly similar name) on the Chrome Web Store or any other marketplace
without prior written permission from the copyright holder.

If you fork this extension, you must rename it and its listing before
publishing it anywhere.
