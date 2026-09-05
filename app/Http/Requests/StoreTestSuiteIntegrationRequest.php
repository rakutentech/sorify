<?php

namespace App\Http\Requests;

use App\Models\TestSuiteIntegration;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for a single suite integration. The payload is deliberately
 * flat (repository / workflow / ref / inputs rows) rather than a nested
 * config object — the stored config shape is assembled by
 * App\Support\IntegrationPayload::normalize().
 *
 * The rules are shared (via rulesFor()) with the API suite request, which
 * validates whole `integrations` arrays for the MCP tools.
 */
class StoreTestSuiteIntegrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return static::rulesFor();
    }

    /**
     * Rules for one integration payload. With a prefix, the rules validate a
     * nested item — pass a wildcard prefix ("integrations.*") to validate
     * every element of an array payload.
     *
     * Type-conditional fields: repository/workflow belong to github_action,
     * url/method to http_request. Input/header rows are plain {name, value}
     * pairs; "sorify_"-prefixed input names are reserved for context
     * Sorify injects into every dispatch/request.
     *
     * @return array<string, string|array>
     */
    public static function rulesFor(string $prefix = ''): array
    {
        $key = $prefix === '' ? '' : $prefix.'.';

        return [
            "{$key}type" => 'required|string|in:'.implode(',', TestSuiteIntegration::TYPES),
            // github_action only: which configured GitHub App (public GitHub
            // or a GitHub Enterprise instance) to dispatch as. Null falls
            // back to the default app at dispatch time.
            "{$key}github_app_id" => ['nullable', 'integer', Rule::exists('github_apps', 'id')],
            "{$key}label" => 'nullable|string|max:100',
            "{$key}repository" => 'required_if:'.$key.'type,github_action|string|max:255|regex:/^[A-Za-z0-9_.\-]+\/[A-Za-z0-9_.\-]+$/',
            "{$key}workflow" => 'required_if:'.$key.'type,github_action|string|max:255|regex:/^[A-Za-z0-9_.\-\/]+$/',
            "{$key}ref" => 'nullable|string|max:255',
            "{$key}url" => 'required_if:'.$key.'type,http_request|string|max:2048|starts_with:http://,https://',
            "{$key}method" => 'nullable|string|in:GET,POST,PUT,DELETE',
            // http_request only: raw JSON body, sent as-is on POST/PUT.
            "{$key}body" => 'nullable|string|max:65535|json',
            // Optional per-integration proxy for the outgoing requests
            // (GitHub API calls / the HTTP request itself).
            "{$key}proxy" => 'nullable|string|max:500',
            "{$key}inputs" => 'nullable|array|max:20',
            "{$key}inputs.*.name" => 'required|string|max:100|regex:/^[A-Za-z_][A-Za-z0-9_\-]*$/',
            "{$key}inputs.*.value" => 'nullable|string|max:2000',
            // http_request only: extra request headers (e.g. Authorization).
            // Array form — the regex character class contains a literal pipe,
            // which would split a pipe-separated rule string.
            "{$key}headers" => 'nullable|array|max:20',
            "{$key}headers.*.name" => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/'],
            "{$key}headers.*.value" => 'nullable|string|max:2000',
            "{$key}enabled" => 'nullable|boolean',
            "{$key}trigger_before" => 'nullable|boolean',
            "{$key}trigger_after" => 'nullable|boolean',
        ];
    }
}
