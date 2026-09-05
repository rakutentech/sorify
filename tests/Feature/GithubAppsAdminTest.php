<?php

namespace Tests\Feature;

use App\Models\GithubApp;
use App\Models\TestSuite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GithubAppsAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_github_apps(): void
    {
        $admin = User::factory()->admin()->create();
        GithubApp::create([
            'name' => 'GHE',
            'base_url' => 'https://ghe.example.com',
            'client_id' => 'Iv1.ghe',
            'client_secret' => 'secret',
        ]);

        $this->actingAs($admin)
            ->get('/sorify/admin/github-apps')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('apps.0.name', 'GHE')
                ->where('apps.0.can_sign_in', true)
                ->where('apps.0.can_dispatch', false)
                ->etc());
    }

    public function test_admin_can_create_a_github_app(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->post('/sorify/admin/github-apps', [
                'name' => 'Public GitHub',
                'base_url' => '',
                'client_id' => 'Iv1.public',
                'client_secret' => 'secret',
                'proxy' => 'http://proxy.example.com:8080',
                'app_id' => '123',
                'private_key' => '-----BEGIN RSA PRIVATE KEY-----\\nabc\\n-----END RSA PRIVATE KEY-----\\n',
                'enabled' => true,
            ])
            ->assertRedirect();

        $app = GithubApp::where('client_id', 'Iv1.public')->first();
        $this->assertNotNull($app);
        $this->assertSame('', $app->base_url);
        $this->assertSame('http://proxy.example.com:8080', $app->proxy);
        $this->assertSame('https://api.github.com', $app->apiBase());
        $this->assertTrue($app->canSignIn());
        $this->assertTrue($app->canDispatch());

        // Enterprise instances use the /api/v3 endpoint.
        $ghe = GithubApp::create([
            'name' => 'GHE',
            'base_url' => 'https://ghe.example.com',
            'client_id' => 'Iv1.ghe',
            'client_secret' => 'secret',
        ]);
        $this->assertSame('https://ghe.example.com/api/v3', $ghe->apiBase());
    }

    public function test_blank_secret_fields_keep_the_stored_values_on_update(): void
    {
        $admin = User::factory()->admin()->create();
        $app = GithubApp::create([
            'name' => 'GHE',
            'base_url' => 'https://ghe.example.com',
            'client_id' => 'Iv1.ghe',
            'client_secret' => 'original-secret',
            'app_id' => '123',
            'private_key' => '-----BEGIN RSA PRIVATE KEY-----\\nabc\\n-----END RSA PRIVATE KEY-----\\n',
        ]);

        $this->actingAs($admin)
            ->put("/sorify/admin/github-apps/{$app->id}", [
                'name' => 'GHE Renamed',
                'base_url' => 'https://ghe.example.com',
                'client_id' => 'Iv1.ghe',
                'client_secret' => '',
                'app_id' => '123',
                'private_key' => '',
                'sign_in_enabled' => true,
                'actions_enabled' => true,
            ])
            ->assertRedirect();

        $app->refresh();
        $this->assertSame('GHE Renamed', $app->name);
        $this->assertSame('original-secret', $app->client_secret);
        $this->assertStringContainsString('BEGIN RSA PRIVATE KEY', $app->privateKeyPem());
    }

    public function test_secrets_are_never_exposed_in_listings(): void
    {
        $admin = User::factory()->admin()->create();
        GithubApp::create([
            'name' => 'GHE',
            'base_url' => 'https://ghe.example.com',
            'client_id' => 'Iv1.ghe',
            'client_secret' => 'super-secret-value',
        ]);

        $response = $this->actingAs($admin)
            ->get('/sorify/admin/github-apps')
            ->assertOk();

        $this->assertStringNotContainsString('super-secret-value', $response->getContent());
    }

    public function test_non_admin_cannot_manage_github_apps(): void
    {
        $user = User::factory()->create();
        $app = GithubApp::create([
            'name' => 'GHE',
            'base_url' => 'https://ghe.example.com',
            'client_id' => 'Iv1.ghe',
            'client_secret' => 'secret',
        ]);

        $this->actingAs($user)->get('/sorify/admin/github-apps')->assertForbidden();
        $this->actingAs($user)->post('/sorify/admin/github-apps', [])->assertForbidden();
        $this->actingAs($user)->put("/sorify/admin/github-apps/{$app->id}", [])->assertForbidden();
        $this->actingAs($user)->delete("/sorify/admin/github-apps/{$app->id}")->assertForbidden();
    }

    public function test_connection_check_tests_the_instance_api_root(): void
    {
        $admin = User::factory()->admin()->create();

        Http::fake([
            'https://ghe.example.com/api/v3/' => Http::response(['current_user_url' => 'x'], 200),
            'https://api.github.com/' => Http::response([], 200),
        ]);

        // Enterprise instance: the configured base URL is probed.
        $this->actingAs($admin)
            ->postJson('/sorify/admin/github-apps/test-connection', [
                'base_url' => 'https://ghe.example.com',
                'proxy' => 'http://proxy.example.com:8080',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('status', 200)
            ->assertJsonPath('url', 'https://ghe.example.com/api/v3/');

        // Empty base URL falls back to public github.com's API.
        $this->actingAs($admin)
            ->postJson('/sorify/admin/github-apps/test-connection', ['base_url' => ''])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('url', 'https://api.github.com/');

        // Unreachable instances report ok=false, never a 500.
        Http::fake(fn () => throw new \Exception('connection refused'));

        $this->actingAs($admin)
            ->postJson('/sorify/admin/github-apps/test-connection', [
                'base_url' => 'https://ghe.example.com',
            ])
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('error', 'connection refused');
    }

    public function test_connection_check_is_admin_only(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/sorify/admin/github-apps/test-connection', ['base_url' => ''])
            ->assertForbidden();
    }

    public function test_deleting_an_app_disables_its_active_integrations_with_a_note(): void
    {
        $admin = User::factory()->admin()->create();
        $app = GithubApp::create([
            'name' => 'GHE',
            'base_url' => 'https://ghe.example.com',
            'client_id' => 'Iv1.ghe',
            'client_secret' => 'secret',
            'app_id' => '123',
            'private_key' => '-----BEGIN RSA PRIVATE KEY-----\\nabc\\n-----END RSA PRIVATE KEY-----\\n',
        ]);

        $user = User::factory()->create(['github_id' => '123', 'github_app_id' => $app->id]);

        $suite = TestSuite::create(['name' => 'Suite', 'created_by' => $admin->id]);
        $active = $suite->integrations()->create([
            'type' => 'github_action',
            'github_app_id' => $app->id,
            'config' => ['repository' => 'acme/app', 'workflow' => 'deploy.yml'],
            'enabled' => true,
        ]);
        // Already-disabled and other-type integrations are left untouched.
        $disabled = $suite->integrations()->create([
            'type' => 'github_action',
            'github_app_id' => $app->id,
            'config' => ['repository' => 'acme/app', 'workflow' => 'other.yml'],
            'enabled' => false,
        ]);

        $this->actingAs($admin)
            ->delete("/sorify/admin/github-apps/{$app->id}")
            ->assertRedirect();

        // Active integrations are force-disabled with an explanatory note…
        $this->assertFalse($active->fresh()->enabled);
        $this->assertStringContainsString('was deleted', (string) $active->fresh()->disabled_note);

        // …NOT silently re-routed to another app (the app reference is
        // cleared by the FK, so dispatching fails loudly).
        $this->assertNull($active->fresh()->github_app_id);

        // Already-disabled integrations only lose the app reference.
        $this->assertFalse($disabled->fresh()->enabled);
        $this->assertNull($disabled->fresh()->disabled_note);

        // Users keep their accounts but lose the GitHub link.
        $this->assertNull($user->fresh()->github_app_id);
        $this->assertTrue($user->exists());
    }
}
