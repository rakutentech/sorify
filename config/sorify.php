<?php

return [
    'screenshot_retention_days' => env('SORIFY_SCREENSHOT_RETENTION_DAYS', 90),
    'max_test_timeout_ms' => env('SORIFY_MAX_TEST_TIMEOUT_MS', 30000),
    'runner_script_path' => resource_path('playwright/runner.cjs'),
    'tmp_dir' => storage_path('app/tmp'),
    'test_code_version_retention' => env('SORIFY_TEST_CODE_VERSION_RETENTION', 10),

    'execution' => [
        'default_mode' => env('SORIFY_EXECUTION_DEFAULT_MODE', 'local'),
        // Where Playwright looks for browser binaries in local mode. Unset =
        // Playwright's default ($HOME/.cache/ms-playwright). The Docker image
        // sets PLAYWRIGHT_BROWSERS_PATH=/opt/ms-playwright via ENV, so no
        // value is needed there; non-Docker hosts point this at their browser
        // install or leave it unset for the HOME-based default.
        'browsers_path' => env('SORIFY_BROWSERS_PATH'),

        'docker_binary' => env('SORIFY_EXECUTION_DOCKER_BINARY', 'docker'),
        'docker_host' => env('SORIFY_EXECUTION_DOCKER_HOST'),
        'runner_image' => env('SORIFY_EXECUTION_RUNNER_IMAGE', 'sorify-runner:latest'),
        'runner_network' => env('SORIFY_EXECUTION_RUNNER_NETWORK', 'sorify-runners'),
        'gvisor_runtime' => env('SORIFY_EXECUTION_GVISOR_RUNTIME', 'runsc'),
        'host_runs_dir' => env('SORIFY_EXECUTION_HOST_RUNS_DIR'),
        'local_test_uid' => env('SORIFY_LOCAL_TEST_UID'),
        'local_max_processes' => env('SORIFY_LOCAL_MAX_PROCESSES', 256),
        'local_max_filesize' => env('SORIFY_LOCAL_MAX_FILESIZE', 1073741824),
        'container' => [
            'pids_limit' => env('SORIFY_EXECUTION_PIDS_LIMIT', 512),
            'memory' => env('SORIFY_EXECUTION_MEMORY', '2g'),
            'cpus' => env('SORIFY_EXECUTION_CPUS', 2),
            'tmpfs_size' => env('SORIFY_EXECUTION_TMPFS_SIZE', '256m'),
        ],
    ],

    'run_trigger_rate_limit' => [
        'max_attempts' => env('SORIFY_RUN_TRIGGER_MAX_ATTEMPTS', 10),
        'decay_seconds' => env('SORIFY_RUN_TRIGGER_DECAY_SECONDS', 60),
    ],

    'teams_max_screenshots' => env('SORIFY_TEAMS_MAX_SCREENSHOTS', 5),

    // Screenshots embedded inline (<img>) in result emails, failing/error
    // results first.
    'email_max_screenshots' => env('SORIFY_EMAIL_MAX_SCREENSHOTS', 10),

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
