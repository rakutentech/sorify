<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Contracts\Provider as SocialiteProvider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Mockery;
use Tests\TestCase;

class GithubAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function githubConfig(string $baseUrl = 'https://ghe.example.com'): void
    {
        config()->set('services.github', [
            'client_id' => 'test-client-id',
            'client_secret' => 'test-client-secret',
            'redirect' => 'http://localhost/sorify/auth/github/callback',
            'url' => $baseUrl,
            'scopes' => ['user:email'],
        ]);
    }

    public function test_redirect_uses_github_enterprise_authorize_url(): void
    {
        $this->githubConfig('https://ghe.example.com');

        $response = $this->get('/sorify/auth/github/redirect');

        $response->assertRedirect();
        $this->assertStringStartsWith(
            'https://ghe.example.com/login/oauth/authorize',
            $response->getTargetUrl()
        );
        $this->assertStringContainsString('client_id=test-client-id', $response->getTargetUrl());
    }

    public function test_redirect_falls_back_to_github_com_without_base_url(): void
    {
        $this->githubConfig('');

        $response = $this->get('/sorify/auth/github/redirect');

        $response->assertRedirect();
        $this->assertStringStartsWith(
            'https://github.com/login/oauth/authorize',
            $response->getTargetUrl()
        );
    }

    public function test_callback_creates_a_new_user_and_logs_them_in(): void
    {
        $this->githubConfig();

        $this->mockGithubUser(SocialiteUser::fake([
            'id' => '998877',
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'avatar' => 'https://ghe.example.com/avatars/jane.png',
            'token' => 'gho_access',
            'refreshToken' => 'gho_refresh',
        ]));

        $response = $this->get('/sorify/auth/github/callback');

        $response->assertRedirect('/sorify/');

        $user = User::where('email', 'jane@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame('998877', $user->github_id);
        $this->assertSame('gho_access', $user->github_token);
        $this->assertNull($user->password);
        $this->assertTrue($user->is_view_only);
        $this->assertNotNull($user->email_verified_at);
        $this->assertSame('https://ghe.example.com/avatars/jane.png', $user->avatar);
        $this->assertSame('https://ghe.example.com/avatars/jane.png', $user->avatar_url);
        $this->assertFalse($user->has_password);
        $this->assertTrue(Auth::check());
        $this->assertEquals($user->id, Auth::id());
    }

    public function test_callback_links_existing_user_by_email(): void
    {
        $this->githubConfig();

        $existing = User::factory()->create([
            'email' => 'jane@example.com',
            'github_id' => null,
        ]);

        $this->mockGithubUser(SocialiteUser::fake([
            'id' => '998877',
            'email' => 'jane@example.com',
            'avatar' => 'https://ghe.example.com/avatars/jane.png',
        ]));

        $response = $this->get('/sorify/auth/github/callback');

        $response->assertRedirect('/sorify/');

        $this->assertSame(1, User::where('email', 'jane@example.com')->count());
        $existing->refresh();
        $this->assertSame('998877', $existing->github_id);
        // The pre-existing email-signup user adopts the GitHub avatar.
        $this->assertSame('https://ghe.example.com/avatars/jane.png', $existing->avatar);
        $this->assertEquals($existing->id, Auth::id());
    }

    public function test_callback_does_not_overwrite_an_uploaded_avatar(): void
    {
        $this->githubConfig();

        $existing = User::factory()->create([
            'email' => 'jane@example.com',
            'github_id' => null,
            'avatar' => 'avatars/uploaded.png',
        ]);

        $this->mockGithubUser(SocialiteUser::fake([
            'id' => '998877',
            'email' => 'jane@example.com',
            'avatar' => 'https://ghe.example.com/avatars/jane.png',
        ]));

        $this->get('/sorify/auth/github/callback');

        $existing->refresh();
        // Uploaded avatar is preserved; GitHub's is ignored.
        $this->assertSame('avatars/uploaded.png', $existing->avatar);
    }

    public function test_callback_logs_in_existing_user_matched_by_github_id(): void
    {
        $this->githubConfig();

        $existing = User::factory()->create([
            'github_id' => '998877',
            'email' => 'other@example.com',
        ]);

        $this->mockGithubUser(SocialiteUser::fake([
            'id' => '998877',
            'email' => 'new-email@example.com',
        ]));

        $this->get('/sorify/auth/github/callback');

        $this->assertEquals($existing->id, Auth::id());
        $this->assertSame(1, User::count());
    }

    public function test_callback_handles_error_from_github(): void
    {
        $response = $this->get('/sorify/auth/github/callback?error=access_denied');

        $response->assertRedirect('/sorify/login');
        $response->assertSessionHasErrors('github');
        $this->assertGuest();
    }

    public function test_callback_without_email_redirects_with_error(): void
    {
        $this->githubConfig();

        $this->mockGithubUser(SocialiteUser::fake([
            'id' => '998877',
            'email' => null,
        ]));

        $response = $this->get('/sorify/auth/github/callback');

        $response->assertRedirect('/sorify/login');
        $response->assertSessionHasErrors('github');
        $this->assertGuest();
    }

    protected function mockGithubUser(SocialiteUser $user): void
    {
        $provider = Mockery::mock(SocialiteProvider::class);
        $provider->shouldReceive('user')->andReturn($user);

        Socialite::shouldReceive('driver')->with('github')->andReturn($provider);
    }
}
