'use strict'

const fs = require('fs')
const path = require('path')
// Resolve playwright relative to this file so it works regardless of cwd or machine
const playwright = require(path.join(__dirname, '..', '..', 'node_modules', 'playwright'))

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
 * @param {string|null} proxy      HTTP proxy URL for Playwright (e.g. http://proxy:8080), or null
 * @param {string}      browserName  Browser engine: 'chromium' | 'firefox' | 'webkit'
 * @param {boolean}     headless   Whether to run headless
 * @param {boolean}     takeScreenshot  Whether page.screenshot() calls actually capture a PNG
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
    proxy = null,
    browserName = 'chromium',
    headless = true,
    takeScreenshot = true
) {
    // Ensure output directory exists before anything else
    fs.mkdirSync(outputDir, { recursive: true })

    const screenshots = []
    let screenshotCounter = 0

    const startTime = Date.now()

    let browser = null

    // Clean shutdown on SIGTERM
    const sigTermHandler = async () => {
        if (browser) {
            await browser.close().catch(() => {})
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
            if (require('fs').existsSync(systemChrome)) {
                launchOpts.executablePath = systemChrome
            }
        }
        browser = await playwright[browserType].launch(launchOpts)
        const contextOpts = proxy ? { proxy: { server: proxy } } : {}
        const context = await browser.newContext(contextOpts)
        const page = await context.newPage()

        // Apply default timeout to the page
        page.setDefaultTimeout(timeout)
        page.setDefaultNavigationTimeout(timeout)

        // Monkey-patch page.screenshot to auto-save PNGs and collect metadata.
        // When takeScreenshot is disabled, skip capture entirely for faster runs.
        const originalScreenshot = page.screenshot.bind(page)
        page.screenshot = async function patchedScreenshot (options = {}) {
            if (!takeScreenshot) {
                return Buffer.alloc(0)
            }

            screenshotCounter += 1
            const seq = String(screenshotCounter).padStart(3, '0')
            const filename = `step-${seq}.png`
            const filePath = path.join(outputDir, filename)

            // Force path so the PNG always ends up in outputDir regardless of caller options
            const buffer = await originalScreenshot({
                ...options,
                path: filePath
            })

            const label = options.label || options.path || filename
            const takenAtMs = Date.now()

            screenshots.push({
                filename,
                label: String(label),
                taken_at_ms: takenAtMs
            })

            return buffer
        }

        // Execute the generated code.
        // `require` is shadowed to prevent the generated code from loading arbitrary modules.
        // The variables page, context, browser, and baseUrl are available in scope.
        await (async function executeGenerated () {
            /* eslint-disable no-unused-vars */
            const require = () => {
                throw new Error('require not allowed in test code')
            }
            /* eslint-enable no-unused-vars */

            // eval receives the outer function's scope, giving it access to:
            //   page, context, browser, baseUrl, require (shadowed)
            // We wrap in an async IIFE inside eval so the generated code can use await freely.
            await eval(`(async () => { ${generatedCode} })()`)
        })()

        const duration_ms = Date.now() - startTime

        process.removeListener('SIGTERM', sigTermHandler)
        await browser.close()

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
