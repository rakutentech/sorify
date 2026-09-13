<?php

namespace Tests\Unit\Mcp\Tools;

use App\Mcp\Servers\SorifyServer;
use App\Mcp\Tools\Agent\GetAgentConversationTool;
use App\Mcp\Tools\Agent\ListAgentConversationsTool;
use App\Models\AgentConversation;
use App\Models\AgentMessage;
use App\Models\AgentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentConversationsToolsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private AgentProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        $this->profile = AgentProfile::create([
            'user_id' => $this->user->id,
            'name' => 'My OpenAI',
            'base_url' => 'https://api.openai.com',
            'api_token' => 'sk-secret',
            'history_retention_days' => 30,
        ]);
    }

    public function test_list_agent_conversations_returns_own_conversations(): void
    {
        AgentConversation::create([
            'user_id' => $this->user->id,
            'agent_profile_id' => $this->profile->id,
            'title' => 'Checkout flow tests',
            'page_name' => 'TestSuites/Show',
            'page_url' => '/sorify/suites/12',
        ]);

        // Another user's conversation must never be listed.
        AgentConversation::create([
            'user_id' => User::factory()->create()->id,
            'agent_profile_id' => $this->profile->id,
            'title' => 'Someone else',
        ]);

        SorifyServer::actingAs($this->user)
            ->tool(ListAgentConversationsTool::class)
            ->assertOk()
            ->assertHasNoErrors()
            ->assertSee('Checkout flow tests')
            ->assertDontSee('Someone else');
    }

    public function test_get_agent_conversation_returns_the_transcript(): void
    {
        $conversation = AgentConversation::create([
            'user_id' => $this->user->id,
            'agent_profile_id' => $this->profile->id,
            'title' => 'Crawl the docs site',
            'page_name' => 'TestSuites/Show',
            'page_url' => '/sorify/suites/3',
            'context' => 'User is viewing suite 3 (Docs site).',
        ]);

        AgentMessage::create([
            'turn_id' => 1,
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => 'Write regression tests for the search flow',
        ]);

        AgentMessage::create([
            'turn_id' => 1,
            'conversation_id' => $conversation->id,
            'role' => 'assistant',
            'content' => null,
            'tool_calls' => [[
                'id' => 'call_1',
                'type' => 'function',
                'function' => ['name' => 'browser_map', 'arguments' => '{"suite_id":3,"url":"https://docs.example.com"}'],
            ]],
        ]);

        AgentMessage::create([
            'turn_id' => 1,
            'conversation_id' => $conversation->id,
            'role' => 'tool',
            'content' => '{"url":"https://docs.example.com","title":"Docs"}',
            'tool_call_id' => 'call_1',
            'name' => 'browser_map',
        ]);

        SorifyServer::actingAs($this->user)
            ->tool(GetAgentConversationTool::class, ['conversation_id' => $conversation->id])
            ->assertOk()
            ->assertHasNoErrors()
            ->assertSee('Write regression tests for the search flow')
            ->assertSee('browser_map')
            ->assertSee('User is viewing suite 3');
    }

    public function test_get_agent_conversation_rejects_foreign_conversations(): void
    {
        $foreign = AgentConversation::create([
            'user_id' => User::factory()->create()->id,
            'agent_profile_id' => $this->profile->id,
            'title' => 'Someone else',
        ]);

        SorifyServer::actingAs($this->user)
            ->tool(GetAgentConversationTool::class, ['conversation_id' => $foreign->id])
            ->assertHasErrors()
            ->assertSee('No conversation found');
    }
}
