<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Skill extends Model
{
    /**
     * Cap on how many skills a single user may keep (originals + installed
     * copies combined) — enforced at every creation path: the profile
     * page, the shared page's install button and the MCP skill tools.
     */
    public const MAX_PER_USER = 25;

    protected $fillable = [
        'user_id',
        'name',
        'description',
        'content',
        'is_public',
        'copies_count',
        'copied_from_id',
    ];

    protected function casts(): array
    {
        return [
            'is_public' => 'boolean',
            'copies_count' => 'integer',
            'copied_from_id' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The skill this one was copied from (a copy is fully detached —
     * later edits by the original owner don't propagate).
     */
    public function original(): BelongsTo
    {
        return $this->belongsTo(self::class, 'copied_from_id');
    }

    public function scopeOwnedBy(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_public', true);
    }

    /**
     * Skills the author wrote themselves — not copies installed from
     * someone else. The shared Skills page only lists originals, so an
     * installed copy that was later made public does not shadow its
     * original.
     */
    public function scopeOriginal(Builder $query): Builder
    {
        return $query->whereNull('copied_from_id');
    }

    /**
     * Safe array for API / UI responses that must not carry the full
     * markdown body (lists, pickers).
     *
     * @return array<string, mixed>
     */
    public function toSummaryArray(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->user_id,
            'name' => $this->name,
            'description' => $this->description,
            'is_public' => (bool) $this->is_public,
            'copies_count' => (int) $this->copies_count,
            'copied_from_id' => $this->copied_from_id,
            'updated_at' => $this->updated_at,
        ];
    }
}
