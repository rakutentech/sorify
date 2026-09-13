<?php

namespace App\Services\Agent;

use App\Mcp\Servers\SorifyServer;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Content\Text;
use Laravel\Mcp\Server\Tool;
use Throwable;

/**
 * Bridges the app's MCP tools to the OpenAI tool-calling API.
 *
 * The tool registry is read from SorifyServer's declaration so the agent
 * always exposes the same toolset as the MCP server, and tool calls are
 * executed in-process: each tool re-runs its own authorization gates
 * against the authenticated user.
 */
class AgentToolAdapter
{
    /**
     * Tools hidden from the agent. `get_screenshot` returns binary image
     * content that cannot be represented as a text tool result —
     * `list_screenshots` remains available for metadata and URLs.
     */
    private const EXCLUDED_TOOLS = ['get_screenshot'];

    /**
     * @var array<string, Tool>|null class-string => resolved instance
     */
    private ?array $resolved = null;

    /**
     * @return list<array<string, mixed>> OpenAI `tools` payload
     */
    public function definitions(): array
    {
        $definitions = [];

        foreach ($this->tools() as $tool) {
            $definitions[] = [
                'type' => 'function',
                'function' => [
                    'name' => $tool->name(),
                    'description' => $tool->description(),
                    'parameters' => $this->parameters($tool),
                ],
            ];
        }

        return $definitions;
    }

    /**
     * @param  array<string, mixed>  $meta  Request meta for the tool — e.g.
     *                                      ['ai_model' => …] from the agent
     *                                      chat, used for code attribution.
     * @return array{result: string, is_error: bool}
     */
    public function execute(string $name, array $arguments, array $meta = []): array
    {
        $tool = null;

        foreach ($this->tools() as $candidate) {
            if ($candidate->name() === $name) {
                $tool = $candidate;
                break;
            }
        }

        if ($tool === null || in_array($name, self::EXCLUDED_TOOLS, true)) {
            return ['result' => "Unknown tool [{$name}].", 'is_error' => true];
        }

        try {
            $response = $tool->handle(new Request($arguments, meta: $meta));

            $isError = $response instanceof Response
                ? $response->isError()
                : $response->responses()->contains(fn (Response $r) => $r->isError());

            return ['result' => $this->toText($response), 'is_error' => $isError];
        } catch (ValidationException $exception) {
            return ['result' => $exception->getMessage(), 'is_error' => true];
        } catch (Throwable $exception) {
            report($exception);

            return ['result' => "Tool [{$name}] failed: {$exception->getMessage()}", 'is_error' => true];
        }
    }

    /**
     * @return list<Tool>
     */
    private function tools(): array
    {
        if ($this->resolved !== null) {
            return array_values(array_filter(
                $this->resolved,
                fn (Tool $tool) => ! in_array($tool->name(), self::EXCLUDED_TOOLS, true)
            ));
        }

        /** @var list<class-string<Tool>> $toolClasses */
        $toolClasses = (new \ReflectionClass(SorifyServer::class))
            ->getDefaultProperties()['tools'];

        $this->resolved = [];

        foreach ($toolClasses as $toolClass) {
            $this->resolved[$toolClass] = app($toolClass);
        }

        return $this->tools();
    }

    /**
     * @return array<string, mixed> JSON Schema object
     */
    /**
     * @return array<string, mixed> JSON Schema object
     */
    private function parameters(Tool $tool): array
    {
        $inputSchema = $tool->toArray()['inputSchema'] ?? null;

        if (! is_array($inputSchema)) {
            return ['type' => 'object', 'properties' => new \stdClass];
        }

        $inputSchema['properties'] ??= new \stdClass;

        return $inputSchema;
    }

    private function toText(Response|ResponseFactory $response): string
    {
        if ($response instanceof ResponseFactory) {
            if ($structured = $response->getStructuredContent()) {
                return json_encode($structured, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
            }

            return $response->responses()
                ->map(fn (Response $response) => $this->contentToText($response))
                ->filter()
                ->implode("\n") ?: '{}';
        }

        return $this->contentToText($response);
    }

    private function contentToText(Response $response): string
    {
        $content = $response->content();

        if ($content instanceof Text) {
            return (string) $content;
        }

        return '[binary content omitted]';
    }
}
