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
            'fs usage' => ["require('fs')"],
            'eval' => ['eval("require(\'child_process\')")'],
            'new Function constructor' => ["new Function('return process')()"],
            'globalThis' => ["const cp = globalThis['child_process'];"],
            'exec call' => ["exec('id')"],
            'spawn call' => ["spawn('rm', ['-rf', '/'])"],
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
}
