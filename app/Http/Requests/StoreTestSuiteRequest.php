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
            'browser'          => 'nullable|string|in:chromium,firefox,webkit',
            'headless'         => 'nullable|boolean',
            'history_retention' => 'nullable|integer|in:3,5,10',
            'timeout_ms'       => 'nullable|integer|in:10000,30000,60000,120000',
            'take_screenshot'  => 'nullable|boolean',
            'teams_webhook_url' => 'nullable|string|max:500|url',
            'teams_webhook_proxy' => 'nullable|string|max:500',
            'teams_notify_on_success' => 'nullable|boolean',
            'teams_notify_on_failure' => 'nullable|boolean',
        ];
    }
}
