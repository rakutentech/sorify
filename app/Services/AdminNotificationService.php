<?php

namespace App\Services;

use App\Mail\NewUserJoinedEmail;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Emails the administrators when a new user joins the platform — whether
 * they self-register, sign in with GitHub for the first time, or are
 * created from the admin panel.
 */
class AdminNotificationService
{
    /**
     * @param  string  $source  admin | github | registered
     */
    public function notifyNewUser(User $user, string $source, ?string $createdByName = null): void
    {
        $recipients = $this->admins();

        if ($recipients->isEmpty()) {
            return;
        }

        try {
            Mail::to($recipients->all())->send(new NewUserJoinedEmail($user, $source, $createdByName));
        } catch (\Throwable $e) {
            // Failures are logged, never thrown — a notification problem
            // must not fail the signup that triggered it.
            Log::warning('Failed to send new-user email to admins', [
                'user_id' => $user->id,
                'source' => $source,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return Collection<int, User>
     */
    private function admins(): Collection
    {
        return User::query()
            ->where('is_admin', true)
            ->orderBy('name')
            ->get();
    }
}
