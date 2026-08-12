<?php

namespace App\Services;

use App\Models\TestRun;
use App\Models\TestSuite;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TeamsNotificationService
{
    public function notifyRunCompleted(TestRun $run): void
    {
        $suite = $run->testSuite;

        if (!$suite || !$suite->teams_webhook_url || $run->status === 'cancelled') {
            return;
        }

        $isSuccess = (int) $run->failed_count === 0 && (int) $run->error_count === 0;

        if ($isSuccess && !$suite->teams_notify_on_success) {
            return;
        }

        if (!$isSuccess && !$suite->teams_notify_on_failure) {
            return;
        }

        try {
            Http::timeout(10)->post($suite->teams_webhook_url, $this->buildPayload($suite, $run, $isSuccess));
        } catch (\Throwable $e) {
            Log::warning('Failed to send Teams notification for test run', [
                'suite_id' => $suite->id,
                'run_id'   => $run->id,
                'error'    => $e->getMessage(),
            ]);
        }
    }

    private function buildPayload(TestSuite $suite, TestRun $run, bool $isSuccess): array
    {
        $status = $isSuccess ? 'Success' : 'Failure';

        return [
            '@type'      => 'MessageCard',
            '@context'   => 'http://schema.org/extensions',
            'themeColor' => $isSuccess ? '2EB67D' : 'D32F2F',
            'summary'    => "{$suite->name}: {$status}",
            'sections'   => [[
                'activityTitle' => "{$suite->name} — {$status}",
                'facts' => [
                    ['name' => 'Passed', 'value' => (string) $run->passed_count],
                    ['name' => 'Failed', 'value' => (string) $run->failed_count],
                    ['name' => 'Errors', 'value' => (string) $run->error_count],
                    ['name' => 'Duration', 'value' => $run->duration_ms ? round($run->duration_ms / 1000, 1).'s' : '—'],
                ],
                'markdown' => true,
            ]],
        ];
    }
}
