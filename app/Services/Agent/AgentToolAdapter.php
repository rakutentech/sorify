<?php

namespace App\Services\Agent;

use App\Mcp\Servers\SorifyServer;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Content\Text;
use Laravel\Mcp\Server\Tool;
use Prism\Prism\Schema\RawSchema;
use Prism\Prism\Tool as PrismTool;
use Throwable;

/**
 * Bridges the app's MCP tools to Prism's tool-calling API.
 *
 * The tool registry is read from SorifyServer's declaration so the agent
 * always exposes the same toolset as the MCP server, and tool calls are
 * executed in-process: each tool re-runs its own authorization gates
 * against the authenticated user.
 *
 * One chat-agent-only filter applies on top: tools named in the
 * sorify.agent.blocked_tools config are never exposed to the agent (the
 * MCP server keeps them).
 */
class AgentToolAdapter
{
    /**
     * Cap on what a single tool result may weigh — both what is persisted
     * to the transcript and what is fed back to the LLM on the next
     * round-trip.
     */
    public const MAX_TOOL_RESULT_CHARS = 60000;

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
     * The MCP toolset as Prism tools: each wraps the SorifyServer
     * declaration's JSON schema (as raw schemas) and delegates execution
     * back to this adapter, which re-runs the tool's authorization gates
     * as the chatting user and records the agent's model as the
     * ground-truth attribution meta for any test code it writes.
     *
     * @return list<PrismTool>
     */
    public function prismTools(string $model): array
    {
        $prismTools = [];

        foreach ($this->tools() as $tool) {
            $name = $tool->name();

            $prismTool = (new PrismTool)
                ->as($name)
                ->for($tool->description() ?? '');

            $inputSchema = $tool->toArray()['inputSchema'] ?? [];

            if (is_array($inputSchema)) {
                $required = $inputSchema['required'] ?? [];

                foreach ($inputSchema['properties'] ?? [] as $property => $propertySchema) {
                    $prismTool->withParameter(
                        new RawSchema((string) $property, is_array($propertySchema) ? $propertySchema : []),
                        in_array($property, $required, true),
                    );
                }
            }

            $prismTool
                ->using(function (...$arguments) use ($name, $model) {
                    $result = $this->execute($name, $arguments, [
                        'ai_model' => $model,
                        'via' => 'agent',
                    ]);

                    if ($result['is_error']) {
                        throw new AgentToolError(mb_substr($result['result'], 0, self::MAX_TOOL_RESULT_CHARS));
                    }

                    return mb_substr($result['result'], 0, self::MAX_TOOL_RESULT_CHARS);
                })
                ->failed(fn (Throwable $exception, array $parameters = []): string => $exception instanceof AgentToolError
                    ? $exception->getMessage()
                    : "Tool [{$name}] failed: {$exception->getMessage()}");

            $prismTools[] = $prismTool;
        }

        return $prismTools;
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

        if ($tool === null || in_array($name, $this->blockedTools(), true)) {
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
                fn (Tool $tool) => ! in_array($tool->name(), $this->blockedTools(), true)
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
     * Tool names hidden from the chat agent: the built-in exclusions plus
     * the sorify.agent.blocked_tools config (comma-separated names).
     *
     * @return list<string>
     */
    private function blockedTools(): array
    {
        $configured = config('sorify.agent.blocked_tools');

        $names = is_string($configured)
            ? explode(',', $configured)
            : (is_array($configured) ? $configured : []);

        return array_values(array_unique(array_merge(
            self::EXCLUDED_TOOLS,
            array_filter(array_map('trim', $names)),
        )));
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
