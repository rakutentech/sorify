'use strict';

/**
 * runner.js — Main entry point for the Sorify QA Playwright test runner.
 *
 * Called by Laravel's PlaywrightRunnerService via:
 *   node runner.js --spec <path> --output <dir> [--timeout <ms>] [--base-url <url>] [--proxy <url>]
 *     [--proxy-rules <path-to-json-file>] [--variables <path-to-json-file>] [--cookies <path-to-json-file>]
 *     [--browser chromium|firefox|webkit] [--headless true|false] [--take-screenshot true|false]
 *
 * --proxy-rules points to a JSON file containing an array of {domain, proxy} objects,
 * where `domain` is a regular expression tested against each request's hostname.
 * The first matching rule's proxy is used; everything else uses --proxy as the
 * default (or connects directly).
 *
 * --variables points to a JSON file containing an object of {key: value} pairs
 * that are injected into the test code's scope as a `variables` object.
 *
 * --cookies points to a JSON file containing an array of Playwright cookie
 * objects ({name, value, domain|url, path?, expires?, httpOnly?, secure?,
 * sameSite?}) that are added to the browser context before any page is created,
 * so the test starts already authenticated.
 *
 * Writes a JSON result object to stdout and exits with:
 *   0  → status === 'passed'
 *   1  → status === 'failed' | 'error'
 */

const fs = require('fs');
const path = require('path');

// ---------------------------------------------------------------------------
// Minimal CLI argument parser (no dependencies)
// ---------------------------------------------------------------------------
function parseArgs(argv) {
  const args = {};
  for (let i = 0; i < argv.length; i++) {
    const flag = argv[i];
    if (flag === '--spec' && argv[i + 1]) {
      args.spec = argv[++i];
    } else if (flag === '--output' && argv[i + 1]) {
      args.output = argv[++i];
    } else if (flag === '--timeout' && argv[i + 1]) {
      args.timeout = parseInt(argv[++i], 10);
    } else if (flag === '--base-url' && argv[i + 1]) {
      args.baseUrl = argv[++i];
    } else if (flag === '--proxy' && argv[i + 1]) {
      args.proxy = argv[++i];
    } else if (flag === '--proxy-rules' && argv[i + 1]) {
      args.proxyRules = argv[++i];
    } else if (flag === '--variables' && argv[i + 1]) {
      args.variables = argv[++i];
    } else if (flag === '--cookies' && argv[i + 1]) {
      args.cookies = argv[++i];
    } else if (flag === '--browser' && argv[i + 1]) {
      args.browser = argv[++i];
    } else if (flag === '--headless' && argv[i + 1]) {
      args.headless = argv[++i] !== 'false';
    } else if (flag === '--take-screenshot' && argv[i + 1]) {
      args.takeScreenshot = argv[++i] !== 'false';
    }
  }
  return args;
}

// ---------------------------------------------------------------------------
// Write result to stdout and exit
// ---------------------------------------------------------------------------
function finish(result, exitCode) {
  process.stdout.write(JSON.stringify(result));
  process.exit(exitCode);
}

// ---------------------------------------------------------------------------
// Top-level entry point
// ---------------------------------------------------------------------------
(async () => {
  let args;

  try {
    // Required here (not at module top-level) so a failure to resolve harness.cjs
    // or its playwright dependency is caught below and still emits structured JSON,
    // instead of crashing Node before anything is written to stdout.
    const { runWithHarness } = require('./harness.cjs');

    args = parseArgs(process.argv.slice(2));

    if (!args.spec) {
      throw new Error('Missing required argument: --spec <path>');
    }
    if (!args.output) {
      throw new Error('Missing required argument: --output <dir>');
    }

    const specPath = path.resolve(args.spec);
    const outputDir = path.resolve(args.output);
    const timeout = Number.isFinite(args.timeout) && args.timeout > 0 ? args.timeout : 30000;
    const baseUrl = args.baseUrl || '';
    const proxy = args.proxy || null;
    const proxyRules = args.proxyRules ? JSON.parse(fs.readFileSync(path.resolve(args.proxyRules), 'utf8')) : [];
    const variables = args.variables ? JSON.parse(fs.readFileSync(path.resolve(args.variables), 'utf8')) : {};
    const cookies = args.cookies ? JSON.parse(fs.readFileSync(path.resolve(args.cookies), 'utf8')) : [];
    const browser = args.browser || 'chromium';
    const headless = args.headless !== false;
    const takeScreenshot = args.takeScreenshot !== false;

    // Read the spec file
    let generatedCode;
    try {
      generatedCode = fs.readFileSync(specPath, 'utf8');
    } catch (readErr) {
      throw new Error(`Failed to read spec file "${specPath}": ${readErr.message}`);
    }

    // Run the test
    const result = await runWithHarness(generatedCode, outputDir, baseUrl, timeout, proxy, browser, headless, takeScreenshot, proxyRules, variables, cookies);

    const exitCode = result.status === 'passed' ? 0 : 1;
    finish(result, exitCode);

  } catch (err) {
    // Uncaught / setup errors — still emit structured JSON so the caller can parse it
    const errorResult = {
      status: 'error',
      duration_ms: 0,
      error_message: err instanceof Error ? err.message : String(err),
      error_stack: err instanceof Error ? (err.stack || null) : null,
      screenshots: [],
    };
    finish(errorResult, 1);
  }
})();
