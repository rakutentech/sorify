<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreSuiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'playwright_proxy' => 'nullable|string|max:500',
            'proxy_rules' => 'nullable|array',
            'proxy_rules.*.domain' => ['required_with:proxy_rules', 'string', 'max:255', $this->validRegexRule()],
            'proxy_rules.*.proxy' => 'required_with:proxy_rules|string|max:500',
            'variables' => 'nullable|array',
            'variables.*.key' => ['required_with:variables', 'string', 'max:255', 'regex:/^[A-Za-z_][A-Za-z0-9_]*$/'],
            'variables.*.value' => 'nullable|string',
            'cookies' => 'nullable|array',
            'cookies.*.name' => ['required_with:cookies', 'string', 'max:255'],
            'cookies.*.value' => 'nullable|string',
            'cookies.*.domain' => 'nullable|string|max:255',
            'cookies.*.path' => 'nullable|string|max:255',
            'cookies.*.url' => 'nullable|string|max:500|url',
            'cookies.*.expires' => 'nullable|integer',
            'cookies.*.http_only' => 'nullable|boolean',
            'cookies.*.secure' => 'nullable|boolean',
            'cookies.*.same_site' => 'nullable|string|in:Strict,Lax,None',
            'cookies.*' => [function (string $attribute, mixed $value, \Closure $fail) {
                if (! is_array($value)) {
                    return;
                }
                $hasUrl = ! empty($value['url']);
                $hasDomain = ! empty($value['domain']);
                if (! $hasUrl && ! $hasDomain) {
                    $fail('The :attribute must set either a domain or a url (Playwright requires one of them).');
                }
            }],
            'browser' => 'nullable|string|in:chromium,firefox,webkit',
            'headless' => 'nullable|boolean',
            'base_url' => 'nullable|string|max:500',
            'history_retention' => 'nullable|integer|in:3,5,10',
            'timeout_ms' => 'nullable|integer|in:10000,30000,60000,120000,300000,600000',
            'take_screenshot' => 'nullable|boolean',
            'teams_webhook_url' => 'nullable|string|max:500|url',
            'teams_webhook_proxy' => 'nullable|string|max:500',
            'teams_notify_on_success' => 'nullable|boolean',
            'teams_notify_on_failure' => 'nullable|boolean',
        ];
    }

    private function validRegexRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            if (@preg_match('~'.$value.'~i', '') === false) {
                $fail('The :attribute must be a valid regular expression.');
            }
        };
    }
}
