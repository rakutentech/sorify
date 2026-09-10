<?php

namespace App\Mail;

use App\Models\TestRun;
use App\Models\TestSuite;
use App\Support\AppUrl;
use App\Support\NotificationCooldown;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class RunDigestEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly TestSuite $suite,
        public readonly Collection $runs,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Sorify — Suite: {$this->suite->name} — Summary of {$this->runs->count()} runs",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.run-digest',
            with: [
                'suite' => $this->suite,
                'suiteUrl' => AppUrl::absolute(route('suites.show', $this->suite, absolute: false)),
                'rows' => $this->rows(),
                'succeeded' => $this->runs->filter(fn (TestRun $run) => $run->isSuccessful())->count(),
                'failed' => $this->runs->reject(fn (TestRun $run) => $run->isSuccessful())->count(),
                'totalTests' => (int) $this->runs->sum('total_tests'),
                'passedTests' => (int) $this->runs->sum('passed_count'),
                'cooldownNotice' => NotificationCooldown::digestNotice($this->suite, 'email'),
            ],
        );
    }

    /**
     * @return array<int, array{id: int, url: string, success: bool, passed: int, total: int, duration: string, triggeredBy: string}>
     */
    private function rows(): array
    {
        return $this->runs->map(function (TestRun $run) {
            return [
                'id' => $run->id,
                'url' => AppUrl::absolute(route('runs.show', $run, absolute: false)),
                'success' => $run->isSuccessful(),
                'passed' => (int) $run->passed_count,
                'total' => (int) $run->total_tests,
                'duration' => $run->duration_ms ? round($run->duration_ms / 1000, 1).'s' : '—',
                'triggeredBy' => $run->triggeredByUser?->name ?? $this->triggeredByLabel($run->triggered_by),
            ];
        })->all();
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
}
