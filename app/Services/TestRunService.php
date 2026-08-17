<?php

namespace App\Services;

use App\Events\TestRunCompleted;
use App\Exceptions\RunRateLimitExceededException;
use App\Jobs\RunSingleTestJob;
use App\Models\Test;
use App\Models\TestRun;
use App\Models\TestSuite;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\RateLimiter;

class TestRunService
{
    /**
     * @throws RunRateLimitExceededException
     */
    public function triggerRun(TestSuite $suite, ?array $testIds, string $triggeredBy, ?int $triggeredByUserId = null): TestRun
    {
        if ($triggeredBy !== 'schedule') {
            $this->assertNotRateLimited($suite, $triggeredBy, $triggeredByUserId);
        }

        $run = $suite->testRuns()->create([
            'triggered_by' => $triggeredBy,
            'triggered_by_user_id' => $triggeredByUserId,
            'status' => 'pending',
        ]);

        $query = $suite->activeTests();
        if ($testIds) {
            $query->whereIn('id', $testIds);
        }
        $tests = $query->get();

        $run->update([
            'total_tests' => $tests->count(),
            'started_at'  => now(),
            'status'      => 'running',
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

        $hasFailures = $run->failed_count > 0 || $run->error_count > 0;

        $run->update([
            'duration_ms'  => $run->started_at?->diffInMilliseconds(now()),
            'completed_at' => now(),
            'status'       => $run->status === 'cancelled' ? 'cancelled' : ($hasFailures ? 'failed' : 'completed'),
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
