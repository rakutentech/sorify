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
    public const RETENTION_DAYS = [7, 14, 30, 90, 365];

    protected $fillable = [
        'user_id',
        'name',
        'base_url',
        'api_token',
        'proxy_url',
        'extra_headers',
        'default_model',
        'system_prompt',
        'history_retention_days',
    ];

    protected $hidden = ['api_token'];

    protected function casts(): array
    {
        return [
            'api_token' => 'encrypted',
            'extra_headers' => 'array',
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
     * Normalize custom chat-request headers. Accepts the name/value pair rows
     * the settings form sends ([{name, value}]) and already-normalized
     * name => value maps (stored data) alike.
     *
     * Header names must be RFC 7230 tokens; values are stripped of CR/LF/NUL
     * so a value like "x\r\nEvil: 1" cannot smuggle in extra headers.
     * Authorization is dropped — the profile's api_token is the only way to
     * set credentials, so a header row can never shadow or duplicate it.
     *
     * @param  array<mixed>|null  $rows
     * @return array<string, string>
     */
    public static function normalizeHeaders(?array $rows): array
    {
        $headers = [];

        foreach ((array) $rows as $key => $row) {
            if (is_string($row)) {
                $name = trim((string) $key);
                $value = $row;
            } else {
                $name = trim((string) (($row ?? [])['name'] ?? ''));
                $value = (string) (($row ?? [])['value'] ?? '');
            }

            if ($name === '' || strcasecmp($name, 'Authorization') === 0) {
                continue;
            }

            if (! preg_match('/^[A-Za-z0-9!#$%&\'*+.^_`|~-]+$/', $name)) {
                continue;
            }

            $headers[$name] = str_replace(["\r", "\n", "\0"], '', $value);
        }

        return $headers;
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
            'extra_headers' => $this->extra_headers ?? [],
            'default_model' => $this->default_model,
            'system_prompt' => $this->system_prompt,
            'history_retention_days' => $this->history_retention_days,
            'token_configured' => (bool) $this->api_token,
        ];
    }
}
