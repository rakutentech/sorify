<?php

namespace App\Mail;

use App\Models\User;
use App\Support\AppUrl;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class NewUserJoinedEmail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly string $source,
        public readonly ?string $createdByName = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Sorify — New user: {$this->user->name}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.new-user-joined',
            with: [
                'user' => $this->user,
                'source' => $this->source,
                'createdByName' => $this->createdByName,
                'usersUrl' => AppUrl::absolute(route('admin.users.index', absolute: false)),
            ],
        );
    }
}
