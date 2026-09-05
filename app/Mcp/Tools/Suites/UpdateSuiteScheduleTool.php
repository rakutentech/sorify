<?php

namespace App\Mcp\Tools\Suites;

use App\Http\Requests\StoreTestSuiteScheduleRequest;
use App\Mcp\Tools\Concerns\AuthorizesSuiteAccess;
use App\Models\TestSuite;
use App\Services\ActivityLogger;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class UpdateSuiteScheduleTool extends Tool
{
    use AuthorizesSuiteAccess;

    protected string $name = 'update_suite_schedule';

    protected string $description = 'Create or update the cron schedule that runs a test suite automatically.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'suite_id' => $schema->integer()->required()->description('The test suite ID.'),
            'cron_expression' => $schema->string()->description('Cron expression for when the suite should run (e.g. "0 * * * *"). Empty string removes the schedule.'),
            'timezone' => $schema->string()->description('Timezone for the schedule (e.g. "UTC", "Asia/Tokyo"). Defaults to UTC.'),
            'is_enabled' => $schema->boolean()->description('Whether the schedule is active. Defaults to true.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $suite = TestSuite::findOrFail($request->validate(['suite_id' => 'required|integer|exists:test_suites,id'])['suite_id']);
        $this->authorizeSuite('edit', $suite);

        $data = $request->validate((new StoreTestSuiteScheduleRequest)->rules());
        $timezone = $data['timezone'] ?? 'UTC';

        if (($data['cron_expression'] ?? '') === '') {
            $suite->schedule()->delete();

            ActivityLogger::log('schedule_updated', Auth::user(), $suite, null, ['action' => 'removed']);

            return Response::structured(['schedule' => null]);
        }

        $schedule = $suite->schedule()->updateOrCreate([], [
            'cron_expression' => $data['cron_expression'],
            'timezone' => $timezone,
            'is_enabled' => $data['is_enabled'] ?? true,
            'created_by' => Auth::id(),
        ]);

        $schedule->update([
            'next_run_at' => $schedule->is_enabled
                ? $schedule->nextRunAfter(Carbon::now($timezone))
                : null,
        ]);

        ActivityLogger::log('schedule_updated', Auth::user(), $suite, null, [
            'action' => 'set',
            'cron_expression' => $schedule->cron_expression,
            'timezone' => $schedule->timezone,
            'is_enabled' => (bool) $schedule->is_enabled,
        ]);

        return Response::structured(['schedule' => $schedule->toArray()]);
    }
}
