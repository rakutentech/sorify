<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class PlaywrightCodeValidatorService
{
    private const BANNED_PATTERNS = [
        '/\brequire\s*\(/i',
        '/\bimport\s+/i',
        '/\bimport\s*\(/i',
        '/\bprocess\.env\b/i',
        '/\bprocess\s*\[/i',
        '/\bfs\.\w+\s*\(/i',
        '/\bchild_process\b/i',
        '/\bexec\s*\(/i',
        '/\bspawn\s*\(/i',
        '/\beval\s*\(/i',
        '/\bnew\s+Function\b/i',
        '/\bglobalThis\b/i',
    ];

    public function validate(string $code): void
    {
        foreach (self::BANNED_PATTERNS as $pattern) {
            if (preg_match($pattern, $code)) {
                throw ValidationException::withMessages([
                    'playwright_code' => ['The playwright_code contains disallowed patterns (require, import, eval, exec, spawn, fs.*, process.env, child_process).'],
                ]);
            }
        }
    }
}
