<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;

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
     * Prism message for replaying this row to the LLM. Tool rows become
     * single-result tool result messages; AgentService::history() takes
     * care of grouping and of never replaying tool calls without their
     * results.
     *
     * @return \Prism\Prism\Contracts\Message
     */
    public function toPrismMessage()
    {
        if ($this->role === 'user') {
            return new UserMessage((string) ($this->content ?? ''));
        }

        if ($this->role === 'tool') {
            return new ToolResultMessage([
                new ToolResult(
                    toolCallId: (string) ($this->tool_call_id ?? ''),
                    toolName: (string) ($this->name ?? ''),
                    args: [],
                    result: (string) ($this->content ?? ''),
                ),
            ]);
        }

        $toolCalls = [];

        foreach ($this->tool_calls ?? [] as $toolCall) {
            $toolCalls[] = new ToolCall(
                id: (string) ($toolCall['id'] ?? ''),
                name: (string) ($toolCall['function']['name'] ?? ''),
                arguments: (string) ($toolCall['function']['arguments'] ?? ''),
            );
        }

        return new AssistantMessage((string) ($this->content ?? ''), $toolCalls);
    }
}

