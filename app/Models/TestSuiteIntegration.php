<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TestSuiteIntegration extends Model
{
    public const TYPES = ['github_action', 'http_request'];

    protected $fillable = [
        'type',
        'github_app_id',
        'created_by',
        'label',
        'config',
        'disabled_note',
        'enabled',
        'trigger_before',
        'trigger_after',
    ];

    protected $casts = [
        'config' => 'array',
        'enabled' => 'boolean',
        'trigger_before' => 'boolean',
        'trigger_after' => 'boolean',
    ];

    public function testSuite(): BelongsTo
    {
        return $this->belongsTo(TestSuite::class);
    }

    /**
     * The GitHub App this integration authenticates as (github_action only).
     * Null falls back to the default app at dispatch time.
     */
    public function githubApp(): BelongsTo
    {
        return $this->belongsTo(GithubApp::class);
    }

    /**
     * Who set up the integration — used by the GitHub App access-list sweep
     * to spare integrations a still-whitelisted member created. Null for
     * integrations created before the feature existed.
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function config(string $key, mixed $default = null): mixed
    {
        return data_get($this->config, $key, $default);
    }
}
