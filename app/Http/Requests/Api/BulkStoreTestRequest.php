<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class BulkStoreTestRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'tests'                   => 'required|array|min:1|max:100',
            'tests.*.name'            => 'required|string|max:255',
            'tests.*.playwright_code' => 'required|string|min:10',
            'tests.*.description'     => 'nullable|string',
            'tests.*.uploaded_by'     => 'nullable|string|max:255|exists:users,email',
            'tests.*.status'          => 'nullable|in:active,disabled',
        ];
    }
}
