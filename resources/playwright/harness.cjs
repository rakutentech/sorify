'use strict'

const fs = require('fs')
const http = require('http')
const net = require('net')
const path = require('path')
// Resolve playwright relative to this file so it works regardless of cwd or machine
const playwright = require(path.join(__dirname, '..', '..', 'node_modules', 'playwright'))

/**
 * Picks the proxy (if any) that should handle a request to `host`, based on
 * per-domain rules, falling back to `defaultProxy`. Each rule's `domain` is a
 * regular expression tested against the hostname (case-insensitive). Examples:
 *   - "^example\\.com$"         exact host only: example.com, not foo.example.com
 *   - "(^|\\.)example\\.com$"   host or subdomain: example.com, foo.example.com; not notexample.com
 *   - "example\\.com$"         avoid: also matches unrelated hosts like notexample.com
 * An invalid pattern is skipped (logged to stderr) rather than aborting the whole run.
 */
function resolveProxyForHost (host, proxyRules, defaultProxy) {
    for (const rule of proxyRules) {
        try {
            if (new RegExp(rule.domain, 'i').test(host)) {
                return rule.proxy
            }
        } catch (err) {
            console.error(`Invalid proxy rule pattern "${rule.domain}": ${err.message}`)
        }
    }
    return defaultProxy || null
}

/**
 * Starts a tiny local forward proxy that dispatches each CONNECT tunnel (i.e.
 * every HTTPS connection Chromium makes) either straight to its real target
 * (DIRECT) or through a specific upstream proxy, based on resolveProxyForHost().
 *
 * This exists because Playwright's context only supports one static proxy for
 * its whole lifetime, and Chromium's native --proxy-pac-url mechanism (the
 * only way to get per-domain routing from Chromium itself) turned out to be
 * unreliable in this deployment. Chromium is instead pointed at this local
 * dispatcher as its one-and-only proxy — a mechanism proven reliable here —
 * and the dispatcher does the actual per-domain routing itself in Node, where
 * it sees every real TCP connection (including every hop of a redirect chain,
 * unlike Playwright's route() interception, which does not re-fire per hop).
 */
function startProxyDispatcher (proxyRules, defaultProxy) {
    return new Promise((resolveServer, rejectServer) => {
        // Plain HTTP requests arrive as absolute-URI proxy requests (not CONNECT) —
        // relay them to the real target or an upstream proxy, matching the same rules.
        const server = http.createServer((req, res) => {
            let targetUrl
            try {
                targetUrl = new URL(req.url)
            } catch {
                res.writeHead(400)
                res.end()
                return
            }

            const upstream = resolveProxyForHost(targetUrl.hostname, proxyRules, defaultProxy)
            const relayVia = upstream ? new URL(upstream.includes('://') ? upstream : `http://${upstream}`) : targetUrl

            const relayReq = http.request({
                host: relayVia.hostname,
                port: Number(relayVia.port) || 80,
                method: req.method,
                path: upstream ? req.url : (targetUrl.pathname + targetUrl.search),
                headers: req.headers
            }, (relayRes) => {
                res.writeHead(relayRes.statusCode, relayRes.headers)
                relayRes.pipe(res)
            })
            relayReq.on('error', () => { res.writeHead(502); res.end() })
            req.pipe(relayReq)
        })

        server.on('connect', (req, clientSocket, head) => {
            clientSocket.on('error', () => {})

            const [host, portStr] = req.url.split(':')
            const port = parseInt(portStr, 10) || 443
            const upstream = resolveProxyForHost(host, proxyRules, defaultProxy)

            if (!upstream) {
                const upstreamSocket = net.connect(port, host, () => {
                    clientSocket.write('HTTP/1.1 200 Connection Established\r\n\r\n')
                    upstreamSocket.write(head)
                    upstreamSocket.pipe(clientSocket)
                    clientSocket.pipe(upstreamSocket)
                })
                upstreamSocket.on('error', () => clientSocket.end())
                return
            }

            let upstreamUrl
            try {
                upstreamUrl = new URL(upstream.includes('://') ? upstream : `http://${upstream}`)
            } catch {
                clientSocket.end()
                return
            }

            const upstreamSocket = net.connect(Number(upstreamUrl.port) || 80, upstreamUrl.hostname, () => {
                upstreamSocket.write(`CONNECT ${host}:${port} HTTP/1.1\r\nHost: ${host}:${port}\r\n\r\n`)
            })

            let tunnelEstablished = false
            upstreamSocket.on('data', (chunk) => {
                if (tunnelEstablished) return
                tunnelEstablished = true
                if (/^HTTP\/1\.[01] 200/.test(chunk.toString('latin1'))) {
                    clientSocket.write('HTTP/1.1 200 Connection Established\r\n\r\n')
                    upstreamSocket.write(head)
                    upstreamSocket.pipe(clientSocket)
                    clientSocket.pipe(upstreamSocket)
                } else {
                    clientSocket.end()
                    upstreamSocket.end()
                }
            })
            upstreamSocket.on('error', () => clientSocket.end())
        })

        server.on('error', rejectServer)
        server.listen(0, '127.0.0.1', () => resolveServer(server))
    })
}

