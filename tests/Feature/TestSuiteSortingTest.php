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
            'name'     => 'Suite',
            'base_url' => 'https://example.com',
        ]);
    }

    private function test(TestSuite $suite, array $attributes = []): Test
    {
        return Test::create(array_merge([
            'test_suite_id' => $suite->id,
            'name'          => 'Test '.($suite->tests()->count() + 1),
            'status'        => 'active',
        ], $attributes));
    }

    private function nameList(array $data): array
    {
        return array_map(fn ($t) => $t['name'], $data);
    }

    /**
     * Fetch the test list data returned for the suite show page, optionally
     * applying sort + direction query params.
     */
    private function testList(TestSuite $suite, array $query = []): array
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

        $names = $this->nameList($this->testList($suite));

        $this->assertSame([$newer->name, $older->name], $names);
    }

    public function test_sort_by_specific_status_prioritizes_that_status(): void
    {
        $suite = $this->suite();
        $run   = TestRun::create(['test_suite_id' => $suite->id, 'status' => 'completed']);

        $error   = $this->test($suite, ['name' => 'Erroring']);
        $passed  = $this->test($suite, ['name' => 'Passing']);
        $running = $this->test($suite, ['name' => 'Running']);

        $this->makeResult($run, $passed, 'passed');
        $this->makeResult($run, $error, 'error');
        $this->makeResult($run, $running, 'running');

        // Sorting by "running" pushes the running test to the top, then the rest.
        $names = $this->nameList($this->testList($suite, ['sort' => 'running']));
        $this->assertSame($running->name, $names[0]);

        // Sorting by "passed" pushes the passed test to the top.
        $names = $this->nameList($this->testList($suite, ['sort' => 'passed']));
        $this->assertSame($passed->name, $names[0]);
    }

    public function test_sort_by_status_prioritizes_active_or_disabled(): void
    {
        $suite = $this->suite();
        $active   = $this->test($suite, ['name' => 'Active One', 'status' => 'active']);
        $disabled = $this->test($suite, ['name' => 'Disabled One', 'status' => 'disabled']);

        // status_active pushes active tests to the top.
        $names = $this->nameList($this->testList($suite, ['sort' => 'status_active']));
        $this->assertSame([$active->name, $disabled->name], $names);

        // status_disabled pushes disabled tests to the top.
        $names = $this->nameList($this->testList($suite, ['sort' => 'status_disabled']));
        $this->assertSame([$disabled->name, $active->name], $names);
    }

    public function test_sort_by_duration_long_and_short(): void
    {
        $suite = $this->suite();
        $run = TestRun::create(['test_suite_id' => $suite->id, 'status' => 'completed']);

        $slow = $this->test($suite, ['name' => 'Slow']);
        $fast = $this->test($suite, ['name' => 'Fast']);
        $unmeasured = $this->test($suite, ['name' => 'Unmeasured']);

        $this->makeResult($run, $slow, 'passed', 5000);
        $this->makeResult($run, $fast, 'passed', 1000);

        // Longest first.
        $names = $this->nameList($this->testList($suite, ['sort' => 'duration_long']));
        $this->assertSame([$slow->name, $fast->name, $unmeasured->name], $names);

        // Shortest first.
        $names = $this->nameList($this->testList($suite, ['sort' => 'duration_short']));
        $this->assertSame([$fast->name, $slow->name, $unmeasured->name], $names);

        // Unmeasured tests (null duration) always sink to the bottom.
        $this->assertSame($unmeasured->name, end($names));
    }

    public function test_invalid_sort_falls_back_to_default(): void
    {
        $suite = $this->suite();
        $newer = $this->test($suite, ['name' => 'Newer', 'last_run_at' => now()]);
        $older = $this->test($suite, ['name' => 'Older', 'last_run_at' => now()->subDay()]);

        $names = $this->nameList($this->testList($suite, ['sort' => 'nope']));

        $this->assertSame([$newer->name, $older->name], $names);
    }

    public function test_sort_by_created_at_newest_and_oldest(): void
    {
        $suite = $this->suite();
        $first  = $this->test($suite, ['name' => 'Created First', 'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)]);
        $second = $this->test($suite, ['name' => 'Created Second', 'created_at' => now()->subDay(), 'updated_at' => now()->subDay()]);
        $third  = $this->test($suite, ['name' => 'Created Third', 'created_at' => now(), 'updated_at' => now()]);

        // Newest created first.
        $names = $this->nameList($this->testList($suite, ['sort' => 'created_newest']));
        $this->assertSame([$third->name, $second->name, $first->name], $names);

        // Oldest created first.
        $names = $this->nameList($this->testList($suite, ['sort' => 'created_oldest']));
        $this->assertSame([$first->name, $second->name, $third->name], $names);
    }

    public function test_sort_by_updated_at_newest_and_oldest(): void
    {
        $suite = $this->suite();
        $stale    = $this->test($suite, ['name' => 'Updated Long Ago', 'created_at' => now()->subDays(3), 'updated_at' => now()->subDays(3)]);
        $recent   = $this->test($suite, ['name' => 'Updated Recently', 'created_at' => now()->subDays(3), 'updated_at' => now()->subHour()]);
        $latest   = $this->test($suite, ['name' => 'Updated Just Now', 'created_at' => now()->subDays(3), 'updated_at' => now()]);

        // Newest updated first.
        $names = $this->nameList($this->testList($suite, ['sort' => 'updated_newest']));
        $this->assertSame([$latest->name, $recent->name, $stale->name], $names);

        // Oldest updated first.
        $names = $this->nameList($this->testList($suite, ['sort' => 'updated_oldest']));
        $this->assertSame([$stale->name, $recent->name, $latest->name], $names);
    }

    public function test_status_filter_keeps_only_matching_statuses(): void
    {
        $suite = $this->suite();
        $run   = TestRun::create(['test_suite_id' => $suite->id, 'status' => 'completed']);

        $error   = $this->test($suite, ['name' => 'Erroring']);
        $passed  = $this->test($suite, ['name' => 'Passing']);
        $running = $this->test($suite, ['name' => 'Running']);

        $this->makeResult($run, $passed, 'passed');
        $this->makeResult($run, $error, 'error');
        $this->makeResult($run, $running, 'running');

        $names = $this->nameList($this->testList($suite, ['status' => ['passed']]));
        $this->assertSame([$passed->name], $names);

        $names = $this->nameList($this->testList($suite, ['status' => ['passed', 'running']]));
        $this->assertEqualsCanonicalizing([$passed->name, $running->name], $names);

        // No status filter returns everything.
        $names = $this->nameList($this->testList($suite));
        $this->assertCount(3, $names);
    }

    private function makeResult(TestRun $run, Test $test, string $status, ?int $duration = null): TestResult
    {
        return TestResult::create([
            'test_run_id'  => $run->id,
            'test_id'      => $test->id,
            'status'       => $status,
            'duration_ms'  => $duration,
            'started_at'   => now(),
            'completed_at' => now(),
        ]);
    }
}