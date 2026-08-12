<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSuiteMemberRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        return [
            'can_view'   => 'boolean',
            'can_edit'   => 'boolean',
            'can_delete' => 'boolean',
            'can_run'    => 'boolean',
        ];
    }
}
