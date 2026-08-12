<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

class StoreApiTestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'            => 'required|string|max:255',
            'playwright_code' => 'required|string|min:10',
            'description'     => 'nullable|string',
            'uploaded_by'     => 'nullable|string|max:255|exists:users,email',
            'status'          => 'nullable|in:active,disabled',
        ];
    }
}
