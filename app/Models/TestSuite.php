<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class TestSuite extends Model
{
    /**
     * Maximum number of webhook URLs a suite may have active at once:
     * the current one plus the superseded ones still awaiting deletion.
     * Regeneration is refused while the cap is reached.
     */
    public const MAX_WEBHOOK_TOKENS = 5;

    protected $fillable = [
        'name',
        'description',
        'base_url',
        'status',
        'playwright_proxy',
        'browser',
        'headless',
        'history_retention',
        'timeout_ms',
        'max_retries',
        'take_screenshot',
        'created_by',
        'teams_webhook_url',
        'teams_webhook_proxy',
        'teams_notify_on_start',
        'teams_notify_on_success',
        'teams_notify_on_failure',
        'duplication_status',
        'duplicated_from_suite_id',
    ];

    protected $casts = [
        'headless' => 'boolean',
        'take_screenshot' => 'boolean',
        'timeout_ms' => 'integer',
        'history_retention' => 'integer',
        'max_retries' => 'integer',
        'previous_webhook_tokens' => 'array',
        'teams_notify_on_start' => 'boolean',
        'teams_notify_on_success' => 'boolean',
        'teams_notify_on_failure' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (TestSuite $suite) {
            $suite->webhook_token ??= static::generateWebhookToken();
        });
    }

    public static function generateWebhookToken(): string
    {
        return 'whk_'.Str::random(40);
    }

    public function regenerateWebhookToken(): bool
    {
        if ($this->webhookTokenCount() >= static::MAX_WEBHOOK_TOKENS) {
            return false;
        }

        $previous = $this->previous_webhook_tokens ?? [];

        if ($this->webhook_token) {
            $previous[] = $this->webhook_token;
        }

        $this->previous_webhook_tokens = array_values($previous);
        $this->webhook_token = static::generateWebhookToken();
        $this->save();

        return true;
    }

    /**
     * Permanently remove one of the superseded (old) webhook tokens.
     * The current token cannot be removed this way — regenerate instead.
     */
    public function removePreviousWebhookToken(string $token): bool
    {
        $previous = $this->previous_webhook_tokens ?? [];

        $index = array_search($token, $previous, true);

        if ($index === false) {
            return false;
        }

        unset($previous[$index]);

        $this->previous_webhook_tokens = array_values($previous);
        $this->save();

        return true;
    }

    /**
     * Total active webhook tokens for this suite: the current one plus
     * every superseded one that has not been deleted yet.
     */
    public function webhookTokenCount(): int
    {
        return ($this->webhook_token ? 1 : 0) + count($this->previous_webhook_tokens ?? []);
    }

    public function hasPreviousWebhookToken(string $token): bool
    {
        return in_array($token, $this->previous_webhook_tokens ?? [], true);
    }

    public function webhookUrl(): ?string
    {
        return $this->webhook_token ? route('webhooks.trigger', ['token' => $this->webhook_token]) : null;
    }

    /**
     * Superseded webhook tokens with their trigger URLs, oldest first —
     * the URLs shown below the current one, waiting to be deleted.
     *
     * @return array<int, array{token: string, url: string}>
     */
    public function previousWebhooks(): array
    {
        return array_map(
            fn (string $token) => [
                'token' => $token,
                'url' => route('webhooks.trigger', ['token' => $token]),
            ],
            $this->previous_webhook_tokens ?? [],
        );
    }

    /**
     * True while a background job is still copying tests into this suite
     * from a `duplicate_suite` action.
     */
    public function isBeingDuplicated(): bool
    {
        return $this->duplication_status === 'pending';
    }

    public function duplicatedFrom(): BelongsTo
    {
        return $this->belongsTo(TestSuite::class, 'duplicated_from_suite_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function tests(): HasMany
    {
        return $this->hasMany(Test::class);
    }

    public function proxyRules(): HasMany
    {
        return $this->hasMany(TestSuiteProxyRule::class);
    }

    public function variables(): HasMany
    {
        return $this->hasMany(TestSuiteVariable::class);
    }

    public function cookies(): HasMany
    {
        return $this->hasMany(TestSuiteCookie::class);
    }

    public function integrations(): HasMany
    {
        return $this->hasMany(TestSuiteIntegration::class);
    }

    /**
     * Enabled integrations that fire before a run's tests start. These are
     * blocking: the run stays pending until every one of them completes.
     */
    public function preRunIntegrations()
    {
        return $this->integrations()
            ->where('enabled', true)
            ->where('trigger_before', true);
    }

    public function testRuns(): HasMany
    {
        return $this->hasMany(TestRun::class);
    }

    public function activeTests(): HasMany
    {
        return $this->hasMany(Test::class)->where('status', 'active');
    }

    public function schedule(): HasOne
    {
        return $this->hasOne(TestSuiteSchedule::class);
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'test_suite_user')
            ->withPivot(['can_view', 'can_edit', 'can_delete', 'can_run'])
            ->withTimestamps();
    }

    public function bookmarkedBy(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'suite_bookmarks')->withTimestamps();
    }

    public function isBookmarkedBy(?User $user): bool
    {
        return $user !== null && $this->bookmarkedBy()->where('users.id', $user->id)->exists();
    }

    /**
     * @return array{view: bool, edit: bool, delete: bool, run: bool}
     */
    public function privilegesFor(?User $user): array
    {
        $none = ['view' => false, 'edit' => false, 'delete' => false, 'run' => false];

        $privileges = match (true) {
            $user === null => $none,
            (bool) $user->is_admin => ['view' => true, 'edit' => true, 'delete' => true, 'run' => true],
            default => $this->pivotPrivilegesFor($user) ?? $none,
        };

        if ($user?->is_view_only) {
            $privileges['edit'] = false;
            $privileges['delete'] = false;
            $privileges['run'] = false;
        }

        return $privileges;
    }

    /**
     * @return array{view: bool, edit: bool, delete: bool, run: bool}|null
     */
    private function pivotPrivilegesFor(User $user): ?array
    {
        $membership = $this->members()->where('users.id', $user->id)->first();

        if (! $membership) {
            return null;
        }

        return [
            'view' => (bool) $membership->pivot->can_view,
            'edit' => (bool) $membership->pivot->can_edit,
            'delete' => (bool) $membership->pivot->can_delete,
            'run' => (bool) $membership->pivot->can_run,
        ];
    }
}
