<?php

namespace Tests\Feature;

use App\Models\Screenshot;
use App\Models\Test;
use App\Models\TestResult;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class RunsIndexSortingTest extends TestCase
{
    use RefreshDatabase;

    private function suite(?User $creator = null, string $name = 'Suite'): TestSuite
    {
        return TestSuite::create([
            'name' => $name,
            'base_url' => 'https://example.com',
            'created_by' => $creator?->id,
        ]);
    }

    private function makeRun(TestSuite $suite, array $attributes = []): TestRun
    {
        return TestRun::create(array_merge([
            'test_suite_id' => $suite->id,
            'status' => 'completed',
        ], $attributes));
    }

    private function makeResult(TestRun $run, string $status = 'passed', ?int $duration = null): TestResult
    {
        return TestResult::create([
            'test_run_id' => $run->id,
            'test_id' => Test::create([
                'test_suite_id' => $run->test_suite_id,
                'name' => 'Test '.$run->id.'-'.$status,
                'status' => 'active',
            ])->id,
            'status' => $status,
            'duration_ms' => $duration,
            'started_at' => now(),
            'completed_at' => now(),
        ]);
    }

    private function attachScreenshot(TestResult $result): Screenshot
    {
        return Screenshot::create([
            'test_result_id' => $result->id,
            'filename' => 'shot.png',
            'path' => '/tmp/shot.png',
        ]);
    }

    /**
     * Fetch the runs index data, optionally applying sort + direction query params.
     */
    private function run_list(array $query = []): array
    {
        $admin = User::factory()->admin()->create();

        $runs = [];

        $this->actingAs($admin)
            ->get(route('runs.index', $query))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$runs) {
                $page->component('Runs/Index');
                $runs = $page->toArray()['props']['runs']['data'];
            });

        return $runs;
    }

    private function ids(array $data): array
    {
        return array_map(fn ($r) => $r['id'], $data);
    }

    public function test_default_sort_is_run_date_descending(): void
    {
        $suite = $this->suite();
        $older = $this->makeRun($suite, ['created_at' => now()->subDay()]);
        $newer = $this->makeRun($suite, ['created_at' => now()]);

        $this->assertSame([$newer->id, $older->id], $this->ids($this->run_list()));
    }

    public function test_sort_run_date_asc_and_desc(): void
    {
        $suite = $this->suite();
        $older = $this->makeRun($suite, ['created_at' => now()->subDay()]);
        $newer = $this->makeRun($suite, ['created_at' => now()]);

        $this->assertSame([$newer->id, $older->id], $this->ids($this->run_list(['sort' => 'run_date', 'sort_dir' => 'desc'])));
        $this->assertSame([$older->id, $newer->id], $this->ids($this->run_list(['sort' => 'run_date', 'sort_dir' => 'asc'])));
    }

    public function test_sort_by_status(): void
    {
        $suite = $this->suite();
        $completed = $this->makeRun($suite, ['status' => 'completed']);
        $failed = $this->makeRun($suite, ['status' => 'failed']);

        // asc: completed (c) before failed (f)
        $this->assertSame([$completed->id, $failed->id], $this->ids($this->run_list(['sort' => 'status', 'sort_dir' => 'asc'])));
        // desc: failed before completed
        $this->assertSame([$failed->id, $completed->id], $this->ids($this->run_list(['sort' => 'status', 'sort_dir' => 'desc'])));
    }

    public function test_sort_by_passed_count(): void
    {
        $suite = $this->suite();
        $more = $this->makeRun($suite, ['passed_count' => 10, 'total_tests' => 10]);
        $less = $this->makeRun($suite, ['passed_count' => 2, 'total_tests' => 10]);

        $this->assertSame([$more->id, $less->id], $this->ids($this->run_list(['sort' => 'passed', 'sort_dir' => 'desc'])));
        $this->assertSame([$less->id, $more->id], $this->ids($this->run_list(['sort' => 'passed', 'sort_dir' => 'asc'])));
    }

    public function test_sort_by_duration_asc_and_desc_with_nulls_last(): void
    {
        $suite = $this->suite();
        $slow = $this->makeRun($suite, ['duration_ms' => 5000]);
        $fast = $this->makeRun($suite, ['duration_ms' => 1000]);
        $unmeasured = $this->makeRun($suite, ['duration_ms' => null]);

        $this->assertSame([$slow->id, $fast->id, $unmeasured->id], $this->ids($this->run_list(['sort' => 'duration', 'sort_dir' => 'desc'])));
        $this->assertSame([$fast->id, $slow->id, $unmeasured->id], $this->ids($this->run_list(['sort' => 'duration', 'sort_dir' => 'asc'])));
    }

    public function test_sort_by_screenshots_count(): void
    {
        $suite = $this->suite();
        $withShots = $this->makeRun($suite);
        $without = $this->makeRun($suite);

        $result = $this->makeResult($run = $withShots);
        $this->attachScreenshot($result);
        $this->attachScreenshot($result);

        // desc: runs with screenshots first
        $this->assertSame([$withShots->id, $without->id], $this->ids($this->run_list(['sort' => 'screenshots', 'sort_dir' => 'desc'])));
        // asc: runs without screenshots first
        $this->assertSame([$without->id, $withShots->id], $this->ids($this->run_list(['sort' => 'screenshots', 'sort_dir' => 'asc'])));
    }

    public function test_sort_by_suite_name(): void
    {
        $zSuite = $this->suite(null, 'Zulu Suite');
        $aSuite = $this->suite(null, 'Alpha Suite');
        $zRun = $this->makeRun($zSuite);
        $aRun = $this->makeRun($aSuite);

        $this->assertSame([$aRun->id, $zRun->id], $this->ids($this->run_list(['sort' => 'suite', 'sort_dir' => 'asc'])));
        $this->assertSame([$zRun->id, $aRun->id], $this->ids($this->run_list(['sort' => 'suite', 'sort_dir' => 'desc'])));
    }

    public function test_sort_by_created_by_user_name(): void
    {
        $zUser = User::factory()->create(['name' => 'Zara']);
        $aUser = User::factory()->create(['name' => 'Anna']);
        $zSuite = $this->suite($zUser, 'Z Suite');
        $aSuite = $this->suite($aUser, 'A Suite');
        $zRun = $this->makeRun($zSuite);
        $aRun = $this->makeRun($aSuite);

        $this->assertSame([$aRun->id, $zRun->id], $this->ids($this->run_list(['sort' => 'created_by', 'sort_dir' => 'asc'])));
        $this->assertSame([$zRun->id, $aRun->id], $this->ids($this->run_list(['sort' => 'created_by', 'sort_dir' => 'desc'])));
    }

    public function test_sort_by_ran_by_user_name(): void
    {
        $suite = $this->suite();
        $zUser = User::factory()->create(['name' => 'Zara']);
        $aUser = User::factory()->create(['name' => 'Anna']);
        $zRun = $this->makeRun($suite, ['triggered_by_user_id' => $zUser->id, 'triggered_by' => 'manual']);
        $aRun = $this->makeRun($suite, ['triggered_by_user_id' => $aUser->id, 'triggered_by' => 'manual']);

        $this->assertSame([$aRun->id, $zRun->id], $this->ids($this->run_list(['sort' => 'ran_by', 'sort_dir' => 'asc'])));
        $this->assertSame([$zRun->id, $aRun->id], $this->ids($this->run_list(['sort' => 'ran_by', 'sort_dir' => 'desc'])));
    }

    public function test_invalid_sort_falls_back_to_default(): void
    {
        $suite = $this->suite();
        $older = $this->makeRun($suite, ['created_at' => now()->subDay()]);
        $newer = $this->makeRun($suite, ['created_at' => now()]);

        $this->assertSame([$newer->id, $older->id], $this->ids($this->run_list(['sort' => 'nope'])));
    }

    public function test_filters_prop_contains_sort_and_sort_dir(): void
    {
        $suite = $this->suite();
        $this->makeRun($suite);

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('runs.index', ['sort' => 'duration', 'sort_dir' => 'asc']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.sort', 'duration')
                ->where('filters.sort_dir', 'asc')
            );
    }

    public function test_sort_is_preserved_across_pagination(): void
    {
        $suite = $this->suite();
        // Create more than 1 page (default per_page = 30, so create 35).
        for ($i = 0; $i < 35; $i++) {
            $this->makeRun($suite, ['passed_count' => $i, 'total_tests' => 100, 'created_at' => now()->subMinutes(35 - $i)]);
        }

        // Page 2 with sort=passed asc should still be ascending overall.
        $page2 = $this->run_list(['sort' => 'passed', 'sort_dir' => 'asc', 'per_page' => 30, 'page' => 2]);
        $page1 = $this->run_list(['sort' => 'passed', 'sort_dir' => 'asc', 'per_page' => 30, 'page' => 1]);

        // Last item of page1 should be < first item of page2 (since asc).
        $this->assertLessThan($page2[0]['passed_count'], end($page1)['passed_count']);
    }
}
