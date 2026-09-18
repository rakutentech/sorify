<?php

namespace Tests\Feature;

use App\Mail\NewUserJoinedEmail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * A new user joining — self-registration or admin creation — emails the
 * administrators.
 */
class NewUserAdminNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_self_registration_notifies_admins(): void
    {
        Mail::fake();

        $admin = User::factory()->admin()->create();
        $otherAdmin = User::factory()->admin()->create();
        User::factory()->create(); // regular users are not notified

        $response = $this->post('/sorify/register', [
            'name' => 'Newcomer',
            'email' => 'newcomer@example.com',
            'password' => 'super-secret-1',
            'password_confirmation' => 'super-secret-1',
        ]);

        $response->assertRedirect('/sorify/');

        // The mailable is queued (ShouldQueue) — assert on the queue.
        Mail::assertQueued(NewUserJoinedEmail::class, 1);
        Mail::assertQueued(NewUserJoinedEmail::class, fn ($mail) => $mail->hasTo([$admin->email, $otherAdmin->email])
            && $mail->user->email === 'newcomer@example.com'
            && $mail->source === 'registered');

        Mail::assertNotSent(NewUserJoinedEmail::class);
    }

    public function test_admin_creation_notifies_admins(): void
    {
        Mail::fake();

        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post('/sorify/admin/users', [
            'name' => 'Created User',
            'email' => 'created@example.com',
            'password' => 'super-secret-1',
            'role' => 'member',
        ])->assertRedirect();

        Mail::assertQueued(NewUserJoinedEmail::class, 1);

        Mail::assertQueued(NewUserJoinedEmail::class, fn ($mail) => $mail->hasTo($admin->email)
            && $mail->user->email === 'created@example.com'
            && $mail->source === 'admin'
            && $mail->createdByName === $admin->name);
    }

    public function test_no_admins_means_no_email_and_no_failure(): void
    {
        Mail::fake();

        $this->post('/sorify/register', [
            'name' => 'Newcomer',
            'email' => 'newcomer@example.com',
            'password' => 'super-secret-1',
            'password_confirmation' => 'super-secret-1',
        ])->assertRedirect('/sorify/');

        Mail::assertNothingQueued();
        Mail::assertNothingSent();
    }
}
