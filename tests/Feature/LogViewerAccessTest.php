<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogViewerAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->get('/sorify/log-viewer')->assertRedirect('/sorify/login');
    }

    public function test_non_admins_are_forbidden(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)->get('/sorify/log-viewer')->assertForbidden();
    }

    public function test_admins_can_view_the_log_viewer(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get('/sorify/log-viewer')->assertOk();
    }
}
