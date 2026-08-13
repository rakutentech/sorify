<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_page_is_accessible(): void
    {
        $response = $this->get('/sorify/register');

        $response->assertStatus(200);
    }

    public function test_user_can_register(): void
    {
        $response = $this->post('/sorify/register', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect('/sorify/');

        $user = User::where('email', 'jane@example.com')->first();
        $this->assertNotNull($user);
        $this->assertTrue(Hash::check('secret-password', $user->password));
        $this->assertFalse($user->is_admin);
        $this->assertTrue(Auth::check());
        $this->assertEquals($user->id, Auth::id());
    }

    public function test_registration_fails_with_duplicate_email(): void
    {
        $existing = User::factory()->create();

        $response = $this->post('/sorify/register', [
            'name' => 'Jane Doe',
            'email' => $existing->email,
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_registration_fails_when_passwords_do_not_match(): void
    {
        $response = $this->post('/sorify/register', [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'different-password',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertGuest();
    }

    public function test_registered_users_are_redirected_from_the_registration_page(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/sorify/register');

        $response->assertRedirect('/sorify/');
    }
}
