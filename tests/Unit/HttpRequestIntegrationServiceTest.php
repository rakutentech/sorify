<?php

namespace Tests\Unit;

use App\Models\TestRun;
use App\Models\TestSuite;
use App\Models\TestSuiteIntegration;
use App\Services\Integrations\HttpRequestIntegrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class HttpRequestIntegrationServiceTest extends TestCase
{
    use RefreshDatabase;

    private TestSuite $suite;

    private TestRun $run;

    protected function setUp(): void
    {
        parent::setUp();

        $this->suite = TestSuite::create(['name' => 'Suite', 'base_url' => 'https://example.com']);
        $this->run = $this->suite->testRuns()->create([
            'status' => 'running',
            'triggered_by' => 'manual',
            'total_tests' => 2,
            'started_at' => now(),
        ]);
    }

    private function integration(array $configOverrides = [], array $overrides = []): TestSuiteIntegration
    {
        return $this->suite->integrations()->create(array_merge([
            'type' => 'http_request',
            'label' => 'Deploy hook',
            'config' => array_merge([
                'url' => 'https://example.com/api/deploy',
                'method' => 'POST',
                'inputs' => [],
                'headers' => [],
            ], $configOverrides),
            'enabled' => true,
            'trigger_before' => true,
            'trigger_after' => false,
        ], $overrides));
    }

    public function test_get_inputs_override_url_params_and_are_encoded(): void
    {
        Http::fake(['*' => Http::response(status: 200)]);

        app(HttpRequestIntegrationService::class)->executeAndWait($this->integration([
            'url' => 'https://example.com/api?env=prod&keep=1',
            'method' => 'GET',
            'inputs' => [
                'env' => 'staging',
                'evil' => 'a&admin=1#frag',
            ],
        ]), $this->run);

        Http::assertSent(function ($request) {
            $this->assertSame('GET', $request->method());

            // Input wins over the same-named URL param, unrelated URL
            // params are kept, and the hostile value is percent-encoded so
            // it can neither inject another parameter nor a fragment. The
            // sorify_* context follows after the user's own params.
            return str_starts_with($request->url(), 'https://example.com/api?env=staging&keep=1&evil=a%26admin%3D1%23frag&');
        });
    }

    public function test_get_url_params_survive_when_not_overridden(): void
    {
        Http::fake(['*' => Http::response(status: 200)]);

        app(HttpRequestIntegrationService::class)->executeAndWait($this->integration([
            'url' => 'https://example.com/api?env=prod',
            'method' => 'GET',
        ]), $this->run);

        Http::assertSent(function ($request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $params);

            return ($params['env'] ?? null) === 'prod'
                && ($params['sorify_run_id'] ?? null) === (string) $this->run->id;
        });
    }

    public function test_post_sends_inputs_as_json_body_and_keeps_url_params(): void
    {
        Http::fake(['*' => Http::response(status: 200)]);

        app(HttpRequestIntegrationService::class)->executeAndWait($this->integration([
            'url' => 'https://example.com/api?token=abc',
            'method' => 'POST',
            'inputs' => ['environment' => 'staging'],
        ]), $this->run);

        Http::assertSent(function ($request) {
            $this->assertSame('POST', $request->method());
            $this->assertStringContainsString('token=abc', $request->url());

            $body = json_decode((string) $request->body(), true);

            return ($body['environment'] ?? null) === 'staging'
                && ($body['sorify_run_id'] ?? null) === (string) $this->run->id
                && ($body['sorify_suite_id'] ?? null) === (string) $this->suite->id;
        });
    }

    public function test_raw_body_is_sent_as_is_on_post_and_put(): void
    {
        Http::fake(['*' => Http::response(status: 200)]);

        $raw = '{"deploy": true, "environment": "staging"}';

        app(HttpRequestIntegrationService::class)->executeAndWait($this->integration([
            'method' => 'POST',
            'body' => $raw,
            'inputs' => ['environment' => 'staging'],
        ]), $this->run);

        Http::assertSent(function ($request) use ($raw) {
            return $request->method() === 'POST'
                && $request->body() === $raw
                && $request->hasHeader('Content-Type', 'application/json');
        });

        app(HttpRequestIntegrationService::class)->executeAndWait($this->integration([
            'method' => 'PUT',
            'body' => $raw,
        ]), $this->run);

        Http::assertSent(function ($request) use ($raw) {
            return $request->method() === 'PUT'
                && $request->body() === $raw;
        });
    }

    public function test_raw_body_moves_inputs_to_query_params(): void
    {
        Http::fake(['*' => Http::response(status: 200)]);

        app(HttpRequestIntegrationService::class)->executeAndWait($this->integration([
            'url' => 'https://example.com/api?token=abc',
            'method' => 'POST',
            'body' => '{"deploy": true}',
            'inputs' => ['environment' => 'staging'],
        ]), $this->run);

        Http::assertSent(function ($request) {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $params);

            // URL params kept, inputs moved to the query, body untouched.
            return ($params['token'] ?? null) === 'abc'
                && ($params['environment'] ?? null) === 'staging'
                && ($params['sorify_run_id'] ?? null) === (string) $this->run->id
                && $request->body() === '{"deploy": true}';
        });
    }

    public function test_raw_body_is_ignored_on_get_and_delete(): void
    {
        Http::fake(['*' => Http::response(status: 200)]);

        app(HttpRequestIntegrationService::class)->executeAndWait($this->integration([
            'method' => 'GET',
            'body' => '{"ignored": true}',
            'inputs' => ['environment' => 'staging'],
        ]), $this->run);

        Http::assertSent(fn ($request) => $request->method() === 'GET' && ! str_contains((string) $request->body(), 'ignored'));

        app(HttpRequestIntegrationService::class)->executeAndWait($this->integration([
            'method' => 'DELETE',
            'body' => '{"ignored": true}',
            'inputs' => ['environment' => 'staging'],
        ]), $this->run);

        Http::assertSent(function ($request) {
            $body = json_decode((string) $request->body(), true);

            return $request->method() === 'DELETE'
                && ($body['environment'] ?? null) === 'staging';
        });
    }

    public function test_delete_and_put_are_supported(): void
    {
        Http::fake(['*' => Http::response(status: 204)]);

        app(HttpRequestIntegrationService::class)->executeAndWait($this->integration([
            'method' => 'DELETE',
        ]), $this->run);

        Http::assertSent(fn ($request) => $request->method() === 'DELETE');

        app(HttpRequestIntegrationService::class)->executeAndWait($this->integration([
            'method' => 'PUT',
        ]), $this->run);

        Http::assertSent(fn ($request) => $request->method() === 'PUT');
    }

    public function test_post_body_contains_only_sorify_context_when_no_inputs(): void
    {
        Http::fake(['*' => Http::response(status: 200)]);

        app(HttpRequestIntegrationService::class)->executeAndWait($this->integration(), $this->run);

        Http::assertSent(function ($request) {
            $body = json_decode((string) $request->body(), true);

            return array_keys($body ?? []) === ['sorify_run_id', 'sorify_suite_id', 'sorify_run_url'];
        });
    }

    public function test_headers_are_sent_with_the_request(): void
    {
        Http::fake(['*' => Http::response(status: 200)]);

        app(HttpRequestIntegrationService::class)->executeAndWait($this->integration([
            'headers' => [
                'X-API-Key' => 'key-123',
                'Authorization' => 'Bearer token',
            ],
        ]), $this->run);

        Http::assertSent(function ($request) {
            return $request->hasHeader('X-API-Key', 'key-123')
                && $request->hasHeader('Authorization', 'Bearer token');
        });
    }

    public function test_header_values_cannot_inject_extra_headers(): void
    {
        Http::fake(['*' => Http::response(status: 200)]);

        app(HttpRequestIntegrationService::class)->executeAndWait($this->integration([
            'headers' => ['X-Safe' => "value\r\nEvil-Header: injected"],
        ]), $this->run);

        Http::assertSent(function ($request) {
            // CR/LF are stripped, so the value arrives as one header line —
            // no Evil-Header can be smuggled in.
            return $request->hasHeader('X-Safe', 'valueEvil-Header: injected')
                && ! $request->hasHeader('Evil-Header');
        });
    }

    public function test_url_with_embedded_credentials_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must not embed credentials');

        app(HttpRequestIntegrationService::class)->executeAndWait($this->integration([
            'url' => 'https://user:pass@example.com/api',
        ]), $this->run);
    }

    public function test_non_http_scheme_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('http:// or https://');

        app(HttpRequestIntegrationService::class)->executeAndWait($this->integration([
            'url' => 'ftp://example.com/file',
        ]), $this->run);
    }

    public function test_fragment_is_stripped_from_the_url(): void
    {
        Http::fake(['*' => Http::response(status: 200)]);

        app(HttpRequestIntegrationService::class)->executeAndWait($this->integration([
            'url' => 'https://example.com/api#section',
            'method' => 'GET',
        ]), $this->run);

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://example.com/api?') && ! str_contains($request->url(), '#'));
    }

    public function test_execute_and_wait_throws_on_non_2xx(): void
    {
        Http::fake(['*' => Http::response(['error' => 'nope'], 500)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('returned 500');

        app(HttpRequestIntegrationService::class)->executeAndWait($this->integration(), $this->run);
    }

    public function test_post_run_phase_includes_outcome_counts(): void
    {
        Http::fake(['*' => Http::response(status: 200)]);

        $this->run->update([
            'status' => 'completed',
            'passed_count' => 3,
            'failed_count' => 1,
            'error_count' => 0,
        ]);

        app(HttpRequestIntegrationService::class)->dispatchForRun($this->integration(), $this->run, 'after');

        Http::assertSent(function ($request) {
            $body = json_decode((string) $request->body(), true);

            return ($body['sorify_run_status'] ?? null) === 'completed'
                && ($body['sorify_passed_count'] ?? null) === '3'
                && ($body['sorify_failed_count'] ?? null) === '1'
                && ($body['sorify_error_count'] ?? null) === '0';
        });
    }

    public function test_dispatch_for_run_swallows_request_failures(): void
    {
        Http::fake(fn () => throw new \Exception('connection refused'));

        // Must not throw despite the transport error.
        app(HttpRequestIntegrationService::class)->dispatchForRun($this->integration(), $this->run, 'after');

        $this->assertTrue(true);
    }
}
