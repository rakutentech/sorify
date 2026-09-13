<?php

namespace App\Mcp\Tools\Agent;

use App\Services\Agent\UrlGuard;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

class FetchUrlTool extends Tool
{
    protected string $name = 'fetch_url';

    protected string $description = 'Fetch a web page over HTTP and return its readable text, title, and hyperlinks. Use for lightweight crawling of public pages.';

    public function __construct(private readonly UrlGuard $urlGuard) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'url' => $schema->string()->required()->description('The absolute http(s) URL to fetch.'),
            'max_chars' => $schema->integer()->min(500)->max(50000)->description('Truncate the extracted text to at most this many characters. Defaults to 20000.'),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'url' => ['required', 'string', 'max:2048'],
            'max_chars' => ['nullable', 'integer', 'min:500', 'max:50000'],
        ]);

        try {
            $fetched = $this->urlGuard->get($data['url'], ['max_redirects' => 5]);
        } catch (\InvalidArgumentException $exception) {
            return Response::error($exception->getMessage());
        }

        if (! str_contains((string) ($fetched['headers']['content-type'][0] ?? ''), 'text/html')) {
            return Response::structured([
                'url' => $fetched['url'],
                'status' => $fetched['status'],
                'content_type' => $fetched['headers']['content-type'][0] ?? null,
                'body' => mb_substr($fetched['body'], 0, $data['max_chars'] ?? 20000),
            ]);
        }

        $extract = $this->extractHtml($fetched['body']);

        $maxChars = $data['max_chars'] ?? 20000;
        $text = mb_substr($extract['text'], 0, $maxChars);
        $truncated = mb_strlen($extract['text']) > $maxChars;

        return Response::structured([
            'url' => $fetched['url'],
            'status' => $fetched['status'],
            'title' => $extract['title'],
            'text' => $text,
            'truncated' => $truncated,
            'links' => array_slice($extract['links'], 0, 100),
        ]);
    }

    /**
     * @return array{title: string|null, text: string, links: list<string>}
     */
    private function extractHtml(string $html): array
    {
        $document = new \DOMDocument;

        libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8"?>'.$html, LIBXML_NOWARNING | LIBXML_NOERROR);
        libxml_clear_errors();

        $title = null;

        foreach ($document->getElementsByTagName('title') as $node) {
            $title = trim($node->textContent);
            break;
        }

        $links = [];

        foreach ($document->getElementsByTagName('a') as $node) {
            $href = trim((string) $node->getAttribute('href'));

            if ($href !== '' && ! str_starts_with($href, '#') && ! str_starts_with($href, 'javascript:')) {
                $links[] = $href;
            }

            if (count($links) >= 200) {
                break;
            }
        }

        $xpath = new \DOMXPath($document);

        foreach (['script', 'style', 'noscript', 'template'] as $tag) {
            foreach (iterator_to_array($xpath->query("//*[local-name()='{$tag}']") ?: []) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        $text = trim(preg_replace(
            "/[ \t]*\n[ \t]*\n[ \t]*/",
            "\n",
            preg_replace('/[ \t]+/', ' ', $document->textContent ?? '')
        ) ?? '');

        return ['title' => $title, 'text' => $text, 'links' => array_values(array_unique($links))];
    }
}
