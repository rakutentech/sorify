<?php

namespace Tests\Feature;

use App\Models\TestSuite;
use App\Models\User;
use App\Services\TestRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class MultiBrowserRunTest extends TestCase
{
    use RefreshDatabase;

    private function makeSuite(array $attributes = []): TestSuite
    {
        return TestSuite::create(array_merge([
            'name' => 'Suite',
            'base_url' => 'https://example.com',
        ], $attributes));
    }

    public function test_suite_browsers_parses_comma_separated_column(): void
    {
        $this->assertSame(['chromium'], $this->makeSuite()->browsers());
        $this->assertSame(['chromium'], $this->makeSuite(['browser' => null])->browsers());

        $suite = $this->makeSuite(['browser' => 'firefox']);
        $this->assertSame(['firefox'], $suite->browsers());
        $this->assertSame('firefox', $suite->primaryBrowser());

        // Canonical order, deduplicated, unknown engines dropped.
        $suite = $this->makeSuite(['browser' => 'webkit,chromium,webkit,chrome']);
        $this->assertSame(['chromium', 'webkit'], $suite->browsers());
        $this->assertSame('chromium', $suite->primaryBrowser());
    }

    public function test_single_browser_suite_creates_one_run(): void
    {
        Bus::fake();

        $suite = $this->makeSuite(['browser' => 'firefox']);

        $run = app(TestRunService::class)->triggerRun($suite, null, 'schedule');

        $this->assertSame('firefox', $run->browser);
        $this->assertSame(1, $suite->testRuns()->count());
    }

    public function test_multi_browser_suite_fans_out_one_run_per_browser(): void
    {
        Bus::fake();

        $suite = $this->makeSuite(['browser' => 'chromium,firefox,webkit']);

        $run = app(TestRunService::class)->triggerRun($suite, null, 'schedule');

        $runs = $suite->testRuns()->orderBy('id')->get();

        $this->assertSame(3, $runs->count());
        $this->assertSame(
            ['chromium', 'firefox', 'webkit'],
            $runs->pluck('browser')->all(),
        );

        // The trigger's representative run is the first browser.
        $this->assertSame($runs->first()->id, $run->id);
        $this->assertSame('chromium', $run->browser);
    }

    public function test_coverage_does_not_restrict_the_browser_fan_out(): void
    {
        Bus::fake();

        $suite = $this->makeSuite([
            'browser' => 'chromium,firefox',
            'collect_coverage' => true,
        ]);

        $run = app(TestRunService::class)->triggerRun($suite, null, 'schedule');

        // Coverage is Chromium-only, but it is collected on the chromium
        // run only — it never collapses the fan-out.
        $runs = $suite->testRuns()->get();

        $this->assertSame(2, $runs->count());
        $this->assertSame(
            ['chromium', 'firefox'],
            $runs->pluck('browser')->all(),
        );
        $this->assertSame('chromium', $run->browser);
    }

    public function test_dashboard_update_accepts_browser_array_and_comma_separated_string(): void
    {
        $user = User::factory()->create(['is_admin' => true]);
        $suite = $this->makeSuite();

        $this->actingAs($user)
            ->put("/sorify/suites/{$suite->id}", ['browser' => ['chromium', 'webkit']])
            ->assertRedirect();

        $this->assertSame('chromium,webkit', $suite->refresh()->browser);

        $this->actingAs($user)
            ->put("/sorify/suites/{$suite->id}", ['browser' => 'firefox, chromium'])
            ->assertRedirect();

        $this->assertSame('chromium,firefox', $suite->refresh()->browser);

        // Unknown engines are rejected.
        $this->actingAs($user)
            ->put("/sorify/suites/{$suite->id}", ['browser' => 'chrome,opera'])
            ->assertSessionHasErrors('browser');

        $this->assertSame('chromium,firefox', $suite->refresh()->browser);
    }
}
