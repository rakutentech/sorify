<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddSuiteMemberRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array
    {
        $existingMemberIds = $this->route('suite')->members()->pluck('users.id')->all();

        return [
            'user_id'    => ['required', 'integer', 'exists:users,id', Rule::notIn($existingMemberIds)],
            'can_view'   => 'boolean',
            'can_edit'   => 'boolean',
            'can_delete' => 'boolean',
            'can_run'    => 'boolean',
        ];
    }
}
