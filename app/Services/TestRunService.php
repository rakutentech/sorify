<?php

namespace App\Services;

use App\Events\TestRunCompleted;
use App\Events\TestRunStarted;
use App\Exceptions\RunRateLimitExceededException;
use App\Exceptions\WebhookRunInProgressException;
use App\Jobs\RunPreRunIntegrationsJob;
use App\Jobs\RunSingleTestJob;
use App\Models\Test;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

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

        ActivityLogger::log('run_triggered', $this->resolveActor($triggeredByUserId), $suite, $run, [
            'triggered_by' => $triggeredBy,
        ]);

        // Blocking pre-run integrations (e.g. a GitHub Action that must
        // finish first): the run stays `pending` — with a note explaining
        // why — while a queued job runs the integrations, then starts the
        // tests once every one of them succeeds.
        if ($suite->preRunIntegrations()->exists()) {
            $run->update(['status_note' => 'Waiting for pre-run integrations…']);

            RunPreRunIntegrationsJob::dispatch($run, $testIds)->onQueue('sorify');

            return $run;
        }

        return $this->startTests($run, $testIds);
    }

    /**
     * Move a pending run to running and dispatch its test batch. Split out of
     * triggerRun() so the pre-run integration job can call it once the
     * blocking workflows have completed. The pending → running transition is
     * claimed atomically so a cancellation racing this call cannot also
     * start the tests.
     */
    public function startTests(TestRun $run, ?array $testIds = null): TestRun
    {
        $suite = $run->testSuite;

        $query = $suite->activeTests();
        if ($testIds) {
            $query->whereIn('id', $testIds);
        }
        $tests = $query->get();

        $claimed = $run->whereKey($run->id)
            ->where('status', 'pending')
            ->update([
                'total_tests' => $tests->count(),
                'started_at' => now(),
                'status' => 'running',
                'status_note' => null,
            ]);

        if ($claimed === 0) {
            // Cancelled (or already started) while we were setting up.
            return $run->refresh();
        }

        $run->refresh();

        if ($tests->isEmpty()) {
            $this->finalizeRun($run);

            return $run;
        }

        TestRunStarted::dispatch($run);

        Bus::batch($tests->map(fn (Test $test) => new RunSingleTestJob($run, $test))->all())
            ->onQueue('sorify')
            ->finally(fn () => app(self::class)->finalizeRun($run))
            ->dispatch();

        return $run;
    }

    /**
     * Abort a still-pending run without running any test — used when a
     * blocking pre-run integration fails or times out. The completion event
     * still fires so post-run integrations and Teams notifications run.
     */
    public function failRun(TestRun $run, string $note): void
    {
        $updated = $run->whereKey($run->id)
            ->where('status', 'pending')
            ->whereNull('completed_at')
            ->update([
                'status' => 'failed',
                'status_note' => Str::limit($note, 500),
                'completed_at' => now(),
            ]);

        if ($updated > 0) {
            $run = $run->refresh();

            TestRunCompleted::dispatch($run);

            $this->logRunCompleted($run, 'failed');
        }
    }

    public function finalizeRun(TestRun $run): void
    {
        $run->refresh();

        // Guard against double-finalization: the batch finally callback can be
        // invoked from recordFailedJob (when retry_after re-releases a
        // long-running job and another worker fails it) before the original
        // worker finishes. The completed_at null-check alone is not enough —
        // two workers can both read null before either writes. Claim the run
        // atomically: the conditional update only affects a row whose
        // completed_at is still null, so exactly one worker wins and proceeds
        // to dispatch the completion event (and the Teams notification).
        if ($run->completed_at !== null) {
            return;
        }

        $hasFailures = $run->failed_count > 0 || $run->error_count > 0;
        $status = $run->status === 'cancelled' ? 'cancelled' : ($hasFailures ? 'failed' : 'completed');

        $updated = $run->whereKey($run->id)
            ->whereNull('completed_at')
            ->update([
                'duration_ms' => $run->started_at?->diffInMilliseconds(now()),
                'completed_at' => now(),
                'status' => $status,
            ]);

        if ($updated === 0) {
            // Another worker finalized the run between our refresh and the
            // atomic claim — nothing left to do.
            return;
        }

        TestRunCompleted::dispatch($run->refresh());

        $this->logRunCompleted($run, $status);
    }

    private function logRunCompleted(TestRun $run, string $status): void
    {
        ActivityLogger::log('run_completed', $this->resolveActor($run->triggered_by_user_id), $run->testSuite, $run, [
            'status'        => $status,
            'triggered_by'  => $run->triggered_by,
            'total_tests'   => $run->total_tests,
            'passed_count'  => $run->passed_count,
            'failed_count'  => $run->failed_count,
            'error_count'   => $run->error_count,
            'duration_ms'   => $run->duration_ms,
        ]);
    }

    private function resolveActor(?int $userId): ?User
    {
        return $userId !== null ? User::find($userId) : null;
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

    public function cancel(TestRun $run, ?User $cancelledBy = null): TestRun
    {
        if (in_array($run->status, ['pending', 'running'], true)) {
            $run->update(['status' => 'cancelled', 'completed_at' => now()]);

            ActivityLogger::log('run_cancelled', $cancelledBy, $run->testSuite, $run, [
                'triggered_by' => $run->triggered_by,
            ]);
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
