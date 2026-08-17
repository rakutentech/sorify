<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreTestSuiteRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name'             => 'required|string|max:255',
            'description'      => 'nullable|string',
            'playwright_proxy' => 'nullable|string|max:500',
            'proxy_rules'         => 'nullable|array',
            'proxy_rules.*.domain' => ['required_with:proxy_rules', 'string', 'max:255', $this->validRegexRule()],
            'proxy_rules.*.proxy'  => 'required_with:proxy_rules|string|max:500',
            'browser'          => 'nullable|string|in:chromium,firefox,webkit',
            'headless'         => 'nullable|boolean',
            'history_retention' => 'nullable|integer|in:3,5,10',
            'timeout_ms'       => 'nullable|integer|in:10000,30000,60000,120000,300000,600000',
            'max_retries'      => 'nullable|integer|in:0,1,2,3',
            'take_screenshot'  => 'nullable|boolean',
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
