<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentTurnEvent extends Model
{
    protected $fillable = [
        'conversation_id',
        'turn_id',
        'seq',
        'event',
        'data',
    ];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AgentConversation::class);
    }

    /**
     * Events that close a turn — the replay stream ends once one is seen.
     */
    public const TERMINAL_EVENTS = ['done', 'error'];
}
