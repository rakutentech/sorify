<?php

namespace App\Mail;

use App\Models\Screenshot;
use App\Models\TestResult;
use App\Models\TestRun;
use App\Support\AppUrl;
use App\Support\NotificationCooldown;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;

class RunCompletedEmail extends Mailable
{
    use Queueable, SerializesModels;

    private const TEST_LIST_LIMIT = 10;

    public function __construct(
        public readonly TestRun $run,
        public readonly bool $isSuccess,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Sorify — Suite: {$this->run->testSuite->name} — ".($this->isSuccess ? 'Success' : 'Failure'),
        );
    }

    public function content(): Content
    {
        [$screenshots, $remainingScreenshots] = $this->screenshotsFor($this->run);

        $results = $this->run->testResults()->with('test:id,name')->orderBy('id')->get();
        $tests = $results->take(self::TEST_LIST_LIMIT)
            ->map(fn (TestResult $result) => [
                'name' => $result->test?->name ?? "Test #{$result->test_id}",
                'status' => $result->status,
            ])
            ->values()
            ->all();

        return new Content(
            view: 'emails.run-completed',
            with: [
                'run' => $this->run,
                'suite' => $this->run->testSuite,
                'isSuccess' => $this->isSuccess,
                'triggeredBy' => $this->triggeredBy($this->run),
                'suiteUrl' => AppUrl::absolute(route('suites.show', $this->run->testSuite, absolute: false)),
                'runUrl' => AppUrl::absolute(route('runs.show', $this->run, absolute: false)),
                'duration' => $this->run->duration_ms ? round($this->run->duration_ms / 1000, 1).'s' : '—',
                'tests' => $tests,
                'remainingTests' => max(0, $results->count() - count($tests)),
                'screenshots' => $screenshots,
                'remainingScreenshots' => $remainingScreenshots,
                'cooldownNotice' => NotificationCooldown::holdNotice($this->run->testSuite, 'email'),
            ],
        );
    }

    /**
     * A human-readable label for who (or what) kicked off the run.
     */
    private function triggeredBy(TestRun $run): string
    {
        if ($run->triggeredByUser?->name) {
            return $run->triggeredByUser->name;
        }

        return match ($run->triggered_by) {
            'ci' => 'CI Webhook',
            'schedule' => 'Schedule',
            'mcp' => 'MCP',
            default => 'Manual',
        };
    }

    /**
     * Screenshot rows for a run, capped and ordered like the Teams card —
     * failing/error results first — with the file contents loaded for
     * inline embedding. Returns [items, remainingCount].
     *
     * @return array{0: array<int, array{url: string, label: string, filename: string, mime: string, data: string}>, 1: int}
     */
    private function screenshotsFor(TestRun $run): array
    {
        $max = (int) config('sorify.email_max_screenshots', 10);

        if ($max <= 0) {
            return [[], 0];
        }

        $results = $run->testResults()
            ->with(['screenshots', 'test:id,name'])
            ->get()
            ->sortBy(fn (TestResult $result) => in_array($result->status, ['failed', 'error'], true) ? 0 : 1);

        $pairs = $results
            ->flatMap(fn (TestResult $result) => $result->screenshots->map(fn (Screenshot $screenshot) => [$screenshot, $result]));

        if ($pairs->isEmpty()) {
            return [[], 0];
        }

        $items = [];
        foreach ($pairs->take($max) as [$screenshot, $result]) {
            $data = Storage::disk('screenshots')->get($screenshot->path);

            if ($data === null) {
                continue;
            }

            $items[] = [
                'url' => AppUrl::absolute(route('screenshots.show', $screenshot, absolute: false)),
                'label' => $result->test?->name ?? $screenshot->filename,
                'filename' => $screenshot->filename,
                'mime' => self::mimeType($screenshot->filename),
                'data' => $data,
            ];
        }

        return [$items, max(0, $pairs->count() - count($items))];
    }

    private static function mimeType(string $filename): string
    {
        return match (strtolower(pathinfo($filename, PATHINFO_EXTENSION))) {
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'bmp' => 'image/bmp',
            'svg' => 'image/svg+xml',
            default => 'image/png',
        };
    }
}
