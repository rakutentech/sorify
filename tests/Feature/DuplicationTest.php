<?php

namespace Tests\Feature;

use App\Jobs\DuplicateTestSuiteJob;
use App\Mcp\Servers\SorifyServer;
use App\Mcp\Tools\Suites\DuplicateSuiteTool;
use App\Mcp\Tools\Tests\DuplicateTestTool;
use App\Models\Test;
use App\Models\TestSuite;
use App\Models\User;
use App\Services\TestSuiteDuplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DuplicationTest extends TestCase
{
    use RefreshDatabase;

    private function makeSuiteWithTests(User $owner, int $testCount = 3, array $suiteAttrs = []): TestSuite
    {
        $suite = TestSuite::create(array_merge([
            'name' => 'Original Suite',
            'description' => 'A suite worth copying',
            'base_url' => 'https://example.com',
            'browser' => 'firefox',
            'headless' => false,
            'history_retention' => 10,
            'timeout_ms' => 60000,
            'max_retries' => 2,
            'take_screenshot' => false,
            'playwright_proxy' => 'http://proxy.example.com:8080',
            'teams_webhook_url' => 'https://example.test/teams',
            'teams_notify_on_start' => true,
            'teams_notify_on_success' => true,
            'teams_notify_on_failure' => true,
            'created_by' => $owner->id,
        ], $suiteAttrs));

        $suite->members()->attach($owner->id, [
            'can_view' => true,
            'can_edit' => true,
            'can_delete' => true,
            'can_run' => true,
        ]);

        $suite->proxyRules()->createMany([
            ['domain' => '^example\\.com$', 'proxy' => 'http://proxy.example.com:8080'],
            ['domain' => '(^|\\.)foo\\.com$', 'proxy' => 'http://foo-proxy:8080'],
        ]);

        $suite->integrations()->create([
            'type' => 'github_action',
            'label' => 'Deploy',
            'config' => [
                'repository' => 'acme/app',
                'workflow' => 'deploy.yml',
                'ref' => 'main',
                'inputs' => ['environment' => 'staging'],
            ],
            'enabled' => true,
            'trigger_before' => true,
            'trigger_after' => false,
        ]);

        for ($i = 1; $i <= $testCount; $i++) {
            $suite->tests()->create([
                'name' => "Test {$i}",
                'description' => "Description for test {$i}",
                'uploaded_by' => $owner->email,
                'playwright_code' => "test('test {$i}', () => {});",
                'status' => $i % 2 === 0 ? 'disabled' : 'active',
            ]);
        }

        return $suite->fresh();
    }

    // ─── HTTP: suite duplication ───────────────────────────────────────────

    public function test_admin_can_duplicate_a_suite_and_lands_on_the_clone(): void
    {
        Queue::fake();

        $admin = User::factory()->admin()->create();
        $suite = $this->makeSuiteWithTests($admin, testCount: 3);

        $response = $this->actingAs($admin)
            ->post("/sorify/suites/{$suite->id}/duplicate", []);

        $clone = TestSuite::where('name', 'Original Suite (copy)')->first();
        $this->assertNotNull($clone);

        $response->assertRedirect(route('suites.show', $clone, absolute: false));

        // The shell is created synchronously with the right shape + status.
        $this->assertSame('pending', $clone->duplication_status);
        $this->assertSame($suite->id, $clone->duplicated_from_suite_id);
        $this->assertSame('firefox', $clone->browser);
        $this->assertSame(false, $clone->headless);
        $this->assertSame(60000, $clone->timeout_ms);
        $this->assertSame(2, $clone->max_retries);
        $this->assertSame('http://proxy.example.com:8080', $clone->playwright_proxy);
        $this->assertSame('https://example.test/teams', $clone->teams_webhook_url);
        $this->assertTrue($clone->teams_notify_on_start);
        $this->assertTrue($clone->teams_notify_on_success);
        $this->assertTrue($clone->teams_notify_on_failure);

        // Proxy rules were copied.
        $this->assertSame(2, $clone->proxyRules()->count());
        $this->assertSame(
            $suite->proxyRules->pluck('domain')->all(),
            $clone->proxyRules->pluck('domain')->all(),
        );

        // Integrations were copied.
        $this->assertSame(1, $clone->integrations()->count());
        $cloneIntegration = $clone->integrations()->first();
        $this->assertSame('github_action', $cloneIntegration->type);
        $this->assertSame('Deploy', $cloneIntegration->label);
        $this->assertSame('acme/app', $cloneIntegration->config('repository'));
        $this->assertSame('deploy.yml', $cloneIntegration->config('workflow'));
        $this->assertSame(['environment' => 'staging'], $cloneIntegration->config('inputs'));
        $this->assertTrue($cloneIntegration->trigger_before);
        $this->assertFalse($cloneIntegration->trigger_after);

        // The calling user is attached as a full member.
        $this->assertDatabaseHas('test_suite_user', [
            'test_suite_id' => $clone->id,
            'user_id' => $admin->id,
            'can_view' => true,
            'can_edit' => true,
            'can_delete' => true,
            'can_run' => true,
        ]);

        // Tests are NOT copied synchronously — that's the job's job.
        $this->assertSame(0, $clone->tests()->count());

        // A fresh webhook token is generated, not the source's.
        $this->assertNotSame($suite->webhook_token, $clone->webhook_token);

        // The job was dispatched with source + target.
        Queue::assertPushed(DuplicateTestSuiteJob::class, function ($job) use ($suite, $clone) {
            return $job->source->id === $suite->id && $job->target->id === $clone->id;
        });
    }

    public function test_duplicate_suite_accepts_a_custom_name(): void
    {
        Queue::fake();

        $admin = User::factory()->admin()->create();
        $suite = $this->makeSuiteWithTests($admin, testCount: 1);

        $this->actingAs($admin)
            ->post("/sorify/suites/{$suite->id}/duplicate", ['name' => 'My Custom Clone Name'])
            ->assertRedirect();

        $this->assertDatabaseHas('test_suites', ['name' => 'My Custom Clone Name']);
    }

    public function test_duplicate_suite_bumps_copy_suffix_when_source_already_ends_with_copy(): void
    {
        Queue::fake();

        $admin = User::factory()->admin()->create();
        $suite = $this->makeSuiteWithTests($admin, testCount: 1, suiteAttrs: ['name' => 'Original Suite (copy)']);

        $this->actingAs($admin)
            ->post("/sorify/suites/{$suite->id}/duplicate")
            ->assertRedirect();

        $this->assertDatabaseHas('test_suites', ['name' => 'Original Suite (copy 2)']);
    }

    public function test_duplicate_suite_bumps_copy_n_suffix_when_source_already_ends_with_copy_n(): void
    {
        Queue::fake();

        $admin = User::factory()->admin()->create();
        $suite = $this->makeSuiteWithTests($admin, testCount: 1, suiteAttrs: ['name' => 'Original Suite (copy 3)']);

        $this->actingAs($admin)
            ->post("/sorify/suites/{$suite->id}/duplicate")
            ->assertRedirect();

        $this->assertDatabaseHas('test_suites', ['name' => 'Original Suite (copy 4)']);
    }

    public function test_non_member_cannot_duplicate_a_suite(): void
    {
        Queue::fake();

        $owner = User::factory()->admin()->create();
        $suite = $this->makeSuiteWithTests($owner, testCount: 1);

        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->post("/sorify/suites/{$suite->id}/duplicate")
            ->assertForbidden();

        Queue::assertNotPushed(DuplicateTestSuiteJob::class);
        $this->assertDatabaseMissing('test_suites', ['duplicated_from_suite_id' => $suite->id]);
    }

    public function test_view_only_user_cannot_duplicate_a_suite(): void
    {
        Queue::fake();

        $owner = User::factory()->admin()->create();
        $suite = $this->makeSuiteWithTests($owner, testCount: 1);

        $viewer = User::factory()->viewOnly()->create();
        $suite->members()->attach($viewer->id, [
            'can_view' => true,
            'can_edit' => false,
            'can_delete' => false,
            'can_run' => false,
        ]);

        // view-only users can't pass the `create` gate on TestSuite.
        $this->actingAs($viewer)
            ->post("/sorify/suites/{$suite->id}/duplicate")
            ->assertForbidden();

        Queue::assertNotPushed(DuplicateTestSuiteJob::class);
    }

    public function test_member_with_view_only_can_view_source_but_not_duplicate(): void
    {
        Queue::fake();

        $owner = User::factory()->admin()->create();
        $suite = $this->makeSuiteWithTests($owner, testCount: 1);

        $member = User::factory()->create();
        $suite->members()->attach($member->id, [
            'can_view' => true,
            'can_edit' => false,
            'can_delete' => false,
            'can_run' => false,
        ]);

        // The member can view, and can create a new suite — the policy
        // `create` only blocks view-only users, so this should succeed.
        $this->actingAs($member)
            ->post("/sorify/suites/{$suite->id}/duplicate")
            ->assertRedirect();

        Queue::assertPushed(DuplicateTestSuiteJob::class);
    }

    // ─── HTTP: test duplication ────────────────────────────────────────────

    public function test_admin_can_duplicate_a_single_test_in_the_same_suite(): void
    {
        $admin = User::factory()->admin()->create();
        $suite = $this->makeSuiteWithTests($admin, testCount: 1);
        $source = $suite->tests()->first();

        $response = $this->actingAs($admin)
            ->post("/sorify/suites/{$suite->id}/tests/{$source->id}/duplicate");

        $clone = Test::where('name', 'Test 1 (copy)')->first();
        $this->assertNotNull($clone);

        $response->assertRedirect(route('suites.tests.show', [$suite, $clone], absolute: false));

        $this->assertSame($suite->id, $clone->test_suite_id);
        $this->assertSame($source->description, $clone->description);
        $this->assertSame($source->uploaded_by, $clone->uploaded_by);
        $this->assertSame($source->playwright_code, $clone->playwright_code);
        $this->assertSame($source->status, $clone->status);

        // The original is untouched.
        $this->assertModelExists($source);
        $this->assertSame(2, $suite->tests()->count());
    }

    public function test_duplicate_test_accepts_a_target_suite_id(): void
    {
        $admin = User::factory()->admin()->create();
        $source = $this->makeSuiteWithTests($admin, testCount: 1);
        $target = TestSuite::create(['name' => 'Other Suite', 'base_url' => 'https://other.example.com']);

        $originalTest = $source->tests()->first();

        $this->actingAs($admin)
            ->post("/sorify/suites/{$source->id}/tests/{$originalTest->id}/duplicate", [
                'target_suite_id' => $target->id,
            ])
            ->assertRedirect();

        $this->assertSame(1, $target->tests()->count());
        $this->assertSame('Test 1 (copy)', $target->tests()->first()->name);
        $this->assertSame(1, $source->tests()->count()); // unchanged
    }

    public function test_non_member_cannot_duplicate_a_test(): void
    {
        $owner = User::factory()->admin()->create();
        $suite = $this->makeSuiteWithTests($owner, testCount: 1);
        $test = $suite->tests()->first();

        $stranger = User::factory()->create();

        $this->actingAs($stranger)
            ->post("/sorify/suites/{$suite->id}/tests/{$test->id}/duplicate")
            ->assertForbidden();

        $this->assertSame(1, $suite->tests()->count());
    }

    public function test_member_without_edit_cannot_duplicate_a_test_into_their_suite(): void
    {
        $owner = User::factory()->admin()->create();
        $suite = $this->makeSuiteWithTests($owner, testCount: 1);
        $test = $suite->tests()->first();

        $member = User::factory()->create();
        $suite->members()->attach($member->id, [
            'can_view' => true,
            'can_edit' => false,
            'can_delete' => false,
            'can_run' => false,
        ]);

        // Default target = source suite, but the member has no edit there.
        $this->actingAs($member)
            ->post("/sorify/suites/{$suite->id}/tests/{$test->id}/duplicate")
            ->assertForbidden();
    }

    public function test_bulk_duplicate_copies_multiple_tests_with_auto_names(): void
    {
        $admin = User::factory()->admin()->create();
        $suite = $this->makeSuiteWithTests($admin, testCount: 3);
        $ids = $suite->tests()->pluck('id')->all();

        $this->actingAs($admin)
            ->post("/sorify/suites/{$suite->id}/tests/bulk/duplicate", ['test_ids' => $ids])
            ->assertRedirect();

        // Each test got a "(copy)" clone — originals are untouched.
        $this->assertSame(6, $suite->tests()->count());
        $this->assertDatabaseHas('tests', ['test_suite_id' => $suite->id, 'name' => 'Test 1 (copy)']);
        $this->assertDatabaseHas('tests', ['test_suite_id' => $suite->id, 'name' => 'Test 2 (copy)']);
        $this->assertDatabaseHas('tests', ['test_suite_id' => $suite->id, 'name' => 'Test 3 (copy)']);
    }

    public function test_bulk_duplicate_denies_member_without_edit(): void
    {
        $owner = User::factory()->admin()->create();
        $suite = $this->makeSuiteWithTests($owner, testCount: 2);
        $ids = $suite->tests()->pluck('id')->all();

        $member = User::factory()->create();
        $suite->members()->attach($member->id, [
            'can_view' => true,
            'can_edit' => false,
            'can_delete' => false,
            'can_run' => false,
        ]);

        $this->actingAs($member)
            ->post("/sorify/suites/{$suite->id}/tests/bulk/duplicate", ['test_ids' => $ids])
            ->assertForbidden();

        $this->assertSame(2, $suite->tests()->count());
    }

    public function test_bulk_duplicate_requires_at_least_one_test_id(): void
    {
        $admin = User::factory()->admin()->create();
        $suite = $this->makeSuiteWithTests($admin, testCount: 1);

        $this->actingAs($admin)
            ->post("/sorify/suites/{$suite->id}/tests/bulk/duplicate", ['test_ids' => []])
            ->assertSessionHasErrors(['test_ids']);
    }

    // ─── Job: copies tests ─────────────────────────────────────────────────

    public function test_duplicate_test_suite_job_copies_all_tests_in_chunks_and_marks_complete(): void
    {
        $admin = User::factory()->admin()->create();
        $source = $this->makeSuiteWithTests($admin, testCount: 5);

        $target = TestSuite::create([
            'name' => 'Target',
            'duplication_status' => 'pending',
            'duplicated_from_suite_id' => $source->id,
            'created_by' => $admin->id,
        ]);

        (new DuplicateTestSuiteJob($source, $target))->handle(
            app(TestSuiteDuplicationService::class),
        );

        $target->refresh();

        $this->assertSame('complete', $target->duplication_status);
        $this->assertSame(5, $target->tests()->count());

        // Each copied test carries the source's data.
        $copied = $target->tests()->orderBy('id')->get();
        $originals = $source->tests()->orderBy('id')->get();

        $copied->each(function ($copy, $i) use ($originals) {
            $this->assertSame($originals[$i]->name, $copy->name);
            $this->assertSame($originals[$i]->description, $copy->description);
            $this->assertSame($originals[$i]->uploaded_by, $copy->uploaded_by);
            $this->assertSame($originals[$i]->playwright_code, $copy->playwright_code);
            $this->assertSame($originals[$i]->status, $copy->status);
        });
    }

    public function test_duplicate_test_suite_job_handles_more_than_one_chunk(): void
    {
        $admin = User::factory()->admin()->create();
        $source = $this->makeSuiteWithTests($admin, testCount: 250);

        $target = TestSuite::create([
            'name' => 'Target',
            'duplication_status' => 'pending',
            'duplicated_from_suite_id' => $source->id,
            'created_by' => $admin->id,
        ]);

        (new DuplicateTestSuiteJob($source, $target))->handle(
            app(TestSuiteDuplicationService::class),
        );

        $target->refresh();
        $this->assertSame('complete', $target->duplication_status);
        $this->assertSame(250, $target->tests()->count());
    }

    public function test_duplicate_test_suite_job_finalises_complete_when_source_was_deleted(): void
    {
        $admin = User::factory()->admin()->create();
        $source = $this->makeSuiteWithTests($admin, testCount: 3);

        $target = TestSuite::create([
            'name' => 'Target',
            'duplication_status' => 'pending',
            'duplicated_from_suite_id' => $source->id,
            'created_by' => $admin->id,
        ]);

        $sourceId = $source->id;
        $source->delete();

        (new DuplicateTestSuiteJob($source, $target))->handle(
            app(TestSuiteDuplicationService::class),
        );

        $target->refresh();
        $this->assertSame('complete', $target->duplication_status);
        $this->assertSame(0, $target->tests()->count());
        $this->assertDatabaseMissing('test_suites', ['id' => $sourceId]);
    }

    public function test_duplicate_test_suite_job_bails_silently_when_target_was_deleted(): void
    {
        $admin = User::factory()->admin()->create();
        $source = $this->makeSuiteWithTests($admin, testCount: 1);

        $target = TestSuite::create([
            'name' => 'Target',
            'duplication_status' => 'pending',
            'duplicated_from_suite_id' => $source->id,
            'created_by' => $admin->id,
        ]);

        $targetId = $target->id;
        $target->delete();

        // No exception, nothing to assert beyond "didn't crash".
        (new DuplicateTestSuiteJob($source, $target))->handle(
            app(TestSuiteDuplicationService::class),
        );

        $this->assertDatabaseMissing('test_suites', ['id' => $targetId]);
    }

    public function test_duplicate_test_suite_job_marks_failed_on_copy_error(): void
    {
        $admin = User::factory()->admin()->create();
        $source = $this->makeSuiteWithTests($admin, testCount: 1);

        $target = TestSuite::create([
            'name' => 'Target',
            'duplication_status' => 'pending',
            'duplicated_from_suite_id' => $source->id,
            'created_by' => $admin->id,
        ]);

        $service = $this->partialMock(TestSuiteDuplicationService::class);
        $service->shouldReceive('copyTests')
            ->andThrow(new \RuntimeException('boom'));

        try {
            (new DuplicateTestSuiteJob($source, $target))->handle($service);
            $this->fail('Expected the job to rethrow the copy error.');
        } catch (\RuntimeException $e) {
            $this->assertSame('boom', $e->getMessage());
        }

        $target->refresh();
        $this->assertSame('failed', $target->duplication_status);
    }

    // ─── MCP: tools ─────────────────────────────────────────────────────────

    public function test_mcp_duplicate_suite_creates_clone_and_dispatches_job(): void
    {
        Queue::fake();

        $admin = User::factory()->admin()->create();
        $suite = $this->makeSuiteWithTests($admin, testCount: 2);

        SorifyServer::actingAs($admin)
            ->tool(DuplicateSuiteTool::class, ['suite_id' => $suite->id])
            ->assertOk();

        $clone = TestSuite::where('duplicated_from_suite_id', $suite->id)->first();
        $this->assertNotNull($clone);
        $this->assertSame('pending', $clone->duplication_status);
        $this->assertSame('Original Suite (copy)', $clone->name);

        Queue::assertPushed(DuplicateTestSuiteJob::class);
    }

    public function test_mcp_duplicate_suite_denies_non_member(): void
    {
        Queue::fake();

        $owner = User::factory()->admin()->create();
        $suite = $this->makeSuiteWithTests($owner, testCount: 1);

        $stranger = User::factory()->create();

        SorifyServer::actingAs($stranger)
            ->tool(DuplicateSuiteTool::class, ['suite_id' => $suite->id])
            ->assertHasErrors(['This action is unauthorized']);

        Queue::assertNotPushed(DuplicateTestSuiteJob::class);
    }

    public function test_mcp_duplicate_test_creates_a_copy_in_the_same_suite(): void
    {
        $admin = User::factory()->admin()->create();
        $suite = $this->makeSuiteWithTests($admin, testCount: 1);
        $source = $suite->tests()->first();

        SorifyServer::actingAs($admin)
            ->tool(DuplicateTestTool::class, [
                'suite_id' => $suite->id,
                'test_id' => $source->id,
            ])
            ->assertOk();

        $clone = Test::where('name', 'Test 1 (copy)')->first();
        $this->assertNotNull($clone);
        $this->assertSame($suite->id, $clone->test_suite_id);
        $this->assertSame($source->playwright_code, $clone->playwright_code);
    }

    public function test_mcp_duplicate_test_supports_target_suite_id(): void
    {
        $admin = User::factory()->admin()->create();
        $source = $this->makeSuiteWithTests($admin, testCount: 1);
        $target = TestSuite::create(['name' => 'Other', 'base_url' => 'https://other.example.com']);

        $originalTest = $source->tests()->first();

        SorifyServer::actingAs($admin)
            ->tool(DuplicateTestTool::class, [
                'suite_id' => $source->id,
                'test_id' => $originalTest->id,
                'target_suite_id' => $target->id,
                'name' => 'Cloned Into Other Suite',
            ])
            ->assertOk();

        $this->assertSame(1, $target->tests()->count());
        $this->assertSame('Cloned Into Other Suite', $target->tests()->first()->name);
    }

    public function test_mcp_duplicate_test_denies_edit_on_target(): void
    {
        $owner = User::factory()->admin()->create();
        $sourceSuite = $this->makeSuiteWithTests($owner, testCount: 1);
        $targetSuite = TestSuite::create(['name' => 'Target', 'base_url' => 'https://target.example.com']);

        $member = User::factory()->create();
        $sourceSuite->members()->attach($member->id, [
            'can_view' => true,
            'can_edit' => false,
            'can_delete' => false,
            'can_run' => false,
        ]);

        $test = $sourceSuite->tests()->first();

        SorifyServer::actingAs($member)
            ->tool(DuplicateTestTool::class, [
                'suite_id' => $sourceSuite->id,
                'test_id' => $test->id,
                'target_suite_id' => $targetSuite->id,
            ])
            ->assertHasErrors(['This action is unauthorized']);
    }

    public function test_mcp_duplicate_suite_and_test_are_listed_in_tools_list(): void
    {
        $admin = User::factory()->create(['password' => 'password123']);

        // tools/list is paginated (default 15 per page); walk every page so
        // we don't miss a tool that's past the first page.
        $names = [];
        $cursor = null;

        do {
            $payload = [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/list',
                'params' => array_filter(['per_page' => 100, 'cursor' => $cursor]),
            ];

            $response = $this->withBasicAuth($admin->email, 'password123')
                ->postJson('/sorify/mcp', $payload, ['Accept' => 'application/json, text/event-stream']);

            $response->assertOk();

            $body = json_decode($response->getContent(), true);
            $names = array_merge($names, collect($body['result']['tools'] ?? [])
                ->pluck('name')
                ->all());
            $cursor = $body['result']['nextCursor'] ?? null;
        } while ($cursor !== null);

        $this->assertContains('duplicate_suite', $names);
        $this->assertContains('duplicate_test', $names);
    }
}
