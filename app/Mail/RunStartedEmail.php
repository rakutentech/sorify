<?php

namespace App\Mail;

use App\Models\TestRun;
use App\Support\AppUrl;
use App\Support\NotificationCooldown;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class RunStartedEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly TestRun $run) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Sorify — Suite: {$this->run->testSuite->name} — Run started",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.run-started',
            with: [
                'run' => $this->run,
                'suite' => $this->run->testSuite,
                'triggeredBy' => $this->triggeredBy($this->run),
                'suiteUrl' => AppUrl::absolute(route('suites.show', $this->run->testSuite, absolute: false)),
                'runUrl' => AppUrl::absolute(route('runs.show', $this->run, absolute: false)),
                'cooldownNotice' => NotificationCooldown::holdNotice($this->run->testSuite, 'email'),
            ],
        );
    }

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
}
