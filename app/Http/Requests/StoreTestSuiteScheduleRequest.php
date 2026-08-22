<?php

namespace App\Http\Requests;

use Cron\CronExpression;
use Illuminate\Foundation\Http\FormRequest;

class StoreTestSuiteScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cron_expression' => ['nullable', 'string', 'max:100', function ($attribute, $value, $fail) {
                if ($value !== null && $value !== '' && ! CronExpression::isValidExpression($value)) {
                    $fail('The cron expression is not valid.');
                }
            }],
            'timezone' => 'nullable|string|timezone|max:64',
            'is_enabled' => 'nullable|boolean',
        ];
    }
}
