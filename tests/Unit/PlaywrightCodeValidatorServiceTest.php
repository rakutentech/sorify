<?php

namespace Tests\Unit;

use App\Services\PlaywrightCodeValidatorService;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PlaywrightCodeValidatorServiceTest extends TestCase
{
    private PlaywrightCodeValidatorService $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new PlaywrightCodeValidatorService();
    }

    public static function rejectsProvider(): array
    {
        return [
            'classic require' => ["require('child_process')"],
            'dynamic import' => ["import('child_process').then(m => m.exec('rm -rf /'))"],
            'static import' => ["import fs from 'fs'"],
            'process.env' => ['console.log(process.env.DB_PASSWORD)'],
            'process bracket access' => ['const p = process["mainModule"];'],
            'process anywhere' => ['const env = process;'],
            'fs usage' => ["require('fs')"],
            'eval' => ['eval("require(\'child_process\')")'],
            'new Function constructor' => ["new Function('return process')()"],
            'globalThis' => ["const cp = globalThis['child_process'];"],
            'exec call' => ["exec('id')"],
            'spawn call' => ["spawn('rm', ['-rf', '/'])"],

            // Indirect / member-access bypasses of the module system.
            'require via call' => ["require.call(null, 'fs')"],
            'require via index' => ["require['resolve']('fs')"],
            'require as value' => ["const r = require('fs'); r.readFile('/etc/passwd')"],
            'module constructor' => ["const F = module.constructor('return process');"],
            'exports member' => ["module.exports = {};"],
            'constructor reach' => ["const F = (function(){}).constructor; F('1+1');"],
            'bare Function' => ["const f = Function('return 1');"],

            // Globals and host access.
            'global member' => ['const p = global.process;'],
            'webassembly' => ['WebAssembly.instantiate(buf, imports);'],
            'unicode escaped require' => ["\\u0072equire('fs')"],

            // Raw network from the script.
            'raw fetch' => ["await fetch('http://169.254.169.254/latest/meta-data/');"],
            'string setTimeout' => ["setTimeout(\"require('fs')\", 0);"],
            'string setInterval' => ["setInterval('while(1){}', 1000);"],
        ];
    }

    #[DataProvider('rejectsProvider')]
    public function test_rejects_disallowed_patterns(string $code): void
    {
        $this->expectException(ValidationException::class);
        $this->validator->validate($code);
    }

    public function test_accepts_normal_test_code(): void
    {
        $code = <<<'JS'
await page.goto(baseUrl);
const title = await page.textContent('h1');
if (!title) throw new Error('missing title');
await page.click('button#submit');
await page.waitForTimeout(500);
JS;

        $this->validator->validate($code);
        $this->assertTrue(true);
    }

    public function test_accepts_test_code_with_safe_strings(): void
    {
        // Words that must not trip the filter from inside string
        // literals and selectors of ordinary test code.
        $code = <<<'JS'
await page.goto('https://example.com/import?tab=global');
const text = await page.textContent('main');
if (!text.includes('processing')) throw new Error('not processing');
JS;

        $this->validator->validate($code);
        $this->assertTrue(true);
    }
}
