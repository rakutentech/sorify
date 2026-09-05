<?php

return [
    'screenshot_retention_days' => env('SORIFY_SCREENSHOT_RETENTION_DAYS', 90),
    'max_test_timeout_ms' => env('SORIFY_MAX_TEST_TIMEOUT_MS', 30000),
    'runner_script_path' => resource_path('playwright/runner.cjs'),
    'tmp_dir' => storage_path('app/tmp'),
    'test_code_version_retention' => env('SORIFY_TEST_CODE_VERSION_RETENTION', 10),

    'run_trigger_rate_limit' => [
        'max_attempts' => env('SORIFY_RUN_TRIGGER_MAX_ATTEMPTS', 10),
        'decay_seconds' => env('SORIFY_RUN_TRIGGER_DECAY_SECONDS', 60),
    ],

    'teams_max_screenshots' => env('SORIFY_TEAMS_MAX_SCREENSHOTS', 5),

    'integrations' => [
        'github_action' => [
            // How long a blocking pre-run workflow may take before the run
            // is failed (seconds). Covers dispatch + polling.
            'pre_run_timeout' => env('SORIFY_GITHUB_ACTION_PRE_RUN_TIMEOUT', 900),
            // Seconds between workflow run status polls. Set 0 in tests.
            'poll_interval' => env('SORIFY_GITHUB_ACTION_POLL_INTERVAL', 5),
        ],
        'http_request' => [
            // Per-request timeout in seconds for http_request integrations.
            'timeout' => env('SORIFY_HTTP_REQUEST_TIMEOUT', 15),
        ],
    ],
];