/**
 * runWithHarness
 *
 * Executes a generated Playwright test code string inside a controlled browser
 * context and returns a structured result object.
 *
 * @param {string}  generatedCode  The test code to eval (receives page, context, browser, baseUrl)
 * @param {string}  outputDir      Directory where screenshot PNGs are written
 * @param {string}  baseUrl        Base URL passed into the generated code scope
 * @param {number}  timeout        Navigation / action timeout in milliseconds
 * @param {string|null} defaultProxy  HTTP proxy URL used when no proxyRule matches a request's host, or null
 * @param {string}      browserName  Browser engine: 'chromium' | 'firefox' | 'webkit'
 * @param {boolean}     headless   Whether to run headless
 * @param {boolean}     takeScreenshot  Whether page.screenshot() calls actually capture a PNG
 * @param {Array<{domain: string, proxy: string}>} proxyRules  Per-host proxy overrides; `domain` is a regex tested against the hostname
 * @param {Record<string, *>}  variables  Suite-level key/value pairs exposed to the test code as a `variables` object
 * @param {Array<{name: string, value: string, domain?: string, url?: string, path?: string, expires?: number, httpOnly?: boolean, secure?: boolean, sameSite?: 'Strict'|'Lax'|'None'}>}  cookies  Suite-level cookies added to the browser context before any page is created
 * @returns {Promise<{
 *   status: 'passed'|'failed'|'error',
 *   duration_ms: number,
 *   error_message: string|null,
 *   error_stack: string|null,
 *   screenshots: Array<{filename: string, label: string, taken_at_ms: number}>
 * }>}
 */
