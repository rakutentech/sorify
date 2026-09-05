<?php

namespace App\Services;

use App\Models\Screenshot;
use App\Models\TestResult;
use App\Models\TestRun;
use App\Models\TestSuite;
use App\Support\AppUrl;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TeamsNotificationService
{
    private const TEST_LIST_LIMIT = 10;

    private const STATUS_ICONS = [
        'passed' => '✅',
        'failed' => '❌',
        'error' => '⚠️',
        'timeout' => '⏱️',
        'skipped' => '⏭️',
        'cancelled' => '🚫',
    ];

    public function notifyRunStarted(TestRun $run): void
    {
        $suite = $run->testSuite;

        if (! $suite || ! $suite->teams_webhook_url || ! $suite->teams_notify_on_start) {
            return;
        }

        $this->post($suite, $this->buildStartedPayload($suite, $run), $run, 'start');
    }

    public function notifyRunCompleted(TestRun $run): void
    {
        $suite = $run->testSuite;

        if (! $suite || ! $suite->teams_webhook_url || $run->status === 'cancelled') {
            return;
        }

        // A run aborted by a failing pre-run integration also has zero
        // failure counts — the status check keeps those out of "success".
        $isSuccess = $run->status === 'completed'
            && (int) $run->failed_count === 0
            && (int) $run->error_count === 0;

        if ($isSuccess && ! $suite->teams_notify_on_success) {
            return;
        }

        if (! $isSuccess && ! $suite->teams_notify_on_failure) {
            return;
        }

        $this->post($suite, $this->buildPayload($suite, $run, $isSuccess), $run, 'completion');
    }

    /**
     * Send an Adaptive Card payload to the suite's Teams webhook, honoring
     * the webhook-specific proxy. Failures are logged, never thrown — a
     * notification problem must not fail a test run.
     */
    private function post(TestSuite $suite, array $payload, TestRun $run, string $kind): void
    {
        try {
            $request = Http::timeout(10);

            if ($suite->teams_webhook_proxy) {
                $request->withOptions(['proxy' => $suite->teams_webhook_proxy]);
            }

            $response = $request->post($suite->teams_webhook_url, $payload);

            if ($response->failed()) {
                Log::warning('Teams webhook rejected the notification for test run', [
                    'suite_id' => $suite->id,
                    'run_id' => $run->id,
                    'kind' => $kind,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to send Teams notification for test run', [
                'suite_id' => $suite->id,
                'run_id' => $run->id,
                'kind' => $kind,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Compact "run started" card: suite name, test count, trigger source and
     * a link to the run — enough to glance at what just kicked off.
     */
    private function buildStartedPayload(TestSuite $suite, TestRun $run): array
    {
        $suiteUrl = AppUrl::absolute(route('suites.show', $suite, absolute: false));
        $runUrl = $this->absoluteUrl(route('runs.show', $run, absolute: false));

        return [
            'type' => 'message',
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'contentUrl' => null,
                'content' => [
                    '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                    'type' => 'AdaptiveCard',
                    'version' => '1.4',
                    'body' => [
                        [
                            'type' => 'TextBlock',
                            'id' => 'title',
                            'text' => "Sorify — Suite: {$suite->name} — Run started",
                            'size' => 'large',
                            'weight' => 'bolder',
                            'wrap' => true,
                        ],
                        [
                            'type' => 'TextBlock',
                            'text' => "{$run->total_tests} test".($run->total_tests === 1 ? '' : 's').' queued',
                            'wrap' => true,
                            'spacing' => 'small',
                        ],
                        [
                            'type' => 'TextBlock',
                            'text' => "Triggered by: {$this->triggeredBy($run)}",
                            'wrap' => true,
                            'isSubtle' => true,
                            'spacing' => 'none',
                        ],
                    ],
                    'actions' => [
                        ['type' => 'Action.OpenUrl', 'title' => 'View Test Suite', 'url' => $suiteUrl],
                        ['type' => 'Action.OpenUrl', 'title' => 'View Run', 'url' => $runUrl],
                    ],
                    'msteams' => ['width' => 'Full'],
                ],
            ]],
        ];
    }

    private function buildPayload(TestSuite $suite, TestRun $run, bool $isSuccess): array
    {
        $status = $isSuccess ? 'Success' : 'Failure';
        $duration = $run->duration_ms ? round($run->duration_ms / 1000, 1).'s' : '—';
        $suiteUrl = AppUrl::absolute(route('suites.show', $suite, absolute: false));
        $runUrl = AppUrl::absolute(route('runs.show', $run, absolute: false));

        $body = [
            [
                'type' => 'TextBlock',
                'id' => 'title',
                'text' => "Sorify — Suite: {$suite->name} — {$status}",
                'size' => 'large',
                'weight' => 'bolder',
                'wrap' => true,
                'color' => $isSuccess ? 'good' : 'attention',
            ],
        ];

        if ($suite->description) {
            $body[] = [
                'type' => 'TextBlock',
                'text' => $suite->description,
                'isSubtle' => true,
                'wrap' => true,
                'spacing' => 'none',
            ];
        }

        $total = (int) $run->total_tests;
        $passed = (int) $run->passed_count;
        $failed = (int) $run->failed_count;
        $errors = (int) $run->error_count;

        $body[] = [
            'type' => 'TextBlock',
            'text' => "{$passed}/{$total} passed  •  {$failed} failed  •  {$errors} errors  •  {$duration}",
            'wrap' => true,
            'spacing' => 'small',
        ];

        $body[] = [
            'type' => 'TextBlock',
            'text' => "Triggered by: {$this->triggeredBy($run)}",
            'wrap' => true,
            'isSubtle' => true,
            'spacing' => 'none',
        ];

        array_push($body, ...$this->buildTestsSection($run));
        array_push($body, ...$this->buildScreenshotsSection($run));

        return [
            'type' => 'message',
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'contentUrl' => null,
                'content' => [
                    '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                    'type' => 'AdaptiveCard',
                    'version' => '1.4',
                    'body' => $body,
                    'actions' => [
                        ['type' => 'Action.OpenUrl', 'title' => 'View Test Suite', 'url' => $suiteUrl],
                        ['type' => 'Action.OpenUrl', 'title' => 'View Run', 'url' => $runUrl],
                    ],
                    'msteams' => ['width' => 'Full'],
                ],
            ]],
        ];
    }

    /**
     * Builds an absolute URL from a relative route path.
     *
     * This runs inside a queued job with no active HTTP request, so route()
     * falls back to config('app.url') as the root, which already includes the
     * "/sorify" path segment used by the route group prefix — doubling it.
     * Only the scheme and host from app.url are used here; the path already
     * carries the prefix.
     */
    private function absoluteUrl(string $path): string
    {
        return AppUrl::absolute($path);
    }

    /**
     * A human-readable label for who (or what) kicked off the run. Manual
     * and MCP runs are attributed to the triggering user by name; CI and
     * scheduled runs have no human user, so they fall back to the source.
     */
    private function triggeredBy(TestRun $run): string
    {
        return $run->triggeredByUser?->name ?? $this->triggeredByLabel($run->triggered_by);
    }

    private function triggeredByLabel(string $source): string
    {
        return match ($source) {
            'ci' => 'CI Webhook',
            'schedule' => 'Schedule',
            'mcp' => 'MCP',
            default => 'Manual',
        };
    }

    private function buildTestsSection(TestRun $run): array
    {
        $results = $run->testResults()->with('test:id,name')->orderBy('id')->get();

        if ($results->isEmpty()) {
            return [];
        }

        $shown = $results->take(self::TEST_LIST_LIMIT);
        $remaining = $results->count() - $shown->count();

        $lines = $shown->map(function (TestResult $result) {
            $icon = self::STATUS_ICONS[$result->status] ?? '•';
            $name = $result->test->name ?? "Test #{$result->test_id}";

            return "{$icon} {$name}";
        });

        if ($remaining > 0) {
            $lines->push('+'.$remaining.' more test'.($remaining === 1 ? '' : 's'));
        }

        return [
            [
                'type' => 'TextBlock',
                'text' => 'Tests',
                'weight' => 'bolder',
                'spacing' => 'medium',
            ],
            [
                'type' => 'TextBlock',
                'text' => $lines->implode("\n\n"),
                'wrap' => true,
                'isSubtle' => true,
            ],
        ];
    }

    private function buildScreenshotsSection(TestRun $run): array
    {
        $maxScreenshots = (int) config('sorify.teams_max_screenshots', 5);

        if ($maxScreenshots <= 0) {
            return [];
        }

        $results = $run->testResults()
            ->with(['screenshots', 'test:id,name'])
            ->get()
            ->sortBy(fn (TestResult $result) => in_array($result->status, ['failed', 'error'], true) ? 0 : 1);

        $screenshots = $results
            ->flatMap(fn (TestResult $result) => $result->screenshots->map(fn (Screenshot $screenshot) => [$screenshot, $result]));

        $total = $screenshots->count();

        if ($total === 0) {
            return [];
        }

        $shown = $screenshots->take($maxScreenshots);
        $remaining = $total - $shown->count();
        $runUrl = $this->absoluteUrl(route('runs.show', $run, absolute: false));

        // Screenshots can't be embedded inline: Sorify runs behind a VPN-only
        // host, and Teams fetches card images server-side from Microsoft's
        // own infrastructure, which can never reach it. Link out instead —
        // opened in the recipient's own browser, which is on the VPN — using
        // markdown links inside a TextBlock so the section stays compact even
        // when there are many screenshots. Anything past the cap collapses
        // into a single "+N more" link back to the run page, which shows
        // every screenshot.
        $lines = $shown->map(function (array $pair) {
            [$screenshot, $result] = $pair;
            $label = $result->test->name ?? $screenshot->filename;

            return sprintf(
                '[%s](%s)',
                str_replace(['[', ']'], ['\\[', '\\]'], $label),
                $this->absoluteUrl(route('screenshots.show', $screenshot, absolute: false)),
            );
        });

        if ($remaining > 0) {
            $lines->push(sprintf('[+%d more](%s)', $remaining, $runUrl));
        }

        return [
            [
                'type' => 'TextBlock',
                'text' => 'Screenshots',
                'weight' => 'bolder',
                'spacing' => 'medium',
            ],
            [
                'type' => 'TextBlock',
                'text' => $lines->implode(', '),
                'wrap' => true,
                'isSubtle' => true,
            ],
        ];
    }
}
