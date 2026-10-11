'use strict';

/**
 * browser-check.cjs — Reports which Playwright browser drivers are installed.
 *
 * Called by Laravel's BrowserDriverService via:
 *   node browser-check.cjs
 *
 * For each supported browser engine (chromium, firefox, webkit) it resolves
 * the executable path Playwright would launch and checks whether that file
 * actually exists on this host. Writes a single JSON object to stdout:
 *
 *   {
 *     "chromium": { "installed": true,  "executable_path": "/…/chrome" },
 *     "firefox":  { "installed": false, "executable_path": "/…/firefoxbinary" },
 *     "webkit":   { "installed": true,  "executable_path": "/…/WebKit" }
 *   }
 *
 * `executable_path` is Playwright's best guess even when the driver is not
 * installed (where the binary would be), so the dashboard can hint at it.
 * Exits 0 as long as the report itself could be produced — "not installed"
 * is a valid result, not an error.
 */

const fs = require('fs');

let chromium, firefox, webkit;
try {
    ({ chromium, firefox, webkit } = require('playwright'));
} catch (e) {
    console.error('Unable to load the playwright package: ' + e.message);
    process.exit(1);
}

// The test runner (harness.cjs) falls back to system Chrome when
// Playwright's bundled Chromium binary is unavailable — mirror that here
// so "installed" means exactly what a run means: a browser would launch.
const SYSTEM_CHROME = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';

const engines = { chromium, firefox, webkit };
const report = {};

for (const [name, engine] of Object.entries(engines)) {
    let installed = false;
    let executablePath = null;
    try {
        executablePath = engine.executablePath();
        installed = typeof executablePath === 'string' && executablePath !== '' && fs.existsSync(executablePath);
    } catch (e) {
        // executablePath() throws when the driver metadata is unavailable —
        // treat as not installed rather than failing the whole report.
        installed = false;
    }

    // Chromium-only fallback: a system Chrome install also satisfies a run
    // (the runner points Playwright at it when the bundled driver is
    // missing, e.g. after a Playwright version bump without `playwright
    // install`).
    if (!installed && name === 'chromium' && fs.existsSync(SYSTEM_CHROME)) {
        installed = true;
        executablePath = SYSTEM_CHROME;
    }

    report[name] = { installed, executable_path: executablePath };
}

console.log(JSON.stringify(report));
