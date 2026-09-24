<?php

namespace Tests\Feature;

use App\Models\Test;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The single-test page exposes suite edit privileges (can.edit) so suite
 * variables can be edited inline from the test page.
 */
class TestPageCanTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_sees_edit_privilege_on_the_test_page(): void
    {
        [$suite, $test] = $this->suiteWithTest();

        $this->actingAs(User::factory()->create(['is_admin' => true]))
            ->get("/sorify/suites/{$suite->id}/tests/{$test->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can.edit', true)
                ->etc());
    }

    public function test_edit_member_sees_edit_privilege_on_the_test_page(): void
    {
        [$suite, $test] = $this->suiteWithTest();

        $member = User::factory()->create();
        $suite->members()->attach($member, ['can_view' => true, 'can_edit' => true, 'can_delete' => false, 'can_run' => false]);

        $this->actingAs($member)
            ->get("/sorify/suites/{$suite->id}/tests/{$test->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can.edit', true)
                ->etc());
    }

    public function test_view_only_member_cannot_edit_from_the_test_page(): void
    {
        [$suite, $test] = $this->suiteWithTest();

        $member = User::factory()->create();
        $suite->members()->attach($member, ['can_view' => true, 'can_edit' => false, 'can_delete' => false, 'can_run' => false]);

        $this->actingAs($member)
            ->get("/sorify/suites/{$suite->id}/tests/{$test->id}")
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('can.edit', false)
                ->etc());
    }

    /**
     * @return array{0: TestSuite, 1: Test}
     */
    private function suiteWithTest(): array
    {
        $owner = User::factory()->create();

        $suite = TestSuite::create(['name' => 'Suite', 'created_by' => $owner->id]);

        $test = $suite->tests()->create([
            'name' => 'Test',
            'playwright_code' => 'await page.setContent("<h1>ok</h1>");',
            'status' => 'active',
        ]);

        return [$suite, $test];
    }
}
