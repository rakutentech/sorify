<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreSuiteRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'name'             => 'required|string|max:255',
            'description'      => 'nullable|string',
            'playwright_proxy' => 'nullable|string|max:500',
            'playwright_proxy_pac' => 'nullable|string|max:65535',
            'browser'          => 'nullable|string|in:chromium,firefox,webkit',
            'headless'         => 'nullable|boolean',
            'base_url'         => 'nullable|string|max:500',
            'history_retention' => 'nullable|integer|in:3,5,10',
            'timeout_ms'       => 'nullable|integer|in:10000,30000,60000,120000',
            'take_screenshot'  => 'nullable|boolean',
        ];
    }
}
