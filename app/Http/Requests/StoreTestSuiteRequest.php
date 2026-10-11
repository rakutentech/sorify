<?php

namespace App\Http\Requests;

use App\Support\CookiePayload;
use App\Support\NotificationCooldown;
use App\Support\ScreenshotMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTestSuiteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('take_screenshot')) {
            $this->merge(['take_screenshot' => ScreenshotMode::normalize($this->input('take_screenshot'))]);
        }

        // The browser setting is a set of engines: the dashboard sends an
        // array of checked boxes, MCP/API a single string. Normalize both
        // onto the comma-separated column form (trimmed, deduplicated).
        // Unknown engines are kept here so the regex rule below rejects
        // them; the model canonicalizes valid values on save.
        if ($this->has('browser')) {
            $this->merge(['browser' => $this->normalizeBrowser($this->input('browser'))]);
        }

        // An emptied-out filter field should clear the setting, not fail
        // validation. Multiple filters are comma-separated; each part is
        // trimmed and empty parts (trailing commas, ", ,") are dropped.
        if ($this->has('coverage_url_filter')) {
            $parts = array_filter(array_map('trim', explode(',', (string) $this->input('coverage_url_filter'))));

            $this->merge(['coverage_url_filter' => $parts ? implode(',', $parts) : null]);
        }

        // Cookies pasted into the suite settings (or uploaded via API/MCP)
        // come in several real-world shapes — Playwright's camelCase with a
        // float expires, DevTools' lowercase sameSite spellings and
        // expirationDate. Normalize them onto the canonical form so the
        // validation rules below judge the cookie, not the dialect.
        if ($this->has('cookies') && is_array($this->input('cookies'))) {
            $this->merge(['cookies' => CookiePayload::normalize($this->input('cookies'))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => $this->isMethod('POST') ? 'required|string|max:255' : 'nullable|string|max:255',
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
            'browser' => ['nullable', 'string', 'max:100', 'regex:/^(chromium|firefox|webkit)(,(chromium|firefox|webkit))*$/'],
            'headless' => 'nullable|boolean',
            'history_retention' => 'nullable|integer|in:3,5,10',
            'timeout_ms' => 'nullable|integer|in:10000,30000,60000,120000,300000,600000',
            'max_retries' => 'nullable|integer|in:0,1,2,3',
            'take_screenshot' => ['nullable', 'string', Rule::in(ScreenshotMode::ALL)],
            'collect_coverage' => 'nullable|boolean',
            'coverage_url_filter' => ['nullable', 'string', 'max:500'],
            'teams_webhook_url' => 'nullable|string|max:500|url',
            'teams_webhook_proxy' => 'nullable|string|max:500',
            'teams_notify_on_start' => 'nullable|boolean',
            'teams_notify_on_success' => 'nullable|boolean',
            'teams_notify_on_failure' => 'nullable|boolean',
            'email_notify_on_start' => 'nullable|boolean',
            'email_notify_on_success' => 'nullable|boolean',
            'email_notify_on_failure' => 'nullable|boolean',
            'teams_notification_cooldown_minutes' => ['nullable', 'integer', Rule::in(NotificationCooldown::ALLOWED_MINUTES)],
            'email_notification_cooldown_minutes' => ['nullable', 'integer', Rule::in(NotificationCooldown::ALLOWED_MINUTES)],
            'email_recipient_ids' => 'nullable|array',
            'email_recipient_ids.*' => 'integer|exists:users,id',
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

    /**
     * Accepts a single engine name, a comma-separated list or an array of
     * engine names (the dashboard's checkbox group) and returns the
     * comma-separated form — trimmed, deduplicated. Unknown engines are
     * kept so the validation rule can reject them; valid values are
     * canonicalized (known engines, canonical order) by the model on save.
     */
    private function normalizeBrowser(mixed $value): ?string
    {
        $list = is_array($value) ? $value : explode(',', (string) $value);

        $browsers = array_values(array_unique(array_filter(array_map(
            fn ($b) => trim((string) $b),
            $list,
        ))));

        return $browsers !== [] ? implode(',', $browsers) : null;
    }
}
