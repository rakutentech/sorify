<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
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

    public function test_user_with_password_must_supply_current_password_to_change_it(): void
    {
        $user = User::factory()->create(['password' => 'secret-password']);

        // Wrong current password is rejected.
        $response = $this->actingAs($user)->put('/sorify/profile/password', [
            'current_password' => 'wrong-password',
            'password' => 'new-password',
            'password_confirmation' => 'new-password',
        ]);

        $response->assertSessionHasErrors('current_password');
        $this->assertTrue(Hash::check('secret-password', $user->fresh()->password));

        // Correct current password updates it.
        $response = $this->actingAs($user)->put('/sorify/profile/password', [
            'current_password' => 'secret-password',
            'password' => 'brand-new-password',
            'password_confirmation' => 'brand-new-password',
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('brand-new-password', $user->fresh()->password));
    }

    public function test_oauth_user_without_password_can_set_one_without_current_password(): void
    {
        $user = User::factory()->create([
            'password' => null,
            'github_id' => '998877',
        ]);

        $this->assertFalse($user->fresh()->has_password);

        $response = $this->actingAs($user)->put('/sorify/profile/password', [
            'password' => 'first-password',
            'password_confirmation' => 'first-password',
        ]);

        $response->assertSessionHasNoErrors();
        $user = $user->fresh();
        $this->assertTrue($user->has_password);
        $this->assertTrue(Hash::check('first-password', $user->password));
    }

    public function test_oauth_user_can_then_sign_in_with_email_and_password(): void
    {
        $user = User::factory()->create([
            'email' => 'jane@example.com',
            'password' => null,
            'github_id' => '998877',
        ]);

        $this->actingAs($user)->put('/sorify/profile/password', [
            'password' => 'first-password',
            'password_confirmation' => 'first-password',
        ])->assertSessionHasNoErrors();

        auth()->logout();

        $response = $this->post('/sorify/login', [
            'email' => 'jane@example.com',
            'password' => 'first-password',
        ]);

        $response->assertRedirect('/sorify/');
        $this->assertAuthenticatedAs($user->fresh());
    }

    public function test_user_can_upload_an_avatar(): void
    {
        Storage::fake('public');

        $user = User::factory()->create(['avatar' => null]);

        $file = UploadedFile::fake()->image('avatar.png', 200, 200);

        $response = $this->actingAs($user)->post('/sorify/profile/avatar', [
            'avatar' => $file,
        ]);

        $response->assertSessionHasNoErrors();
        $response->assertSessionHas('flash.success');

        $user = $user->fresh();
        $this->assertNotNull($user->avatar);
        $this->assertStringStartsWith('avatars/', $user->avatar);
        Storage::disk('public')->assertExists($user->avatar);
    }

    public function test_uploading_a_new_avatar_replaces_the_old_file(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $first = UploadedFile::fake()->image('one.png');
        $this->actingAs($user)->post('/sorify/profile/avatar', ['avatar' => $first])->assertSessionHasNoErrors();
        $oldPath = $user->fresh()->avatar;
        Storage::disk('public')->assertExists($oldPath);

        $second = UploadedFile::fake()->image('two.jpg');
        $this->actingAs($user)->post('/sorify/profile/avatar', ['avatar' => $second])->assertSessionHasNoErrors();
        $newPath = $user->fresh()->avatar;

        $this->assertNotEquals($oldPath, $newPath);
        Storage::disk('public')->assertExists($newPath);
        Storage::disk('public')->assertMissing($oldPath);
    }

    public function test_removing_avatar_deletes_the_file(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();
        $this->actingAs($user)->post('/sorify/profile/avatar', [
            'avatar' => UploadedFile::fake()->image('avatar.png'),
        ])->assertSessionHasNoErrors();

        $path = $user->fresh()->avatar;
        Storage::disk('public')->assertExists($path);

        $this->actingAs($user)->delete('/sorify/profile/avatar')->assertSessionHasNoErrors();

        $this->assertNull($user->fresh()->avatar);
        Storage::disk('public')->assertMissing($path);
    }

    public function test_removing_a_remote_github_avatar_does_not_attempt_file_deletion(): void
    {
        Storage::fake('public');

        $user = User::factory()->create([
            'avatar' => 'https://ghe.example.com/avatars/jane.png',
        ]);

        $this->actingAs($user)->delete('/sorify/profile/avatar')->assertSessionHasNoErrors();

        $this->assertNull($user->fresh()->avatar);
    }

    public function test_avatar_upload_rejects_non_image_files(): void
    {
        Storage::fake('public');

        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/sorify/profile/avatar', [
            'avatar' => UploadedFile::fake()->create('not-an-image.txt', 100, 'text/plain'),
        ]);

        $response->assertSessionHasErrors('avatar');
        $this->assertNull($user->fresh()->avatar);
    }

    public function test_profile_show_exposes_avatar_url_and_has_password(): void
    {
        $user = User::factory()->create([
            'avatar' => 'https://ghe.example.com/avatars/jane.png',
            'password' => null,
        ]);

        $response = $this->actingAs($user)->get('/sorify/profile');

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->where('user.avatar_url', 'https://ghe.example.com/avatars/jane.png')
            ->where('user.has_password', false)
        );
    }
}
