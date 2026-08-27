<?php

return [
    'screenshot_retention_days' => env('SORIFY_SCREENSHOT_RETENTION_DAYS', 90),
    'max_test_timeout_ms'        => env('SORIFY_MAX_TEST_TIMEOUT_MS', 30000),
    'runner_script_path'         => resource_path('playwright/runner.cjs'),
    'tmp_dir'                    => storage_path('app/tmp'),
    'test_code_version_retention' => env('SORIFY_TEST_CODE_VERSION_RETENTION', 10),

    'run_trigger_rate_limit' => [
        'max_attempts'  => env('SORIFY_RUN_TRIGGER_MAX_ATTEMPTS', 10),
        'decay_seconds' => env('SORIFY_RUN_TRIGGER_DECAY_SECONDS', 60),
    ],

    'teams_max_screenshots' => env('SORIFY_TEAMS_MAX_SCREENSHOTS', 5),
];
