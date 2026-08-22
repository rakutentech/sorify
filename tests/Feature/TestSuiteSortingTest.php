<?php

namespace Tests\Feature;

use App\Models\Test;
use App\Models\TestResult;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class TestSuiteSortingTest extends TestCase
{
    use RefreshDatabase;

    private function suite(): TestSuite
    {
        return TestSuite::create([
            'name' => 'Suite',
            'base_url' => 'https://example.com',
        ]);
    }

    private function test(TestSuite $suite, array $attributes = []): Test
    {
        $test = new Test;
        $test->forceFill(array_merge([
            'test_suite_id' => $suite->id,
            'name' => 'Test '.($suite->tests()->count() + 1),
            'status' => 'active',
        ], $attributes))->save();

        return $test;
    }

    private function name_list(array $data): array
    {
        return array_map(fn ($t) => $t['name'], $data);
    }

    /**
     * Fetch the test list data returned for the suite show page, optionally
     * applying sort + direction query params.
     */
    private function test_list(TestSuite $suite, array $query = []): array
    {
        $admin = User::factory()->admin()->create();

        $tests = [];

        $this->actingAs($admin)
            ->get(route('suites.show', ['suite' => $suite, ...$query]))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$tests) {
                $page->component('TestSuites/Show');
                $tests = $page->toArray()['props']['tests']['data'];
            });

        return $tests;
    }

    public function test_default_sort_is_latest_run_descending(): void
    {
        $suite = $this->suite();
        $older = $this->test($suite, ['name' => 'Alpha Lower', 'last_run_at' => now()->subHours(2)]);
        $newer = $this->test($suite, ['name' => 'Zulu Fresh', 'last_run_at' => now()]);

        $names = $this->name_list($this->test_list($suite));

        $this->assertSame([$newer->name, $older->name], $names);
    }

    public function test_sort_run_date_asc_and_desc(): void
    {
        $suite = $this->suite();
        $older = $this->test($suite, ['name' => 'Older', 'last_run_at' => now()->subDay()]);
        $newer = $this->test($suite, ['name' => 'Newer', 'last_run_at' => now()]);

        // Desc (default): newest first.
        $names = $this->name_list($this->test_list($suite, ['sort' => 'run_date', 'sort_dir' => 'desc']));
        $this->assertSame([$newer->name, $older->name], $names);

        // Asc: oldest first.
        $names = $this->name_list($this->test_list($suite, ['sort' => 'run_date', 'sort_dir' => 'asc']));
        $this->assertSame([$older->name, $newer->name], $names);
    }

    public function test_sort_by_specific_status_prioritizes_that_status(): void
    {
        $suite = $this->suite();
        $run = TestRun::create(['test_suite_id' => $suite->id, 'status' => 'completed']);

        $error = $this->test($suite, ['name' => 'Erroring']);
        $passed = $this->test($suite, ['name' => 'Passing']);
        $running = $this->test($suite, ['name' => 'Running']);

        $this->makeResult($run, $passed, 'passed');
        $this->makeResult($run, $error, 'error');
        $this->makeResult($run, $running, 'running');

        // Sorting by "running" pushes the running test to the top, then the rest.
        $names = $this->name_list($this->test_list($suite, ['sort' => 'running']));
        $this->assertSame($running->name, $names[0]);

        // Sorting by "passed" pushes the passed test to the top.
        $names = $this->name_list($this->test_list($suite, ['sort' => 'passed']));
        $this->assertSame($passed->name, $names[0]);
    }

    public function test_sort_by_status_prioritizes_active_or_disabled(): void
    {
        $suite = $this->suite();
        $active = $this->test($suite, ['name' => 'Active One', 'status' => 'active']);
        $disabled = $this->test($suite, ['name' => 'Disabled One', 'status' => 'disabled']);

        // asc: active first.
        $names = $this->name_list($this->test_list($suite, ['sort' => 'status', 'sort_dir' => 'asc']));
        $this->assertSame([$active->name, $disabled->name], $names);

        // desc: disabled first.
        $names = $this->name_list($this->test_list($suite, ['sort' => 'status', 'sort_dir' => 'desc']));
        $this->assertSame([$disabled->name, $active->name], $names);
    }

    public function test_sort_by_duration_asc_and_desc(): void
    {
        $suite = $this->suite();
        $run = TestRun::create(['test_suite_id' => $suite->id, 'status' => 'completed']);

        $slow = $this->test($suite, ['name' => 'Slow']);
        $fast = $this->test($suite, ['name' => 'Fast']);
        $unmeasured = $this->test($suite, ['name' => 'Unmeasured']);

        $this->makeResult($run, $slow, 'passed', 5000);
        $this->makeResult($run, $fast, 'passed', 1000);

        // Desc: longest first.
        $names = $this->name_list($this->test_list($suite, ['sort' => 'duration', 'sort_dir' => 'desc']));
        $this->assertSame([$slow->name, $fast->name, $unmeasured->name], $names);

        // Asc: shortest first.
        $names = $this->name_list($this->test_list($suite, ['sort' => 'duration', 'sort_dir' => 'asc']));
        $this->assertSame([$fast->name, $slow->name, $unmeasured->name], $names);

        // Unmeasured tests (null duration) always sink to the bottom.
        $this->assertSame($unmeasured->name, end($names));
    }

    public function test_invalid_sort_falls_back_to_default(): void
    {
        $suite = $this->suite();
        $newer = $this->test($suite, ['name' => 'Newer', 'last_run_at' => now()]);
        $older = $this->test($suite, ['name' => 'Older', 'last_run_at' => now()->subDay()]);

        $names = $this->name_list($this->test_list($suite, ['sort' => 'nope']));

        $this->assertSame([$newer->name, $older->name], $names);
    }

    public function test_legacy_sort_values_still_work(): void
    {
        $suite = $this->suite();
        $run = TestRun::create(['test_suite_id' => $suite->id, 'status' => 'completed']);

        $slow = $this->test($suite, ['name' => 'Slow']);
        $fast = $this->test($suite, ['name' => 'Fast']);
        $this->makeResult($run, $slow, 'passed', 5000);
        $this->makeResult($run, $fast, 'passed', 1000);

        // Legacy "duration_long" → duration desc.
        $names = $this->name_list($this->test_list($suite, ['sort' => 'duration_long']));
        $this->assertSame('Slow', $names[0]);

        // Legacy "duration_short" → duration asc.
        $names = $this->name_list($this->test_list($suite, ['sort' => 'duration_short']));
        $this->assertSame('Fast', $names[0]);

        // Legacy "oldest" → run_date asc.
        $older = $this->test($suite, ['name' => 'Older Run', 'last_run_at' => now()->subDay()]);
        $newer = $this->test($suite, ['name' => 'Newer Run', 'last_run_at' => now()]);
        $names = $this->name_list($this->test_list($suite, ['sort' => 'oldest']));
        $this->assertSame('Older Run', $names[0]);

        // Legacy "created_newest" → created desc.
        $names = $this->name_list($this->test_list($suite, ['sort' => 'created_newest']));
        $first = $this->test_list($suite, ['sort' => 'created', 'sort_dir' => 'desc']);
        $this->assertSame($this->name_list($first), $names);

        // Legacy "status_active" → status asc (active first).
        $active = $this->test($suite, ['name' => 'Legacy Active', 'status' => 'active']);
        $disabled = $this->test($suite, ['name' => 'Legacy Disabled', 'status' => 'disabled']);
        $names = $this->name_list($this->test_list($suite, ['sort' => 'status_active']));
        $this->assertNotSame('Legacy Disabled', $names[0]);
    }

    public function test_sort_by_created_at_asc_and_desc(): void
    {
        $suite = $this->suite();
        $first = $this->test($suite, ['name' => 'Created First', 'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)]);
        $second = $this->test($suite, ['name' => 'Created Second', 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
        $third = $this->test($suite, ['name' => 'Created Third', 'created_at' => now(), 'updated_at' => now()]);

        // Desc: newest created first.
        $names = $this->name_list($this->test_list($suite, ['sort' => 'created', 'sort_dir' => 'desc']));
        $this->assertSame([$third->name, $second->name, $first->name], $names);

        // Asc: oldest created first.
        $names = $this->name_list($this->test_list($suite, ['sort' => 'created', 'sort_dir' => 'asc']));
        $this->assertSame([$first->name, $second->name, $third->name], $names);
    }

    public function test_sort_by_updated_at_asc_and_desc(): void
    {
        $suite = $this->suite();
        $stale = $this->test($suite, ['name' => 'Updated Long Ago', 'created_at' => now()->subDays(3), 'updated_at' => now()->subDays(3)]);
        $recent = $this->test($suite, ['name' => 'Updated Recently', 'created_at' => now()->subDays(3), 'updated_at' => now()->subHour()]);
        $latest = $this->test($suite, ['name' => 'Updated Just Now', 'created_at' => now()->subDays(3), 'updated_at' => now()]);

        // Desc: newest updated first.
        $names = $this->name_list($this->test_list($suite, ['sort' => 'updated', 'sort_dir' => 'desc']));
        $this->assertSame([$latest->name, $recent->name, $stale->name], $names);

        // Asc: oldest updated first.
        $names = $this->name_list($this->test_list($suite, ['sort' => 'updated', 'sort_dir' => 'asc']));
        $this->assertSame([$stale->name, $recent->name, $latest->name], $names);
    }

    public function test_status_filter_keeps_only_matching_statuses(): void
    {
        $suite = $this->suite();
        $run = TestRun::create(['test_suite_id' => $suite->id, 'status' => 'completed']);

        $error = $this->test($suite, ['name' => 'Erroring']);
        $passed = $this->test($suite, ['name' => 'Passing']);
        $running = $this->test($suite, ['name' => 'Running']);

        $this->makeResult($run, $passed, 'passed');
        $this->makeResult($run, $error, 'error');
        $this->makeResult($run, $running, 'running');

        $names = $this->name_list($this->test_list($suite, ['status' => ['passed']]));
        $this->assertSame([$passed->name], $names);

        $names = $this->name_list($this->test_list($suite, ['status' => ['passed', 'running']]));
        $this->assertEqualsCanonicalizing([$passed->name, $running->name], $names);

        // No status filter returns everything.
        $names = $this->name_list($this->test_list($suite));
        $this->assertCount(3, $names);
    }

    private function makeResult(TestRun $run, Test $test, string $status, ?int $duration = null): TestResult
    {
        return TestResult::create([
            'test_run_id' => $run->id,
            'test_id' => $test->id,
            'status' => $status,
            'duration_ms' => $duration,
            'started_at' => now(),
            'completed_at' => now(),
        ]);
    }
}
