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

    public function config(string $key, mixed $default = null): mixed
    {
        return data_get($this->config, $key, $default);
    }
}
