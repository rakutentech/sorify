<?php

namespace Tests\Feature;

use App\Models\GithubApp;
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

    protected function githubConfig(string $baseUrl = 'https://ghe.example.com', string $clientId = 'test-client-id'): GithubApp
    {
        return GithubApp::create([
            'name' => $baseUrl === '' ? 'GitHub' : (string) parse_url($baseUrl, PHP_URL_HOST),
            'base_url' => $baseUrl,
            'client_id' => $clientId,
            'client_secret' => 'test-client-secret',
            'redirect_uri' => 'http://localhost/sorify/auth/github/callback',
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

    public function test_redirect_requests_the_email_scope_for_oauth_apps(): void
    {
        $this->githubConfig('https://ghe.example.com');

        $response = $this->get('/sorify/auth/github/redirect');

        $response->assertRedirect();
        $this->assertStringContainsString('scope=user%3Aemail', $response->getTargetUrl());
    }

    public function test_redirect_omits_the_scope_parameter_for_github_apps(): void
    {
        // GitHub Apps must not receive a `scope` parameter — GitHub rejects
        // the whole authorize request otherwise. Their permissions live on
        // the app itself (e.g. the Email addresses account permission).
        $this->githubConfig('https://ghe.example.com', 'Iv1.60a2b9fb69782986');

        $response = $this->get('/sorify/auth/github/redirect');

        $response->assertRedirect();
        $this->assertStringStartsWith(
            'https://ghe.example.com/login/oauth/authorize',
            $response->getTargetUrl()
        );
        $this->assertStringNotContainsString('scope=', $response->getTargetUrl());
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
        $app = $this->githubConfig();

        $existing = User::factory()->create([
            'github_id' => '998877',
            'github_app_id' => $app->id,
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

    public function test_the_same_github_id_on_different_apps_are_distinct_users(): void
    {
        // github ids are only unique per app: user 998877 on github.com is a
        // different person from user 998877 on a GitHub Enterprise instance.
        $ghe = $this->githubConfig('https://ghe.example.com');
        $public = $this->githubConfig('', 'public-client-id');

        User::factory()->create([
            'email' => 'ghe-user@example.com',
            'github_id' => '998877',
            'github_app_id' => $ghe->id,
        ]);

        // Sign in with the public app using the SAME numeric id.
        $this->mockGithubUser(SocialiteUser::fake([
            'id' => '998877',
            'email' => 'public-user@example.com',
        ]));

        // Point the callback at the public app.
        $this->withSession(['github_app_id' => $public->id])
            ->get('/sorify/auth/github/callback');

        $this->assertSame(2, User::count());
        $this->assertEquals(
            $public->id,
            User::where('email', 'public-user@example.com')->first()->github_app_id,
        );
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
        // The email permission is mandatory: without it Sorify must
        // refuse sign-in rather than fall back to a synthetic address.
        $this->githubConfig();

        $this->mockGithubUser(SocialiteUser::fake([
            'id' => '998877',
            'email' => null,
        ]));

        $response = $this->get('/sorify/auth/github/callback');

        $response->assertRedirect('/sorify/login');
        $response->assertSessionHasErrors('github');
        $this->assertGuest();
        $this->assertSame(0, User::count());
    }

    protected function mockGithubUser(SocialiteUser $user): void
    {
        $provider = Mockery::mock(SocialiteProvider::class);
        $provider->shouldReceive('user')->andReturn($user);

        Socialite::shouldReceive('driver')->with('github')->andReturn($provider);
    }
}
