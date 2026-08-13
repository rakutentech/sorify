<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_update_their_name(): void
    {
        $user = User::factory()->create(['name' => 'Old Name']);

        $response = $this->actingAs($user)->put('/sorify/profile', [
            'name' => 'New Name',
        ]);

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertSame('New Name', $user->fresh()->name);
    }

    public function test_name_is_required(): void
    {
        $user = User::factory()->create(['name' => 'Old Name']);

        $response = $this->actingAs($user)->put('/sorify/profile', [
            'name' => '',
        ]);

        $response->assertSessionHasErrors('name');
        $this->assertSame('Old Name', $user->fresh()->name);
    }
}
