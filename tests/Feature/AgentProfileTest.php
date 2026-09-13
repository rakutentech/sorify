<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AgentProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_lists_only_own_profiles(): void
    {
        $alice = User::factory()->create();
        $bob = User::factory()->create();

        AgentProfile::create($this->profileAttributes($alice, ['name' => 'Alice OpenAI']));

        $response = $this->actingAs($bob)->getJson('/sorify/agent/profiles');

        $response->assertOk()->assertJsonCount(0, 'profiles');

        $this->actingAs($alice)->getJson('/sorify/agent/profiles')
            ->assertOk()
            ->assertJsonCount(1, 'profiles')
            ->assertJsonPath('profiles.0.name', 'Alice OpenAI')
            ->assertJsonPath('profiles.0.token_configured', true);
    }

    public function test_store_creates_profile_and_never_returns_the_token(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/sorify/agent/profiles', [
            'name' => 'Work OpenAI',
            'base_url' => 'https://api.openai.com',
            'api_token' => 'sk-secret',
            'proxy_url' => null,
            'system_prompt' => 'Prefer short test names.',
            'history_retention_days' => 90,
        ]);

        $response->assertCreated()
            ->assertJsonPath('profile.name', 'Work OpenAI')
            ->assertJsonPath('profile.token_configured', true);

        $this->assertStringNotContainsString('sk-secret', $response->getContent());

        $profile = AgentProfile::query()->where('user_id', $user->id)->firstOrFail();

        $this->assertSame('sk-secret', $profile->api_token);
        $this->assertNotSame('sk-secret', $profile->getRawOriginal('api_token'), 'Token must be encrypted at rest.');
        $this->assertSame(90, $profile->history_retention_days);
    }

    public function test_store_requires_name_base_url_and_token(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/sorify/agent/profiles', [
            'name' => '',
            'base_url' => '',
            'api_token' => '',
            'history_retention_days' => 30,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'base_url', 'api_token']);
    }

    public function test_store_rejects_invalid_retention(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/sorify/agent/profiles', [
            'name' => 'X',
            'base_url' => 'https://api.openai.com',
            'api_token' => 'sk-secret',
            'history_retention_days' => 45,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors(['history_retention_days']);
    }

    public function test_update_with_blank_token_keeps_stored_token(): void
    {
        $user = User::factory()->create();

        $profile = AgentProfile::create($this->profileAttributes($user, ['api_token' => 'sk-original']));

        $response = $this->actingAs($user)->putJson("/sorify/agent/profiles/{$profile->id}", [
            'name' => 'Renamed',
            'base_url' => 'http://localhost:11434/v1',
            'api_token' => null,
            'history_retention_days' => 30,
        ]);

        $response->assertOk()->assertJsonPath('profile.token_configured', true);

        $this->assertSame('sk-original', $profile->fresh()->api_token);
        $this->assertSame('Renamed', $profile->fresh()->name);
    }

    public function test_update_rejects_foreign_profile(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $profile = AgentProfile::create($this->profileAttributes($owner));

        $response = $this->actingAs($attacker)->putJson("/sorify/agent/profiles/{$profile->id}", [
            'name' => 'Stolen',
            'base_url' => 'https://evil.example.com',
            'history_retention_days' => 30,
        ]);

        $response->assertForbidden();
        $this->assertSame('My OpenAI', $profile->fresh()->name);
    }

    public function test_destroy_deletes_own_profile_only(): void
    {
        $owner = User::factory()->create();
        $attacker = User::factory()->create();

        $profile = AgentProfile::create($this->profileAttributes($owner));

        $this->actingAs($attacker)->deleteJson("/sorify/agent/profiles/{$profile->id}")
            ->assertForbidden();

        $this->assertNotNull($profile->fresh());

        $this->actingAs($owner)->deleteJson("/sorify/agent/profiles/{$profile->id}")
            ->assertOk();

        $this->assertNull($profile->fresh());
    }

    public function test_profile_page_receives_own_profiles(): void
    {
        $user = User::factory()->create();

        AgentProfile::create($this->profileAttributes($user, ['name' => 'Visible']));

        $this->actingAs($user)->get('/sorify/profile')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('agentProfiles.0.name', 'Visible')
                ->etc());
    }

    public function test_test_connection_uses_stored_token_when_input_is_blank(): void
    {
        Http::fake(['*/models' => Http::response(['data' => [['id' => 'gpt-4o']]])]);

        $user = User::factory()->create();
        $profile = AgentProfile::create($this->profileAttributes($user, ['api_token' => 'sk-stored']));

        // Blank api_token means "keep the stored one" — the server must send
        // the stored token, not an unauthenticated request (401).
        $response = $this->actingAs($user)->postJson('/sorify/agent/profiles/test-connection', [
            'profile_id' => $profile->id,
            'base_url' => 'https://api.example.com',
            'api_token' => null,
        ]);

        $response->assertOk()->assertJsonPath('ok', true);

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer sk-stored')
            && $request->url() === 'https://api.example.com/v1/models'
        );
    }

    public function test_test_connection_explicit_token_overrides_stored(): void
    {
        Http::fake(['*/models' => Http::response(['data' => [['id' => 'm1']]])]);

        $user = User::factory()->create();
        $profile = AgentProfile::create($this->profileAttributes($user, ['api_token' => 'sk-stored']));

        $response = $this->actingAs($user)->postJson('/sorify/agent/profiles/test-connection', [
            'profile_id' => $profile->id,
            'base_url' => $profile->base_url,
            'api_token' => 'sk-fresh',
        ]);

        $response->assertOk()->assertJsonPath('ok', true);

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer sk-fresh'));
    }

    public function test_models_endpoint_uses_stored_token_when_input_is_blank(): void
    {
        Http::fake(['*/models' => Http::response(['data' => [['id' => 'gpt-4o']]])]);

        $user = User::factory()->create();
        $profile = AgentProfile::create($this->profileAttributes($user, ['api_token' => 'sk-stored']));

        $response = $this->actingAs($user)->postJson('/sorify/agent/models', [
            'profile_id' => $profile->id,
            'api_token' => null,
        ]);

        $response->assertOk()->assertJsonPath('models.0', 'gpt-4o');

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer sk-stored'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function profileAttributes(User $user, array $overrides = []): array
    {
        return [
            'user_id' => $user->id,
            'name' => 'My OpenAI',
            'base_url' => 'https://api.openai.com',
            'api_token' => 'sk-test',
            'history_retention_days' => 30,
            ...$overrides,
        ];
    }
}
