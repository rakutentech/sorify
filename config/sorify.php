<?php

return [
    'screenshot_retention_days' => env('SORIFY_SCREENSHOT_RETENTION_DAYS', 90),
    'max_test_timeout_ms'        => env('SORIFY_MAX_TEST_TIMEOUT_MS', 30000),
    'playwright_node_binary'     => env('SORIFY_NODE_BINARY', 'node'),
    'runner_script_path'         => resource_path('playwright/runner.cjs'),
    'tmp_dir'                    => storage_path('app/tmp'),
    'test_code_version_retention' => env('SORIFY_TEST_CODE_VERSION_RETENTION', 10),
];
