<?php

namespace Tests\Feature;

use App\Models\TestSuite;
use App\Models\User;
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
            'version_number'  => 1,
            'playwright_code' => 'v1',
            'source'          => 'manual',
        ]);

        $this->actingAs($user)
            ->post("/sorify/suites/{$suite->id}/tests/{$test->id}/code-versions/{$version->id}/restore")
            ->assertRedirect();

        $this->assertSame('v1', $test->fresh()->playwright_code);
        $this->assertSame(2, $test->codeVersions()->count());
    }
}
