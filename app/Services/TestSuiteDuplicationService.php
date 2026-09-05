<?php

namespace App\Services;

use App\Jobs\DuplicateTestSuiteJob;
use App\Models\Test;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TestSuiteDuplicationService
{
    /**
     * Create a clone shell of the source suite (name, settings, proxy rules,
     * membership) marked as `duplication_status = pending`, then dispatch a
     * background job to copy all of its tests.
     *
     * Returns the new suite immediately; the tests land asynchronously.
     */
    public function duplicate(TestSuite $source, User $user, ?string $name = null): TestSuite
    {
        $source->loadMissing('proxyRules', 'variables', 'cookies', 'integrations');

        $clone = DB::transaction(function () use ($source, $user, $name) {
            $clone = TestSuite::create([
                'name' => $this->copyName($name, $source->name),
                'description' => $source->description,
                'base_url' => $source->base_url,
                'playwright_proxy' => $source->playwright_proxy,
                'browser' => $source->browser,
                'headless' => $source->headless,
                'history_retention' => $source->history_retention,
                'timeout_ms' => $source->timeout_ms,
                'max_retries' => $source->max_retries,
                'take_screenshot' => $source->take_screenshot,
                'teams_webhook_url' => $source->teams_webhook_url,
                'teams_webhook_proxy' => $source->teams_webhook_proxy,
                'teams_notify_on_start' => $source->teams_notify_on_start,
                'teams_notify_on_success' => $source->teams_notify_on_success,
                'teams_notify_on_failure' => $source->teams_notify_on_failure,
                'created_by' => $user->id,
                'duplication_status' => 'pending',
                'duplicated_from_suite_id' => $source->id,
            ]);

            foreach ($source->proxyRules as $rule) {
                $clone->proxyRules()->create([
                    'domain' => $rule->domain,
                    'proxy' => $rule->proxy,
                ]);
            }

            foreach ($source->variables as $variable) {
                $clone->variables()->create([
                    'key' => $variable->key,
                    'value' => $variable->value,
                ]);
            }

            foreach ($source->cookies as $cookie) {
                $clone->cookies()->create([
                    'name' => $cookie->name,
                    'value' => $cookie->value,
                    'domain' => $cookie->domain,
                    'path' => $cookie->path,
                    'url' => $cookie->url,
                    'expires' => $cookie->expires,
                    'http_only' => $cookie->http_only,
                    'secure' => $cookie->secure,
                    'same_site' => $cookie->same_site,
                ]);
            }

            foreach ($source->integrations as $integration) {
                $clone->integrations()->create([
                    'type' => $integration->type,
                    'github_app_id' => $integration->github_app_id,
                    'label' => $integration->label,
                    'config' => $integration->config,
                    'enabled' => $integration->enabled,
                    'trigger_before' => $integration->trigger_before,
                    'trigger_after' => $integration->trigger_after,
                ]);
            }

            $clone->members()->attach($user->id, [
                'can_view' => true,
                'can_edit' => true,
                'can_delete' => true,
                'can_run' => true,
            ]);

            return $clone;
        });

        // Re-fetch the source fresh so the queued job doesn't capture a
        // stale model snapshot after the request ends.
        DuplicateTestSuiteJob::dispatch($source->fresh(), $clone);

        return $clone;
    }

    /**
     * Copy every test from the source suite into the target suite.
     * Run in chunks so a suite with thousands of tests won't blow the
     * memory budget; each chunk is its own transaction to keep writes cheap.
     *
     * Only the current playwright_code, name, description, uploader and
     * status are copied — run history and code-version history start fresh
     * on the duplicate, exactly like a newly authored test.
     */
    public function copyTests(TestSuite $source, TestSuite $target): void
    {
        $source->tests()
            ->select(['id', 'name', 'description', 'uploaded_by', 'playwright_code', 'status'])
            ->chunkById(200, function ($tests) use ($target) {
                $rows = $tests->map(fn (Test $t) => [
                    'test_suite_id' => $target->id,
                    'name' => $t->name,
                    'description' => $t->description,
                    'uploaded_by' => $t->uploaded_by,
                    'playwright_code' => $t->playwright_code,
                    'status' => $t->status,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->all();

                DB::table('tests')->insert($rows);
            });
    }

    /**
     * Duplicate a single test into the same (or a different) suite.
     * Synchronous — one row, no need for a job.
     */
    public function duplicateTest(Test $source, TestSuite $target, ?string $name = null): Test
    {
        return $target->tests()->create([
            'name' => $this->copyName($name, $source->name),
            'description' => $source->description,
            'uploaded_by' => $source->uploaded_by,
            'playwright_code' => $source->playwright_code,
            'status' => $source->status,
        ]);
    }

    private function copyName(?string $custom, string $original): string
    {
        if ($custom !== null && $custom !== '') {
            return trim($custom);
        }

        $base = rtrim($original, " \t\n\r\0\x0B");

        // If the original already ends with " (copy)" or " (copy N)", bump the suffix.
        if (preg_match('/^(.+?)\s\(copy(?:\s(\d+))?\)$/u', $base, $m)) {
            $next = isset($m[2]) ? ((int) $m[2] + 1) : 2;

            return "{$m[1]} (copy {$next})";
        }

        // Truncate the original if needed so the suffix fits within 255 chars.
        $suffix = ' (copy)';

        return Str::length($base) + Str::length($suffix) > 255
            ? Str::limit($base, 255 - Str::length($suffix), '').$suffix
            : $base.$suffix;
    }
}
