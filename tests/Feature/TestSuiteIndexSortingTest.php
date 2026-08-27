<?php

namespace Tests\Feature;

use App\Models\Test;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class TestSuiteIndexSortingTest extends TestCase
{
    use RefreshDatabase;

    private function suite(array $attributes = []): TestSuite
    {
        return TestSuite::create(array_merge([
            'name' => 'Suite',
            'base_url' => 'https://example.com',
        ], $attributes));
    }

    private function addMember(TestSuite $suite, User $user): void
    {
        $suite->members()->attach($user->id, ['can_view' => true]);
    }

    private function addCompletedRun(TestSuite $suite, array $attrs = []): TestRun
    {
        return TestRun::create(array_merge([
            'test_suite_id' => $suite->id,
            'status' => 'completed',
            'total_tests' => 10,
            'passed_count' => 10,
            'failed_count' => 0,
        ], $attrs));
    }

    /**
     * Fetch the suites index data, optionally applying sort + direction query params.
     */
    private function suite_list(array $query = []): array
    {
        $admin = User::factory()->admin()->create();

        $suites = [];

        $this->actingAs($admin)
            ->get(route('suites.index', $query))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$suites) {
                $page->component('TestSuites/Index');
                $suites = $page->toArray()['props']['suites']['data'];
            });

        return $suites;
    }

    private function ids(array $data): array
    {
        return array_map(fn ($s) => $s['id'], $data);
    }

    public function test_default_sort_is_created_descending(): void
    {
        $older = $this->suite(['name' => 'Older', 'created_at' => now()->subDay()]);
        $newer = $this->suite(['name' => 'Newer', 'created_at' => now()]);

        $this->assertSame([$newer->id, $older->id], $this->ids($this->suite_list()));
    }

    public function test_sort_by_name_asc_and_desc(): void
    {
        $zSuite = $this->suite(['name' => 'Zulu']);
        $aSuite = $this->suite(['name' => 'Alpha']);

        $this->assertSame([$aSuite->id, $zSuite->id], $this->ids($this->suite_list(['sort' => 'name', 'sort_dir' => 'asc'])));
        $this->assertSame([$zSuite->id, $aSuite->id], $this->ids($this->suite_list(['sort' => 'name', 'sort_dir' => 'desc'])));
    }

    public function test_sort_by_users_count(): void
    {
        $one = $this->suite(['name' => 'One Member']);
        $two = $this->suite(['name' => 'Two Members']);

        $this->addMember($one, User::factory()->create(['name' => 'A']));
        $this->addMember($two, User::factory()->create(['name' => 'B']));
        $this->addMember($two, User::factory()->create(['name' => 'C']));

        $this->assertSame([$two->id, $one->id], $this->ids($this->suite_list(['sort' => 'users', 'sort_dir' => 'desc'])));
        $this->assertSame([$one->id, $two->id], $this->ids($this->suite_list(['sort' => 'users', 'sort_dir' => 'asc'])));
    }

    public function test_sort_by_tests_count(): void
    {
        $more = $this->suite(['name' => 'More Tests']);
        $less = $this->suite(['name' => 'Fewer Tests']);

        for ($i = 0; $i < 3; $i++) {
            $t = new Test;
            $t->forceFill(['test_suite_id' => $more->id, 'name' => "T$i", 'status' => 'active'])->save();
        }
        $t = new Test;
        $t->forceFill(['test_suite_id' => $less->id, 'name' => 'Only', 'status' => 'active'])->save();

        $this->assertSame([$more->id, $less->id], $this->ids($this->suite_list(['sort' => 'tests', 'sort_dir' => 'desc'])));
        $this->assertSame([$less->id, $more->id], $this->ids($this->suite_list(['sort' => 'tests', 'sort_dir' => 'asc'])));
    }

    public function test_sort_by_runs_count(): void
    {
        $more = $this->suite(['name' => 'More Runs']);
        $less = $this->suite(['name' => 'Fewer Runs']);

        $this->addCompletedRun($more);
        $this->addCompletedRun($more);
        $this->addCompletedRun($less);

        $this->assertSame([$more->id, $less->id], $this->ids($this->suite_list(['sort' => 'runs', 'sort_dir' => 'desc'])));
        $this->assertSame([$less->id, $more->id], $this->ids($this->suite_list(['sort' => 'runs', 'sort_dir' => 'asc'])));
    }

    public function test_sort_by_pass_rate(): void
    {
        $low = $this->suite(['name' => 'Low Pass']);
        $high = $this->suite(['name' => 'High Pass']);

        // High: 8/10 passed = 80%
        $this->addCompletedRun($high, ['total_tests' => 10, 'passed_count' => 8, 'failed_count' => 2]);
        // Low: 3/10 passed = 30%
        $this->addCompletedRun($low, ['total_tests' => 10, 'passed_count' => 3, 'failed_count' => 7]);

        $this->assertSame([$high->id, $low->id], $this->ids($this->suite_list(['sort' => 'pass_rate', 'sort_dir' => 'desc'])));
        $this->assertSame([$low->id, $high->id], $this->ids($this->suite_list(['sort' => 'pass_rate', 'sort_dir' => 'asc'])));
    }

    public function test_sort_by_last_run_asc_and_desc_with_nulls_last(): void
    {
        $neverRun = $this->suite(['name' => 'Never Run', 'created_at' => now()->subDay()]);
        $older = $this->suite(['name' => 'Older Run', 'created_at' => now()->subDay()]);
        $newer = $this->suite(['name' => 'Newer Run', 'created_at' => now()->subDay()]);

        $this->addCompletedRun($older, ['completed_at' => now()->subDay()]);
        $this->addCompletedRun($newer, ['completed_at' => now()]);

        // desc: newest run first; never-run sinks to bottom
        $this->assertSame([$newer->id, $older->id, $neverRun->id], $this->ids($this->suite_list(['sort' => 'last_run', 'sort_dir' => 'desc'])));
        // asc: oldest run first; never-run still sinks to bottom (nulls last)
        $this->assertSame([$older->id, $newer->id, $neverRun->id], $this->ids($this->suite_list(['sort' => 'last_run', 'sort_dir' => 'asc'])));
    }

    public function test_invalid_sort_falls_back_to_default(): void
    {
        $older = $this->suite(['name' => 'Older', 'created_at' => now()->subDay()]);
        $newer = $this->suite(['name' => 'Newer', 'created_at' => now()]);

        $this->assertSame([$newer->id, $older->id], $this->ids($this->suite_list(['sort' => 'nope'])));
    }

    public function test_filters_prop_contains_sort_and_sort_dir(): void
    {
        $this->suite();

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('suites.index', ['sort' => 'name', 'sort_dir' => 'asc']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('filters.sort', 'name')
                ->where('filters.sort_dir', 'asc')
            );
    }

    public function test_sort_works_on_bookmarks_index_too(): void
    {
        $admin = User::factory()->admin()->create();

        $zSuite = $this->suite(['name' => 'Zulu']);
        $aSuite = $this->suite(['name' => 'Alpha']);
        $admin->bookmarkedSuites()->syncWithoutDetaching([$zSuite->id, $aSuite->id]);

        $suites = [];

        $this->actingAs($admin)
            ->get(route('bookmarks.index', ['sort' => 'name', 'sort_dir' => 'asc']))
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use (&$suites) {
                $page->component('Bookmarks/Index');
                $suites = $page->toArray()['props']['suites']['data'];
            });

        $this->assertSame([$aSuite->id, $zSuite->id], $this->ids($suites));
    }
}
