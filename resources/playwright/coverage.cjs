'use strict'

/**
 * coverage.cjs — Merges per-test Chromium V8 coverage into a run-level report.
 *
 * Called by GenerateRunCoverageReportJob (PHP) via:
 *   node coverage.cjs --files <path-to-json-file> --output <dir> [--filter <glob>]
 *
 * --files points to a JSON file containing an array of paths, each pointing at
 * a coverage.json written by harness.cjs ({url, entries: JSCoverageEntry[]}).
 *
 * Pipeline (per script entry, across every test file):
 *   V8 entries → v8-to-istanbul → istanbul-lib-coverage (merged) → istanbul-reports
 *
 * Outputs into --output:
 *   index.html (+ assets/)  browsable HTML report
 *   lcov.info               LCOV interchange file for CI/SonarQube/IDE tooling
 *
 * Writes a single JSON line to stdout:
 *   {status: 'ok', summary: {lines: {...}, functions: {...}, branches: {...}, statements: {...}}, files: N}
 * …so the PHP caller can parse it the same way runner.cjs's payload is parsed
 * (the last line starting with '{').
 */

const fs = require('fs')
const path = require('path')

// Resolve relative to this file so it works regardless of cwd or machine —
// same convention as harness.cjs.
const nodeModules = path.join(__dirname, '..', '..', 'node_modules')
const v8ToIstanbul = require(path.join(nodeModules, 'v8-to-istanbul'))
const libCoverage = require(path.join(nodeModules, 'istanbul-lib-coverage'))
const istanbulReports = require(path.join(nodeModules, 'istanbul-reports'))
const libReport = require(path.join(nodeModules, 'istanbul-lib-report'))

function parseArgs (argv) {
  const args = {}
  for (let i = 0; i < argv.length; i++) {
    const flag = argv[i]
    if (flag === '--files' && argv[i + 1]) {
      args.files = argv[++i]
    } else if (flag === '--output' && argv[i + 1]) {
      args.output = argv[++i]
    } else if (flag === '--filter' && argv[i + 1]) {
      args.filter = argv[++i]
    }
  }
  return args
}

// Simple glob-style filters, matching harness.cjs: every character is
// literal except `*`, which matches any run of characters. Multiple patterns
// are comma-separated and OR-matched (a script matching any one is kept).
// Patterns are tested against the script's origin + path only — never the
// query string — so tracking URLs that merely embed the target site's URL as
// a query parameter do not sneak past a domain filter.
function coverageFilterToRegex (pattern) {
  const escaped = pattern
    .replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
    .replace(/\\\*/g, '[\\s\\S]*')
  return new RegExp(escaped, 'i')
}

function splitCoverageFilters (filter) {
  return String(filter)
    .split(',')
    .map((pattern) => pattern.trim())
    .filter((pattern) => pattern.length > 0)
}

function scriptUrlKey (url) {
  try {
    const u = new URL(url)
    return u.origin + u.pathname
  } catch {
    return String(url)
  }
}

// Istanbul's HTML report writes one file per script, named after the script's
// full URL — query string included. Real-world tracking scripts carry 800+
// character URLs, which exceed the filesystem's filename limit and would
// crash the whole report with ENAMETOOLONG. Keys longer than this are
// shortened: the query string collapses to a short hash (stable per URL), and
// a pathological path is truncated with a hash suffix. Normal-length URLs
// keep their exact query-stringed names.
const MAX_URL_KEY_LENGTH = 180

function hashOf (text) {
  return require('crypto').createHash('sha1').update(text).digest('hex').slice(0, 12)
}

function safeCoverageUrl (url) {
  if (url.length <= MAX_URL_KEY_LENGTH) return url
  try {
    const u = new URL(url)
    if (u.search) {
      u.search = '?' + hashOf(u.search)
    }
    let shortened = u.toString()
    if (shortened.length > MAX_URL_KEY_LENGTH) {
      shortened = shortened.slice(0, MAX_URL_KEY_LENGTH - 13) + '-' + hashOf(url)
    }
    return shortened
  } catch {
    return url.slice(0, MAX_URL_KEY_LENGTH - 13) + '-' + hashOf(url)
  }
}

function exitWith (status, message) {
  process.stdout.write(JSON.stringify({ status, error: message }))
  process.exit(status === 'ok' ? 0 : 1)
}

;(async () => {
  const args = parseArgs(process.argv.slice(2))

  if (!args.files || !args.output) {
    exitWith('error', 'Missing required arguments: --files <path> --output <dir>')
  }

  let files
  try {
    files = JSON.parse(fs.readFileSync(path.resolve(args.files), 'utf8'))
  } catch (err) {
    exitWith('error', `Failed to read files list: ${err.message}`)
  }

  if (!Array.isArray(files) || files.length === 0) {
    exitWith('error', 'No coverage files to merge')
  }

  let filterRegexes = []
  if (args.filter) {
    filterRegexes = splitCoverageFilters(args.filter).map(coverageFilterToRegex)
  }

  fs.mkdirSync(args.output, { recursive: true })

  const map = libCoverage.createCoverageMap({})
  let entryCount = 0

  for (const file of files) {
    let payload
    try {
      payload = JSON.parse(fs.readFileSync(file, 'utf8'))
    } catch (err) {
      console.error(`Skipping unreadable coverage file "${file}": ${err.message}`)
      continue
    }

    const entries = Array.isArray(payload?.entries) ? payload.entries : []

    for (const entry of entries) {
      if (filterRegexes.length > 0 && !filterRegexes.some((regex) => regex.test(scriptUrlKey(entry.url || '')))) continue
      if (!entry.source) {
        // Playwright includes each script's source text so conversion is
        // fully offline; without it v8-to-istanbul cannot map ranges.
        console.error(`Skipping entry without source: ${entry.url}`)
        continue
      }

      try {
        // entry.source carries the script text Playwright captured in the
        // browser, so no network/disk access is needed to convert. The URL
        // is the coverage-map key (and the report file name), so it is
        // shortened first to keep istanbul's writer inside filesystem limits.
        const converter = v8ToIstanbul(safeCoverageUrl(entry.url), 0, { source: entry.source })
        await converter.load()
        converter.applyCoverage(entry.functions)
        map.merge(converter.toIstanbul())
        entryCount += 1
      } catch (err) {
        console.error(`Skipping coverage entry ${entry.url}: ${err.message}`)
      }
    }
  }

  if (entryCount === 0) {
    exitWith('error', 'No convertible coverage entries found')
  }

  const context = libReport.createContext({ dir: args.output, coverageMap: map })
  istanbulReports.create('html').execute(context)
  istanbulReports.create('lcovonly', { file: 'lcov.info' }).execute(context)

  const summary = map.getCoverageSummary().data

  process.stdout.write(JSON.stringify({
    status: 'ok',
    files: files.length,
    entries: entryCount,
    summary: {
      lines: summary.lines,
      functions: summary.functions,
      branches: summary.branches,
      statements: summary.statements,
    },
  }))
  process.exit(0)
})().catch((err) => {
  exitWith('error', err instanceof Error ? err.message : String(err))
})
