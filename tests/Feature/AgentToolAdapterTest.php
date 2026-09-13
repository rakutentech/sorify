<?php

namespace Tests\Feature;

use App\Models\TestSuite;
use App\Models\User;
use App\Services\Agent\AgentToolAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentToolAdapterTest extends TestCase
{
    use RefreshDatabase;

    private AgentToolAdapter $adapter;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adapter = app(AgentToolAdapter::class);
    }

    public function test_definitions_cover_the_server_toolset_with_valid_schemas(): void
    {
        $definitions = $this->adapter->definitions();
        $names = array_map(fn ($definition) => $definition['function']['name'], $definitions);

        $this->assertContains('bulk_create_tests', $names);
        $this->assertContains('trigger_run', $names);
        $this->assertContains('fetch_url', $names);
        $this->assertContains('browser_map', $names);

        // Binary image content cannot be a text tool result.
        $this->assertNotContains('get_screenshot', $names);

        foreach ($definitions as $definition) {
            $this->assertSame('function', $definition['type']);

            $parameters = $definition['function']['parameters'];

            $this->assertSame('object', $parameters['type']);
            $this->assertArrayHasKey('properties', $parameters);

            // Names must be valid for the OpenAI tool-calling API.
            $this->assertMatchesRegularExpression('/^[a-zA-Z0-9_-]{1,64}$/', $definition['function']['name']);
        }
    }

    public function test_unknown_tool_returns_error(): void
    {
        $result = $this->adapter->execute('definitely_not_a_tool', []);

        $this->assertTrue($result['is_error']);
        $this->assertStringContainsString('Unknown tool', $result['result']);
    }

    public function test_excluded_tool_cannot_be_executed_directly(): void
    {
        $result = $this->adapter->execute('get_screenshot', ['screenshot_id' => 1]);

        $this->assertTrue($result['is_error']);
    }

    public function test_tool_failure_is_reported_as_error_result(): void
    {
        // list_tests needs a suite the user cannot see — no auth as any
        // member, so the gate rejects and the adapter surfaces an error.
        $suite = TestSuite::create(['name' => 'S', 'base_url' => 'https://example.com', 'created_by' => User::factory()->create()->id]);

        $this->actingAs(User::factory()->create());

        $result = $this->adapter->execute('list_tests', ['suite_id' => $suite->id]);

        $this->assertTrue($result['is_error']);
    }

    public function test_tool_executes_with_the_authenticated_user(): void
    {
        $user = User::factory()->create();
        $suite = TestSuite::create(['name' => 'Suite Name Here', 'base_url' => 'https://example.com', 'created_by' => $user->id]);
        $suite->members()->attach($user->id, ['can_view' => true, 'can_edit' => true]);

        $this->actingAs($user);

        $result = $this->adapter->execute('get_suite', ['suite_id' => $suite->id]);

        $this->assertFalse($result['is_error'], $result['result']);
        $this->assertStringContainsString('Suite Name Here', $result['result']);
    }

    public function test_agent_meta_is_recorded_as_code_attribution(): void
    {
        $user = User::factory()->create();
        $suite = TestSuite::create(['name' => 'S', 'base_url' => 'https://example.com', 'created_by' => $user->id]);
        $suite->members()->attach($user->id, ['can_view' => true, 'can_edit' => true]);

        $test = $suite->tests()->create(['name' => 'A', 'playwright_code' => 'old']);

        $this->actingAs($user);

        // The agent chat injects its model as ground-truth meta.
        $result = $this->adapter->execute('update_test_code', [
            'suite_id' => $suite->id,
            'test_id' => $test->id,
            'playwright_code' => 'await page.goto("/new");',
        ], ['ai_model' => 'gpt-4o', 'via' => 'agent']);

        $this->assertFalse($result['is_error'], $result['result']);

        $this->assertDatabaseHas('tests', [
            'id' => $test->id,
            'code_source' => 'agent',
            'code_ai_model' => 'gpt-4o',
        ]);
    }

    public function test_agent_meta_wins_over_self_reported_model(): void
    {
        $user = User::factory()->create();
        $suite = TestSuite::create(['name' => 'S', 'base_url' => 'https://example.com', 'created_by' => $user->id]);
        $suite->members()->attach($user->id, ['can_view' => true, 'can_edit' => true]);

        $test = $suite->tests()->create(['name' => 'A', 'playwright_code' => 'old']);

        $this->actingAs($user);

        // An LLM might self-report a wrong name; the profile's model wins.
        $this->adapter->execute('update_test_code', [
            'suite_id' => $suite->id,
            'test_id' => $test->id,
            'playwright_code' => 'await page.goto("/new");',
            'ai_model' => 'hallucinated-model',
        ], ['ai_model' => 'gpt-4o', 'via' => 'agent']);

        $this->assertDatabaseHas('tests', [
            'id' => $test->id,
            'code_ai_model' => 'gpt-4o',
        ]);
    }
}
