<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AgentConversation extends Model
{
    protected $fillable = [
        'user_id',
        'agent_profile_id',
        'title',
        'page_url',
        'page_name',
        'context',
        'agent_mode',
        'agent_max_run_minutes',
        'skill_ids',
    ];

    protected function casts(): array
    {
        return [
            'updated_at' => 'datetime',
            'agent_mode' => 'boolean',
            'agent_max_run_minutes' => 'integer',
            'skill_ids' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(AgentProfile::class, 'agent_profile_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AgentMessage::class, 'conversation_id')->orderBy('id');
    }

    public function turnEvents(): HasMany
    {
        return $this->hasMany(AgentTurnEvent::class, 'conversation_id')->orderBy('seq');
    }
}
