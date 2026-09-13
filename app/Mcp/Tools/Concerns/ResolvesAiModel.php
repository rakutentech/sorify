<?php

namespace App\Mcp\Tools\Concerns;

use Laravel\Mcp\Request;

trait ResolvesAiModel
{
    /**
     * The AI model that produced the code being written. The agent chat's
     * ground truth (Request meta, injected by the AgentToolAdapter) wins
     * over a self-reported ai_model argument from MCP/REST callers.
     */
    protected function aiModelOf(Request $request, ?string $reported = null): ?string
    {
        $model = $this->requestMeta($request)['ai_model'] ?? $reported;

        return is_string($model) && $model !== '' ? $model : null;
    }

    /**
     * Which surface changed the code: 'agent' when the My AI Agent chat
     * executed the tool (adapter-injected meta), 'mcp' for direct MCP/REST
     * callers. The web dashboard passes its own source explicitly.
     */
    protected function codeSourceOf(Request $request, string $default = 'mcp'): string
    {
        $via = $this->requestMeta($request)['via'] ?? null;

        return is_string($via) && $via !== '' ? $via : $default;
    }

    /**
     * @return array<string, mixed>
     */
    private function requestMeta(Request $request): array
    {
        $meta = $request->meta() ?? [];

        return is_array($meta) ? $meta : [];
    }
}
