<?php

namespace Tests\Feature;

use App\Models\DashboardNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardNoteTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_are_redirected_to_login(): void
    {
        $this->put('/sorify/dashboard-note', ['content' => 'hi'])->assertRedirect('/sorify/login');
    }

    public function test_non_admins_cannot_update_the_note(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $this->actingAs($user)
            ->put('/sorify/dashboard-note', ['content' => 'hi'])
            ->assertForbidden();
    }

    public function test_admins_can_update_the_note(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)
            ->put('/sorify/dashboard-note', ['content' => 'Deploy freeze until Friday'])
            ->assertRedirect();

        $this->assertSame('Deploy freeze until Friday', DashboardNote::current()->content);
        $this->assertSame($admin->id, DashboardNote::current()->updated_by);
    }

    public function test_everyone_sees_the_saved_note_read_only_on_the_dashboard(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $viewer = User::factory()->create(['is_admin' => false]);

        DashboardNote::current()->update(['content' => 'Deploy freeze until Friday', 'updated_by' => $admin->id]);

        $this->actingAs($viewer)
            ->get('/sorify/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('dashboard_note.content', 'Deploy freeze until Friday')
                ->where('can.edit_dashboard_note', false)
            );

        $this->actingAs($admin)
            ->get('/sorify/')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('dashboard_note.content', 'Deploy freeze until Friday')
                ->where('can.edit_dashboard_note', true)
            );
    }
}
