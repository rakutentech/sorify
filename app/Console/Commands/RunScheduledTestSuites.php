<?php

namespace App\Console\Commands;

use App\Models\TestSuiteSchedule;
use App\Services\TestRunService;
use Cron\CronExpression;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class RunScheduledTestSuites extends Command
{
    protected $signature = 'sorify:run-scheduled-suites';

    protected $description = 'Trigger a run for every test suite whose cron schedule is due';

    public function handle(TestRunService $runs): int
    {
        TestSuiteSchedule::where('is_enabled', true)
            ->with('testSuite')
            ->get()
            ->each(function (TestSuiteSchedule $schedule) use ($runs) {
                if (!$schedule->testSuite) {
                    return;
                }

                $timezone = $schedule->timezone ?: 'UTC';
                $now = Carbon::now($timezone);
                $cron = new CronExpression($schedule->cron_expression);

                if (!$cron->isDue($now)) {
                    return;
                }

                $runs->triggerRun($schedule->testSuite, null, 'schedule');

                $schedule->update([
                    'last_run_at' => now(),
                    'next_run_at' => $cron->getNextRunDate($now, 0, false, $timezone),
                ]);
            });

        return self::SUCCESS;
    }
}
