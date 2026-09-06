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
            'test_ids' => 'nullable|array',
            'test_ids.*' => [
                'integer',
                // Only check suite membership on the HTTP path — the MCP tool
                // reuses these rules outside a route context and filters the
                // ids against the suite itself.
                function ($attribute, $value, $fail) {
                    $suite = $this->route('suite');

                    if ($suite && ! $suite->tests()->where('tests.id', $value)->exists()) {
                        $fail('One of the selected tests does not belong to this suite.');
                    }
                },
            ],
        ];
    }
}
