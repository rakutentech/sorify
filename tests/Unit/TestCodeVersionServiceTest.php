<?php

namespace Tests\Unit;

use App\Models\TestSuite;
use App\Services\TestCodeVersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TestCodeVersionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_code_snapshots_the_previous_code_as_a_new_version(): void
    {
        $suite = TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com']);
        $test = $suite->tests()->create(['name' => 'A', 'playwright_code' => 'old code', 'status' => 'disabled']);

        app(TestCodeVersionService::class)->updateCode($test, 'new code', 'manual', null);

        $this->assertSame('new code', $test->fresh()->playwright_code);
        $this->assertSame('active', $test->fresh()->status);
        $this->assertDatabaseHas('test_code_versions', [
            'test_id'         => $test->id,
            'version_number'  => 1,
            'playwright_code' => 'old code',
            'source'          => 'manual',
        ]);
    }

    public function test_update_code_is_a_no_op_version_when_code_is_unchanged(): void
    {
        $suite = TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com']);
        $test = $suite->tests()->create(['name' => 'A', 'playwright_code' => 'same code', 'status' => 'active']);

        app(TestCodeVersionService::class)->updateCode($test, 'same code', 'manual', null);

        $this->assertSame(0, $test->codeVersions()->count());
    }

    public function test_update_code_prunes_versions_beyond_the_configured_retention(): void
    {
        config(['sorify.test_code_version_retention' => 3]);

        $suite = TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com']);
        $test = $suite->tests()->create(['name' => 'A', 'playwright_code' => 'v0', 'status' => 'active']);

        $service = app(TestCodeVersionService::class);
        for ($i = 1; $i <= 5; $i++) {
            $service->updateCode($test, "v{$i}", 'manual', null);
        }

        $this->assertSame(3, $test->codeVersions()->count());
        $this->assertSame([5, 4, 3], $test->codeVersions()->pluck('version_number')->all());
        $this->assertSame('v5', $test->fresh()->playwright_code);
    }

    public function test_restore_reapplies_an_old_version_and_snapshots_the_current_code(): void
    {
        $suite = TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com']);
        $test = $suite->tests()->create(['name' => 'A', 'playwright_code' => 'v1', 'status' => 'active']);

        $service = app(TestCodeVersionService::class);
        $service->updateCode($test, 'v2', 'manual', null);

        $oldVersion = $test->codeVersions()->where('version_number', 1)->first();

        $service->restore($test, $oldVersion, 'manual', null);

        $this->assertSame('v1', $test->fresh()->playwright_code);
        $this->assertSame(2, $test->codeVersions()->count());
        $this->assertDatabaseHas('test_code_versions', [
            'test_id'         => $test->id,
            'version_number'  => 2,
            'playwright_code' => 'v2',
        ]);
    }
}
