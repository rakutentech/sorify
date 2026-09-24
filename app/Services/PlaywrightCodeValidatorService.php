<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

/**
 * Bans host-escaping constructs from Playwright test code: the module
 * system, process/env access, dynamic code execution, node built-ins and
 * raw network calls. Test code runs on the Sorify server, so this filter
 * is what keeps a crafted (or prompt-injected) test script from turning
 * into arbitrary command execution — the Docker sandbox is defense in
 * depth, and in local mode this is the only barrier.
 *
 * Patterns are matched against the raw code (comments are NOT stripped
 * first: naive stripping creates bypasses — a `/*` inside a string
 * literal would swallow the real code after it). Over-matching is the
 * safe direction: a false positive just bounces a test back to its
 * author, a false negative runs on the server.
 */
class PlaywrightCodeValidatorService
{
    private const BANNED_PATTERNS = [
        // Module system — call, member and index access (require('fs'),
        // require.call, require['fs'], module.constructor, …).
        '/\brequire\s*[\[.(]/i',
        '/\bmodule\s*[\[.]/i',
        '/\bexports\s*[\[.]/i',
        '/\bimport\s*[\s[(]/i',
        // Host access — process (env, mainModule, binding, exit, …).
        '/\bprocess\b/i',
        // Dynamic code execution — direct, aliased or via constructors.
        '/\beval\b/i',
        '/\bFunction\b/',
        '/\bconstructor\b/i',
        '/\bWebAssembly\b/',
        // String arguments are implicit eval.
        '/\bsetTimeout\s*\(\s*[\'"]/',
        '/\bsetInterval\s*\(\s*[\'"]/',
        // The global object, however it is reached.
        '/\bglobalThis\b/i',
        '/\bglobal\s*\./i',
        // Node built-ins and process spawning.
        '/\bfs\.\w+\s*\(/i',
        '/\bchild_process\b/i',
        '/\bexec\s*\(/i',
        '/\bspawn\s*\(/i',
        // Raw HTTP from the script — requests must go through the
        // browser context (page/context.request), not node's fetch.
        '/\bfetch\s*\(/i',
        // Unicode-escaped identifiers hide banned names (\u0072equire).
        '/\\\\u[0-9a-fA-F]{4}/',
    ];

    public function validate(string $code): void
    {
        foreach (self::BANNED_PATTERNS as $pattern) {
            if (preg_match($pattern, $code)) {
                throw ValidationException::withMessages([
                    'playwright_code' => ['The playwright_code contains disallowed patterns (module system, process/env access, dynamic code execution, node built-ins, raw network calls). Test code must only use the provided page, context, browser, baseUrl and variables.'],
                ]);
            }
        }
    }
}
