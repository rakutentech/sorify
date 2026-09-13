<?php

namespace Tests\Feature;

use App\Models\TestSuite;
use App\Models\User;
use App\Services\TestCodeVersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TestCodeVersionRestoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_restore_route_resolves_the_nested_scoped_binding_and_restores_code(): void
    {
        $user = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com']);
        $test = $suite->tests()->create(['name' => 'A', 'playwright_code' => 'v1', 'status' => 'active']);

        $test->update(['playwright_code' => 'v2']);
        $version = $test->codeVersions()->create([
            'version_number' => 1,
            'playwright_code' => 'v1',
            'source' => 'manual',
        ]);

        $this->actingAs($user)
            ->post("/sorify/suites/{$suite->id}/tests/{$test->id}/code-versions/{$version->id}/restore")
            ->assertRedirect();

        $this->assertSame('v1', $test->fresh()->playwright_code);
        $this->assertSame(2, $test->codeVersions()->count());
    }

    public function test_restore_brings_back_the_version_attribution(): void
    {
        $user = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com']);
        $test = $suite->tests()->create([
            'name' => 'A',
            'playwright_code' => 'ai code',
            'code_source' => 'agent',
            'code_ai_model' => 'gpt-4o',
            'status' => 'active',
        ]);

        // Replace with manual code — archives the AI code with its model.
        app(TestCodeVersionService::class)
            ->updateCode($test->fresh(), 'manual code', 'manual', $user->id);

        $version = $test->codeVersions()->firstOrFail();

        $this->actingAs($user)
            ->post("/sorify/suites/{$suite->id}/tests/{$test->id}/code-versions/{$version->id}/restore")
            ->assertRedirect();

        // Restoring brings back the code AND the model that wrote it.
        $test = $test->fresh();

        $this->assertSame('ai code', $test->playwright_code);
        $this->assertSame('gpt-4o', $test->code_ai_model);
    }
}
