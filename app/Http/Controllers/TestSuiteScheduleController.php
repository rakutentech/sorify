<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTestSuiteScheduleRequest;
use App\Models\TestSuite;
use App\Services\ActivityLogger;
use Illuminate\Support\Carbon;

class TestSuiteScheduleController extends Controller
{
    public function update(StoreTestSuiteScheduleRequest $request, TestSuite $suite)
    {
        $this->authorize('manageSchedule', $suite);

        $data = $request->validated();
        $timezone = $data['timezone'] ?? 'UTC';

        if (($data['cron_expression'] ?? '') === '') {
            $suite->schedule()->delete();

            ActivityLogger::log('schedule_updated', $request->user(), $suite, null, ['action' => 'removed']);

            return back();
        }

        $schedule = $suite->schedule()->updateOrCreate([], [
            'cron_expression' => $data['cron_expression'],
            'timezone' => $timezone,
            'is_enabled' => $data['is_enabled'] ?? false,
            'created_by' => $request->user()?->id,
        ]);

        $schedule->update([
            'next_run_at' => $schedule->is_enabled
                ? $schedule->nextRunAfter(Carbon::now($timezone))
                : null,
        ]);

        ActivityLogger::log('schedule_updated', $request->user(), $suite, null, [
            'action' => 'set',
            'cron_expression' => $schedule->cron_expression,
            'timezone' => $schedule->timezone,
            'is_enabled' => (bool) $schedule->is_enabled,
        ]);

        return back();
    }

    public function destroy(TestSuite $suite)
    {
        $this->authorize('manageSchedule', $suite);

        $suite->schedule()->delete();

        ActivityLogger::log('schedule_updated', request()->user(), $suite, null, ['action' => 'removed']);

        return back();
    }
}
