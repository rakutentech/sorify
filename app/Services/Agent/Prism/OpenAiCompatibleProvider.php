<?php

namespace App\Services\Agent\Prism;

use Generator;
use Prism\Prism\Providers\DeepSeek\DeepSeek;
use Prism\Prism\Text\Request as TextRequest;

/**
 * The chat-completions transport behind AgentProfiles, built on Prism's
 * DeepSeek provider (plain OpenAI-compatible `chat/completions` — Prism's
 * own `openai` driver targets the Responses API instead, which the
 * vLLM / Ollama / OpenRouter-style endpoints behind AgentProfiles don't
 * implement).
 *
 * The only change over the stock provider: streaming requests go through
 * ReasoningStream, which forwards the chat's reasoning-effort picker as
 * `reasoning_effort` in the request body. The non-streaming text() and
 * structured() paths are untouched (the agent chat only streams).
 */
class OpenAiCompatibleProvider extends DeepSeek
{
    #[\Override]
    public function stream(TextRequest $request): Generator
    {
        $handler = new ReasoningStream($this->client(
            $request->clientOptions(),
            $request->clientRetry()
        ));

        return $handler->handle($request);
    }
}
