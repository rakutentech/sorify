<?php

namespace App\Services\Agent\Prism;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Prism\Prism\Providers\DeepSeek\Handlers\Stream;
use Prism\Prism\Providers\DeepSeek\Maps\MessageMap;
use Prism\Prism\Providers\DeepSeek\Maps\ToolChoiceMap;
use Prism\Prism\Providers\DeepSeek\Maps\ToolMap;
use Prism\Prism\Text\Request;

/**
 * Prism's DeepSeek stream handler with one addition: the request's
 * `reasoning_effort` provider option (the chat's reasoning-effort
 * picker) is forwarded in the chat-completions body — the
 * OpenAI-style parameter several compatible endpoints understand.
 *
 * The payload is a mirror of the parent sendRequest() because upstream
 * builds the body inline with no extension point. Keep it in sync on
 * Prism upgrades.
 */
class ReasoningStream extends Stream
{
    #[\Override]
    protected function sendRequest(Request $request): Response
    {
        /** @var Response $response */
        $response = $this->client
            // Transport-level streaming — REQUIRED for incremental reads:
            // with the default curl handler the JSON-body 'stream' flag only
            // tells the endpoint to stream; Guzzle would still download the
            // whole SSE payload before post() returns, so every delta would
            // be parsed in one burst after the full generation completes.
            // 'stream' routes the request through Guzzle's StreamHandler,
            // which returns at the headers and leaves the body as a live
            // socket stream for readLine(). Upstream DeepSeek's handler is
            // missing this — every other Prism provider sets it.
            ->withOptions(['stream' => true])
            ->post(
                'chat/completions',
                array_merge([
                    'stream' => true,
                    'model' => $request->model(),
                    'messages' => (new MessageMap($request->messages(), $request->systemPrompts()))(),
                ], Arr::whereNotNull([
                    // Omitted when unset (null): sending an explicit null
                    // is rejected by strict gateways ("Invalid type for
                    // 'max_tokens'"), and the model's own default applies.
                    'max_tokens' => $request->maxTokens(),
                    'temperature' => $request->temperature(),
                    'top_p' => $request->topP(),
                    'tools' => ToolMap::map($request->tools()) ?: null,
                    'tool_choice' => ToolChoiceMap::map($request->toolChoice()),
                    // Omitted when unset (null): the model's own default
                    // applies. Never send an explicit "default" — endpoints
                    // differ in what they reject.
                    'reasoning_effort' => $request->providerOptions('reasoning_effort'),
                ]))
            );

        return $response;
    }
}
