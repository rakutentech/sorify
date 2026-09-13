<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AgentMessage extends Model
{
    protected $fillable = [
        'turn_id',
        'conversation_id',
        'role',
        'content',
        'tool_calls',
        'tool_call_id',
        'name',
    ];

    protected function casts(): array
    {
        return [
            'tool_calls' => 'array',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(AgentConversation::class);
    }

    /**
     * OpenAI-compatible message payload for replaying history to the LLM.
     *
     * @return array<string, mixed>
     */
    public function toOpenAiMessage(): array
    {
        $message = ['role' => $this->role];

        if ($this->content !== null && $this->content !== '') {
            $message['content'] = $this->content;
        }

        if ($this->tool_calls !== null) {
            $message['tool_calls'] = $this->tool_calls;
        }

        if ($this->tool_call_id !== null) {
            $message['tool_call_id'] = $this->tool_call_id;
        }

        if ($this->name !== null) {
            $message['name'] = $this->name;
        }

        return $message;
    }
}
