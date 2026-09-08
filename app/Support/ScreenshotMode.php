<?php

namespace App\Support;

/**
 * The screenshot capture mode stored in test_suites.take_screenshot.
 *
 *  - enabled   — screenshots captured whenever the test code asks for one
 *  - on_failure — captured as usual, but discarded when the test passes
 *  - disabled  — never captured (screenshot calls short-circuit)
 *
 * The column started life as a boolean; normalize() maps legacy boolean
 * input (true/false, 'true'/'false', 1/0, '1'/'0') onto the corresponding
 * mode so existing API/MCP callers keep working.
 */
class ScreenshotMode
{
    public const ENABLED = 'enabled';

    public const ON_FAILURE = 'on_failure';

    public const DISABLED = 'disabled';

    public const ALL = [self::ENABLED, self::ON_FAILURE, self::DISABLED];

    /**
     * Map legacy boolean-like input to a mode string. Returns the value
     * unchanged when it is null, already a valid mode, or not boolean-like
     * (validation rejects the latter).
     */
    public static function normalize(mixed $value): mixed
    {
        if ($value === null || in_array($value, self::ALL, true)) {
            return $value;
        }

        $bool = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);

        return $bool === null ? $value : ($bool ? self::ENABLED : self::DISABLED);
    }
}
