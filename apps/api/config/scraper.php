<?php

return [

    /*
    |--------------------------------------------------------------------------
    | SCDB target
    |--------------------------------------------------------------------------
    |
    | The Smart Completions (SCDB) installation this scraper drives. Every URL
    | the browser is asked to visit — recipe start URLs and `goto` actions
    | alike — is validated against `allowed_hosts` before Playwright follows
    | it. This is the SSRF / open-redirect boundary: keep it narrow.
    |
    */

    'base_url' => rtrim((string) env('SCDB_BASE_URL', 'https://chiyodanfe.ceccms.com'), '/'),

    'allowed_hosts' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SCDB_ALLOWED_HOSTS', 'chiyodanfe.ceccms.com'))
    ))),

    'allowed_schemes' => ['https'],

    /*
    | Path fragments that mean "SCDB bounced us to the login page". Matched
    | case-insensitively against the URL path to classify a run as
    | `session_expired` rather than a generic failure.
    */
    'login_markers' => ['/login.aspx'],

    /*
    | Landing page used to check whether a stored session is still valid.
    | Relative to base_url.
    */
    'session_check_path' => env('SCDB_SESSION_CHECK_PATH', '/'),

    /*
    |--------------------------------------------------------------------------
    | Node worker
    |--------------------------------------------------------------------------
    |
    | The Playwright CLI lives in the apps/scraper workspace. Laravel invokes
    | it as a child process and talks to it over stdin/stdout using a JSON
    | envelope — never over argv, because argv is world-readable in `ps`.
    |
    */

    'node_binary' => env('SCRAPER_NODE_BINARY', 'node'),

    'app_path' => env('SCRAPER_APP_PATH', dirname(base_path()).'/scraper'),

    'timeout_seconds' => (int) env('SCRAPER_TIMEOUT_SECONDS', 300),

    'navigation_timeout_ms' => (int) env('SCRAPER_NAVIGATION_TIMEOUT_MS', 30000),

    'action_timeout_ms' => (int) env('SCRAPER_ACTION_TIMEOUT_MS', 15000),

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    |
    | Run artifacts (downloaded CSV, failure screenshot, trace) live on a
    | private disk under this prefix. Authentication state is NEVER written
    | here — it lives encrypted in the database.
    |
    */

    'disk' => env('SCRAPER_STORAGE_DISK', 'local'),

    'storage_path' => trim((string) env('SCRAPER_STORAGE_PATH', 'scraper'), '/'),

    'artifact_retention_days' => (int) env('SCRAPER_ARTIFACT_RETENTION_DAYS', 14),

    'max_download_bytes' => (int) env('SCRAPER_MAX_DOWNLOAD_BYTES', 67108864),

    /*
    |--------------------------------------------------------------------------
    | Queue & concurrency
    |--------------------------------------------------------------------------
    |
    | Browser runs are serialised per user: a single SCDB session driven by two
    | concurrent Chromium contexts is not known to be safe, so a lock is held
    | for the duration of each run.
    |
    */

    'queue' => env('SCRAPER_QUEUE', 'scraper'),

    'lock_seconds' => (int) env('SCRAPER_LOCK_SECONDS', 600),

    /*
    |--------------------------------------------------------------------------
    | Recipe limits
    |--------------------------------------------------------------------------
    */

    'max_actions' => 60,

    'max_storage_state_bytes' => 1048576,

];
