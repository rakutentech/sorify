<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A named OpenAI-compatible endpoint configuration owned by a user.
 * Users can keep several (OpenAI, Ollama, internal vLLM, ...).
 */
class AgentProfile extends Model
{
    public const RETENTION_DAYS = [7, 30, 90, 365];

    protected $fillable = [
        'user_id',
        'name',
        'base_url',
        'api_token',
        'proxy_url',
        'default_model',
        'system_prompt',
        'history_retention_days',
    ];

    protected $hidden = ['api_token'];

    protected function casts(): array
    {
        return [
            'api_token' => 'encrypted',
            'history_retention_days' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(AgentConversation::class);
    }

    /**
     * Normalize an OpenAI-compatible base URL: default to https, strip the
     * trailing slash, and append /v1 only when the user supplied a bare host
     * (endpoints like Ollama /v1, OpenRouter /api/v1 keep their own path).
     */
    public static function normalizeBaseUrl(?string $baseUrl): string
    {
        $baseUrl = rtrim(trim((string) $baseUrl), '/');

        if (! str_starts_with($baseUrl, 'http://') && ! str_starts_with($baseUrl, 'https://')) {
            $baseUrl = 'https://'.$baseUrl;
        }

        $path = (string) (parse_url($baseUrl, PHP_URL_PATH) ?: '');

        if ($path === '' || $path === '/') {
            $baseUrl .= '/v1';
        }

        return $baseUrl;
    }

    /**
     * Public-safe representation for the frontend (never exposes the token).
     *
     * @return array<string, mixed>
     */
    public function toSafeArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'base_url' => $this->base_url,
            'proxy_url' => $this->proxy_url,
            'default_model' => $this->default_model,
            'system_prompt' => $this->system_prompt,
            'history_retention_days' => $this->history_retention_days,
            'token_configured' => (bool) $this->api_token,
        ];
    }
}
