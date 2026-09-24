<?php

namespace Tests\Feature;

use App\Models\AgentConversation;
use App\Models\AgentProfile;
use App\Models\Setting;
use App\Models\User;
use App\Services\Agent\AgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * The admin-configurable global system prompt: injected into every agent
 * conversation (above per-profile instructions) and managed from the
 * admin System page.
 */
class AgentGlobalPromptTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_prompt_is_injected_into_the_system_message(): void
    {
        Setting::set('agent_global_system_prompt', 'Never touch the host filesystem.');

        $message = $this->systemMessage();

        $this->assertSame('system', $message['role']);
        $this->assertStringContainsString('Global operator instructions', $message['content']);
        $this->assertStringContainsString('Never touch the host filesystem.', $message['content']);
    }

    public function test_global_prompt_is_omitted_when_not_set(): void
    {
        $message = $this->systemMessage();

        $this->assertStringNotContainsString('Global operator instructions', $message['content']);
    }

    public function test_global_prompt_precedes_profile_instructions(): void
    {
        Setting::set('agent_global_system_prompt', 'OPERATOR RULE');

        $message = $this->systemMessage(profilePrompt: 'PROFILE RULE');

        $this->assertLessThan(
            strpos($message['content'], 'PROFILE RULE'),
            strpos($message['content'], 'OPERATOR RULE'),
        );
    }

    public function test_admin_can_save_the_global_prompt(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->putJson('/sorify/admin/system/agent-prompt', [
            'agent_global_system_prompt' => 'Stay on the target site only.',
        ]);

        $response->assertOk();
        $this->assertSame('Stay on the target site only.', Setting::get('agent_global_system_prompt'));
    }

    public function test_saving_an_empty_prompt_clears_the_setting(): void
    {
        Setting::set('agent_global_system_prompt', 'Old rule');
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->putJson('/sorify/admin/system/agent-prompt', [
            'agent_global_system_prompt' => '   ',
        ]);

        $response->assertOk();
        $this->assertNull(Setting::get('agent_global_system_prompt'));
    }

    public function test_non_admins_cannot_save_the_global_prompt(): void
    {
        $user = User::factory()->create(['is_admin' => false]);

        $response = $this->actingAs($user)->putJson('/sorify/admin/system/agent-prompt', [
            'agent_global_system_prompt' => 'No rules.',
        ]);

        $response->assertForbidden();
        $this->assertNull(Setting::get('agent_global_system_prompt'));
    }

    public function test_the_global_prompt_is_rejected_when_too_long(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->putJson('/sorify/admin/system/agent-prompt', [
            'agent_global_system_prompt' => str_repeat('x', 20001),
        ]);

        $response->assertInvalid('agent_global_system_prompt');
    }

    public function test_the_admin_system_page_receives_the_prompt(): void
    {
        Setting::set('agent_global_system_prompt', 'Displayed rule');
        $admin = User::factory()->create(['is_admin' => true]);

        $response = $this->actingAs($admin)->get('/sorify/admin/system');

        $response->assertOk()->assertInertia(fn ($page) => $page
            ->where('agentGlobalPrompt', 'Displayed rule')
            ->etc());
    }

    /**
     * @return array{role: string, content: string}
     */
    private function systemMessage(?string $profilePrompt = null): array
    {
        $user = User::factory()->create();

        $conversation = AgentConversation::create(['user_id' => $user->id]);

        $profile = AgentProfile::create([
            'user_id' => $user->id,
            'name' => 'My OpenAI',
            'base_url' => 'https://api.openai.com',
            'api_token' => 'sk-secret',
            'history_retention_days' => 30,
            'system_prompt' => $profilePrompt,
        ]);

        $service = (new ReflectionClass(AgentService::class))->newInstanceWithoutConstructor();

        $method = new ReflectionMethod(AgentService::class, 'systemMessage');
        $method->setAccessible(true);

        return $method->invoke($service, $conversation, $profile);
    }
}