async function runWithHarness (
    generatedCode,
    outputDir,
    baseUrl,
    timeout,
    defaultProxy = null,
    browserName = 'chromium',
    headless = true,
    takeScreenshot = true,
    proxyRules = [],
    variables = {},
    cookies = []
) {
    // Ensure output directory exists before anything else
    fs.mkdirSync(outputDir, { recursive: true })

    const screenshots = []
    let screenshotCounter = 0

    const startTime = Date.now()

    let browser = null
    let dispatcher = null

    // Clean shutdown on SIGTERM
    const sigTermHandler = async () => {
        if (browser) {
            await browser.close().catch(() => {})
        }
        if (dispatcher) {
            dispatcher.close()
        }
        process.exit(0)
    }
    process.on('SIGTERM', sigTermHandler)

    try {
        const validBrowsers = ['chromium', 'firefox', 'webkit']
        const browserType = validBrowsers.includes(browserName)
            ? browserName
            : 'chromium'
        const launchOpts = { headless: !!headless }
        // Use system Chrome if Playwright's bundled binary is unavailable (chromium only)
        if (browserType === 'chromium') {
            const systemChrome =
                '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome'
            if (fs.existsSync(systemChrome)) {
                launchOpts.executablePath = systemChrome
            }
        }

        // With per-domain rules, each request needs its own routing decision, so
        // Chromium is pointed at a local dispatcher that decides per-connection.
        // Without rules, the simpler direct path is used: a single default proxy
        // (or none) for the whole context.
        const hasProxyRules = Array.isArray(proxyRules) && proxyRules.length > 0
        let proxyServer = defaultProxy || null
        if (hasProxyRules) {
            dispatcher = await startProxyDispatcher(proxyRules, defaultProxy)
            proxyServer = `http://127.0.0.1:${dispatcher.address().port}`
        }

        browser = await playwright[browserType].launch(launchOpts)
        const contextOpts = proxyServer ? { proxy: { server: proxyServer } } : {}
        const context = await browser.newContext(contextOpts)

        // Inject suite-level cookies before any page is created, so tests
        // start already authenticated. Playwright requires each cookie to
        // carry either `url` or `domain`; a missing/empty `expires` means a
        // session cookie and is normalized to -1 (Playwright's sentinel).
        if (Array.isArray(cookies) && cookies.length > 0) {
            const normalized = cookies.map((c) => {
                const out = { ...c }
                if (out.expires === null || out.expires === undefined || out.expires === '') {
                    out.expires = -1
                }
                return out
            })
            await context.addCookies(normalized)
        }

        const page = await context.newPage()

        // Apply default timeout to the page
        page.setDefaultTimeout(timeout)
        page.setDefaultNavigationTimeout(timeout)

        // Monkey-patch screenshot capture at the PROTOTYPE level so the suite's
        // take_screenshot setting takes priority over ANY screenshot call in the
        // generated test code: page.screenshot(), locator.screenshot(), on the
        // initial page or any page later created via context.newPage().
        //
        // When disabled, every screenshot call short-circuits to an empty Buffer
        // and writes no PNG, regardless of what the test code requests. When
        // enabled, PNGs are forced into outputDir (caller-supplied paths are
        // ignored) and recorded in the screenshots index.
        const PageCtor = page.constructor
        const LocatorCtor = page.locator('html').constructor

        const recordScreenshot = (options, source) => {
            screenshotCounter += 1
            const seq = String(screenshotCounter).padStart(3, '0')
            const filename = `step-${seq}.png`
            const filePath = path.join(outputDir, filename)
            const label = options.label || options.path || `${source}-${seq}`
            screenshots.push({
                filename,
                label: String(label),
                taken_at_ms: Date.now()
            })
            return filePath
        }

        // Page-level screenshots — covers the initial page AND any page created
        // via context.newPage(), since they share the same prototype.
        const originalPageScreenshot = PageCtor.prototype.screenshot
        PageCtor.prototype.screenshot = async function patchedPageScreenshot (options = {}) {
            if (!takeScreenshot) {
                return Buffer.alloc(0)
            }
            const filePath = recordScreenshot(options, 'page')
            return originalPageScreenshot.call(this, { ...options, path: filePath })
        }

        // Element-level screenshots via locator.screenshot().
        const originalLocatorScreenshot = LocatorCtor.prototype.screenshot
        LocatorCtor.prototype.screenshot = async function patchedLocatorScreenshot (options = {}) {
            if (!takeScreenshot) {
                return Buffer.alloc(0)
            }
            const filePath = recordScreenshot(options, 'locator')
            return originalLocatorScreenshot.call(this, { ...options, path: filePath })
        }

        // Execute the generated code.
        // `require` is shadowed to prevent the generated code from loading arbitrary modules.
        // The variables page, context, browser, baseUrl, and variables are available in scope.
        await (async function executeGenerated () {
            /* eslint-disable no-unused-vars */
            const require = () => {
                throw new Error('require not allowed in test code')
            }
            /* eslint-enable no-unused-vars */

            // eval receives the outer function's scope, giving it access to:
            //   page, context, browser, baseUrl, variables, require (shadowed)
            // We wrap in an async IIFE inside eval so the generated code can use await freely.
            await eval(`(async () => { ${generatedCode} })()`)
        })()

        const duration_ms = Date.now() - startTime

        process.removeListener('SIGTERM', sigTermHandler)
        await browser.close()
        if (dispatcher) {
            dispatcher.close()
        }

        return {
            status: 'passed',
            duration_ms,
            error_message: null,
            error_stack: null,
            screenshots
        }
    } catch (err) {
        const duration_ms = Date.now() - startTime

        process.removeListener('SIGTERM', sigTermHandler)
        if (browser) {
            await browser.close().catch(() => {})
        }
        if (dispatcher) {
            dispatcher.close()
        }

        // Distinguish a deliberate test assertion failure from an unexpected harness error.
        // Generated code that throws an AssertionError (or any Error) is treated as 'failed';
        // anything that is not an Error instance (should not happen normally) is 'error'.
        const status = err instanceof Error ? 'failed' : 'error'

        return {
            status,
            duration_ms,
            error_message: err instanceof Error ? err.message : String(err),
            error_stack: err instanceof Error ? err.stack || null : null,
            screenshots
        }
    }
}

module.exports = { runWithHarness }
