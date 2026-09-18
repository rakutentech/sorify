<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A running (or just-finished) agent turn — one chat turn, in Agent mode
 * (full tools) or Ask mode (plain answers). Written by the AgentService
 * when a turn starts, updated as it progresses, closed when it ends.
 *
 * Powers the admin "running AI agents" page: who is running what, for how
 * long, and the ability to stop a turn (cancel_requested_at — the turn
 * loop checks it each step and stops cleanly).
 */
class AgentTurn extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'id',
        'conversation_id',
        'user_id',
        'mode',
        'started_at',
        'last_activity_at',
        'finished_at',
        'cancel_requested_at',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'finished_at' => 'datetime',
            'cancel_requested_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AgentConversation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * A turn counts as running while unfinished; rows whose worker died
     * without closing them go stale after this many idle minutes.
     */
    public function isStale(): bool
    {
        $lastActivity = $this->last_activity_at ?? $this->started_at;

        return $lastActivity->diffInMinutes(now()) >= 15;
    }
}
