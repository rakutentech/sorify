<?php

return [
    'prism_server' => [
        // The middleware that will be applied to the Prism Server routes.
        'middleware' => [],
        'enabled' => env('PRISM_SERVER_ENABLED', false),
    ],

    // Total HTTP timeout for every LLM call. Agent turns override this per
    // request with the sorify.agent.request_timeout client option anyway.
    'request_timeout' => env('PRISM_REQUEST_TIMEOUT', 300),

    'providers' => [
        // The agent's `openai-compatible` provider is registered at runtime
        // in AppServiceProvider (PrismManager::extend) and always carries
        // the per-user AgentProfile credentials in the request, so it has
        // no static configuration here.
    ],
];
