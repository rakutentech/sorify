<?php

namespace Tests\Feature;

use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TestSuiteScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_a_schedule_with_a_valid_cron_expression(): void
    {
        $user = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com']);

        $this->actingAs($user)
            ->put("/sorify/suites/{$suite->id}/schedule", [
                'cron_expression' => '0 */6 * * *',
                'timezone' => 'UTC',
                'is_enabled' => true,
            ])
            ->assertRedirect();

        $schedule = $suite->schedule()->first();
        $this->assertNotNull($schedule);
        $this->assertSame('0 */6 * * *', $schedule->cron_expression);
        $this->assertTrue($schedule->is_enabled);
        $this->assertNotNull($schedule->next_run_at);
    }

    public function test_invalid_cron_expression_is_rejected(): void
    {
        $user = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com']);

        $this->actingAs($user)
            ->put("/sorify/suites/{$suite->id}/schedule", [
                'cron_expression' => 'not a cron',
            ])
            ->assertSessionHasErrors('cron_expression');

        $this->assertNull($suite->schedule()->first());
    }

    public function test_user_without_edit_privilege_cannot_manage_schedule(): void
    {
        $user = User::factory()->create(['is_admin' => false]);
        $suite = TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com']);
        $suite->members()->attach($user->id, [
            'can_view' => true, 'can_edit' => false, 'can_delete' => false, 'can_run' => false,
        ]);

        $this->actingAs($user)
            ->put("/sorify/suites/{$suite->id}/schedule", ['cron_expression' => '* * * * *'])
            ->assertForbidden();
    }

    public function test_destroy_removes_the_schedule(): void
    {
        $user = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com']);
        $suite->schedule()->create(['cron_expression' => '* * * * *', 'timezone' => 'UTC', 'is_enabled' => true]);

        $this->actingAs($user)
            ->delete("/sorify/suites/{$suite->id}/schedule")
            ->assertRedirect();

        $this->assertNull($suite->schedule()->first());
    }

    public function test_empty_cron_expression_removes_the_schedule(): void
    {
        $user = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com']);
        $suite->schedule()->create(['cron_expression' => '* * * * *', 'timezone' => 'UTC', 'is_enabled' => true]);

        $this->actingAs($user)
            ->put("/sorify/suites/{$suite->id}/schedule", [
                'cron_expression' => '',
                'timezone' => 'UTC',
                'is_enabled' => true,
            ])
            ->assertRedirect();

        $this->assertNull($suite->schedule()->first());
    }

    public function test_missing_cron_expression_removes_the_schedule(): void
    {
        $user = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com']);
        $suite->schedule()->create(['cron_expression' => '* * * * *', 'timezone' => 'UTC', 'is_enabled' => true]);

        $this->actingAs($user)
            ->put("/sorify/suites/{$suite->id}/schedule", [])
            ->assertRedirect();

        $this->assertNull($suite->schedule()->first());
    }

    public function test_schedule_can_select_which_tests_run(): void
    {
        $user = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com']);
        $first = $suite->tests()->create(['name' => 'First', 'playwright_code' => '// noop', 'status' => 'active']);
        $second = $suite->tests()->create(['name' => 'Second', 'playwright_code' => '// noop', 'status' => 'active']);

        $this->actingAs($user)
            ->put("/sorify/suites/{$suite->id}/schedule", [
                'cron_expression' => '0 */6 * * *',
                'timezone' => 'UTC',
                'is_enabled' => true,
                'test_ids' => [$first->id],
            ])
            ->assertRedirect();

        $schedule = $suite->schedule()->first();
        $this->assertEquals([$first->id], $schedule->tests()->allRelatedIds()->all());
        $this->assertSame([$first->id], $schedule->test_ids);
    }

    public function test_schedule_rejects_test_ids_from_another_suite(): void
    {
        $user = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com']);
        $otherSuite = TestSuite::create(['name' => 'Other', 'base_url' => 'https://example.com']);
        $foreignTest = $otherSuite->tests()->create(['name' => 'Foreign', 'playwright_code' => '// noop', 'status' => 'active']);

        $this->actingAs($user)
            ->put("/sorify/suites/{$suite->id}/schedule", [
                'cron_expression' => '0 */6 * * *',
                'test_ids' => [$foreignTest->id],
            ])
            ->assertSessionHasErrors('test_ids.0');

        $this->assertNull($suite->schedule()->first());
    }

    public function test_empty_test_ids_selection_clears_previous_selection(): void
    {
        $user = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com']);
        $test = $suite->tests()->create(['name' => 'Test', 'playwright_code' => '// noop', 'status' => 'active']);

        $suite->schedule()->create(['cron_expression' => '* * * * *', 'timezone' => 'UTC', 'is_enabled' => true]);
        $suite->schedule()->first()->tests()->sync([$test->id]);

        $this->actingAs($user)
            ->put("/sorify/suites/{$suite->id}/schedule", [
                'cron_expression' => '* * * * *',
                'test_ids' => [],
            ])
            ->assertRedirect();

        $this->assertSame([], $suite->schedule()->first()->test_ids);
    }

    public function test_omitting_test_ids_keeps_previous_selection(): void
    {
        $user = User::factory()->admin()->create();
        $suite = TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com']);
        $test = $suite->tests()->create(['name' => 'Test', 'playwright_code' => '// noop', 'status' => 'active']);

        $suite->schedule()->create(['cron_expression' => '* * * * *', 'timezone' => 'UTC', 'is_enabled' => true]);
        $suite->schedule()->first()->tests()->sync([$test->id]);

        $this->actingAs($user)
            ->put("/sorify/suites/{$suite->id}/schedule", [
                'cron_expression' => '*/5 * * * *',
                'is_enabled' => true,
            ])
            ->assertRedirect();

        $this->assertSame([$test->id], $suite->schedule()->first()->test_ids);
    }

    public function test_command_runs_only_the_selected_tests(): void
    {
        Queue::fake();

        $suite = TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com']);
        $first = $suite->tests()->create(['name' => 'First', 'playwright_code' => '// noop', 'status' => 'active']);
        $second = $suite->tests()->create(['name' => 'Second', 'playwright_code' => '// noop', 'status' => 'active']);
        $suite->tests()->create(['name' => 'Unselected', 'playwright_code' => '// noop', 'status' => 'active']);
        $disabled = $suite->tests()->create(['name' => 'Disabled', 'playwright_code' => '// noop', 'status' => 'disabled']);

        $schedule = $suite->schedule()->create(['cron_expression' => '* * * * *', 'timezone' => 'UTC', 'is_enabled' => true]);
        $schedule->tests()->sync([$first->id, $second->id, $disabled->id]);

        $this->artisan('sorify:run-scheduled-suites')->assertExitCode(0);

        $run = $suite->testRuns()->where('triggered_by', 'schedule')->first();
        $this->assertNotNull($run);
        // Only the selected tests run — the unselected active test is left out
        // and the disabled one is filtered out by the active-test scope.
        $this->assertSame(2, $run->total_tests);
    }

    public function test_command_runs_all_active_tests_without_a_selection(): void
    {
        Queue::fake();

        $suite = TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com']);
        $suite->tests()->create(['name' => 'First', 'playwright_code' => '// noop', 'status' => 'active']);
        $suite->tests()->create(['name' => 'Second', 'playwright_code' => '// noop', 'status' => 'active']);
        $suite->tests()->create(['name' => 'Disabled', 'playwright_code' => '// noop', 'status' => 'disabled']);
        $suite->schedule()->create(['cron_expression' => '* * * * *', 'timezone' => 'UTC', 'is_enabled' => true]);

        $this->artisan('sorify:run-scheduled-suites')->assertExitCode(0);

        $run = $suite->testRuns()->where('triggered_by', 'schedule')->first();
        $this->assertSame(2, $run->total_tests);
    }

    public function test_command_triggers_a_run_only_for_due_and_enabled_schedules(): void
    {
        Queue::fake();

        $dueSuite = TestSuite::create(['name' => 'Due', 'base_url' => 'https://example.com']);
        $dueSuite->schedule()->create(['cron_expression' => '* * * * *', 'timezone' => 'UTC', 'is_enabled' => true]);

        $notDueSuite = TestSuite::create(['name' => 'NotDue', 'base_url' => 'https://example.com']);
        $notDueSuite->schedule()->create(['cron_expression' => '0 0 1 1 *', 'timezone' => 'UTC', 'is_enabled' => true]);

        $disabledSuite = TestSuite::create(['name' => 'Disabled', 'base_url' => 'https://example.com']);
        $disabledSuite->schedule()->create(['cron_expression' => '* * * * *', 'timezone' => 'UTC', 'is_enabled' => false]);

        $this->artisan('sorify:run-scheduled-suites')->assertExitCode(0);

        $this->assertSame(1, $dueSuite->testRuns()->where('triggered_by', 'schedule')->count());
        $this->assertSame(0, $notDueSuite->testRuns()->count());
        $this->assertSame(0, $disabledSuite->testRuns()->count());

        // The due suite has no tests, so its run completes synchronously without queuing anything.
        Queue::assertNothingPushed();
        $this->assertSame('completed', $dueSuite->testRuns()->where('triggered_by', 'schedule')->first()->status);

        $this->assertNotNull($dueSuite->schedule()->first()->last_run_at);
    }
}
