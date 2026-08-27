<?php

namespace App\Services;

use App\Events\TestRunCompleted;
use App\Exceptions\RunRateLimitExceededException;
use App\Exceptions\WebhookRunInProgressException;
use App\Jobs\RunSingleTestJob;
use App\Models\Test;
use App\Models\TestRun;
use App\Models\TestSuite;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

class TestRunService
{
    /**
     * @throws RunRateLimitExceededException
     * @throws WebhookRunInProgressException
     */
    public function triggerRun(TestSuite $suite, ?array $testIds, string $triggeredBy, ?int $triggeredByUserId = null, ?string $ciIp = null, ?string $ciUserAgent = null): TestRun
    {
        if ($triggeredBy !== 'schedule') {
            $this->assertNotRateLimited($suite, $triggeredBy, $triggeredByUserId);
        }

        // CI webhooks enforce one-at-a-time concurrency per suite: while a
        // webhook-triggered run is pending or running, further webhook requests
        // are rejected with 409 Conflict. Manual, MCP and scheduled triggers
        // are NOT subject to this check — they may queue concurrent runs.
        // The suite row is locked (select-for-update) so two concurrent webhook
        // requests cannot both pass the check and create a run.
        $run = $triggeredBy === 'ci'
            ? $this->createCiRun($suite, $testIds, $triggeredByUserId, $ciIp, $ciUserAgent)
            : $this->createRun($suite, $testIds, $triggeredBy, $triggeredByUserId);

        $query = $suite->activeTests();
        if ($testIds) {
            $query->whereIn('id', $testIds);
        }
        $tests = $query->get();

        $run->update([
            'total_tests' => $tests->count(),
            'started_at' => now(),
            'status' => 'running',
        ]);

        if ($tests->isEmpty()) {
            $this->finalizeRun($run);

            return $run;
        }

        Bus::batch($tests->map(fn (Test $test) => new RunSingleTestJob($run, $test))->all())
            ->onQueue('sorify')
            ->finally(fn () => app(self::class)->finalizeRun($run))
            ->dispatch();

        return $run;
    }

    public function finalizeRun(TestRun $run): void
    {
        $run->refresh();

        // Guard against double-finalization: the batch finally callback can be
        // invoked from recordFailedJob (when retry_after re-releases a
        // long-running job and another worker fails it) before the original
        // worker finishes. If the run was already finalized, bail out.
        if ($run->completed_at !== null) {
            return;
        }

        $hasFailures = $run->failed_count > 0 || $run->error_count > 0;

        $run->update([
            'duration_ms' => $run->started_at?->diffInMilliseconds(now()),
            'completed_at' => now(),
            'status' => $run->status === 'cancelled' ? 'cancelled' : ($hasFailures ? 'failed' : 'completed'),
        ]);

        TestRunCompleted::dispatch($run);
    }

    /**
     * @throws RunRateLimitExceededException
     */
    private function assertNotRateLimited(TestSuite $suite, string $triggeredBy, ?int $triggeredByUserId): void
    {
        $identity = $triggeredByUserId !== null ? "user:{$triggeredByUserId}" : $triggeredBy;
        $key = "run-trigger:suite:{$suite->id}:{$identity}";

        $maxAttempts = (int) config('sorify.run_trigger_rate_limit.max_attempts');
        $decaySeconds = (int) config('sorify.run_trigger_rate_limit.decay_seconds');

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            throw new RunRateLimitExceededException(RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, $decaySeconds);
    }

    /**
     * @throws WebhookRunInProgressException
     */
    private function createCiRun(TestSuite $suite, ?array $testIds, ?int $triggeredByUserId, ?string $ciIp, ?string $ciUserAgent): TestRun
    {
        return DB::transaction(function () use ($suite, $testIds, $triggeredByUserId, $ciIp, $ciUserAgent) {
            // Lock the suite row so two simultaneous webhook requests serialize
            // and cannot both pass the in-progress check. Works on MySQL and
            // SQLite (SQLite serializes all writes anyway).
            TestSuite::whereKey($suite->id)->lockForUpdate()->first();

            $existing = TestRun::where('test_suite_id', $suite->id)
                ->where('triggered_by', 'ci')
                ->whereIn('status', ['pending', 'running'])
                ->latest('id')
                ->first();

            if ($existing !== null) {
                throw new WebhookRunInProgressException($existing);
            }

            return $this->createRun($suite, $testIds, 'ci', $triggeredByUserId, $ciIp, $ciUserAgent);
        });
    }

    private function createRun(TestSuite $suite, ?array $testIds, string $triggeredBy, ?int $triggeredByUserId, ?string $ciIp = null, ?string $ciUserAgent = null): TestRun
    {
        return $suite->testRuns()->create([
            'triggered_by' => $triggeredBy,
            'triggered_by_user_id' => $triggeredByUserId,
            'ci_ip' => $ciIp,
            'ci_user_agent' => $ciUserAgent,
            'status' => 'pending',
        ]);
    }

    public function cancel(TestRun $run): TestRun
    {
        if (in_array($run->status, ['pending', 'running'], true)) {
            $run->update(['status' => 'cancelled', 'completed_at' => now()]);
        }

        return $run->refresh();
    }

    public function statusPayload(TestRun $run): array
    {
        return [
            'status' => $run->status,
            'passed_count' => $run->passed_count,
            'failed_count' => $run->failed_count,
            'error_count' => $run->error_count,
            'total_tests' => $run->total_tests,
            'duration_ms' => $run->duration_ms,
        ];
    }
}
