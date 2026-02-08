<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Telegram API Credentials
    |--------------------------------------------------------------------------
    |
    | Your Telegram API ID and Hash from https://my.telegram.org
    |
    */
    'api_id' => env('CLIENT_API_ID', env('API_ID', '')),
    'api_hash' => env('CLIENT_API_HASH', env('API_HASH', '')),

    /*
    |--------------------------------------------------------------------------
    | Data Center Configuration
    |--------------------------------------------------------------------------
    |
    | dc_id: Default data center (1-5)
    | test_mode: Use Telegram test servers
    |
    */
    'dc_id' => env('CLIENT_DC_ID', 2),
    'test_mode' => env('CLIENT_TEST_MODE', false),

    /*
    |--------------------------------------------------------------------------
    | Connection Driver
    |--------------------------------------------------------------------------
    |
    | Supported: "sync", "amp", 'fiber', 'swoole', 'fork'
    | Default is sync (blocking).
    |
    */
    'driver' => env('CLIENT_LOOP_DRIVER', 'sync'),

    /*
    |--------------------------------------------------------------------------
    | Transport Protocol
    |--------------------------------------------------------------------------
    |
    | Supported: "abridged", "intermediate", "intermediate_padded", "full"
    |
    */
    'transport' => env('CLIENT_TRANSPORT', 'abridged'),

    /*
    |--------------------------------------------------------------------------
    | Session Configuration
    |--------------------------------------------------------------------------
    |
    | driver: Session storage driver ("file", "database", "redis")
    | path: For file driver, where to store sessions
    | name: Default session name
    |
    */
    'session' => [
        'driver' => env('CLIENT_SESSION_DRIVER', 'file'),
        'path' => env('CLIENT_SESSION_PATH', storage_path('app/clients/sessions')),
        'name' => env('CLIENT_SESSION_NAME', 'default'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Connection Settings
    |--------------------------------------------------------------------------
    |
    | timeout: Connection timeout in seconds
    | retry_count: Number of retries on failure
    | retry_delay: Delay between retries in seconds
    |
    */
    'connection' => [
        'timeout' => 10,
        'retry_count' => 3,
        'retry_delay' => 1,
    ],

    /*
    |--------------------------------------------------------------------------
    | Flood Control
    |--------------------------------------------------------------------------
    |
    | Auto-sleep on flood wait errors
    |
    */
    'flood_sleep' => true,
    'flood_sleep_limit' => 60,

    /*
    |--------------------------------------------------------------------------
    | Logging
    |--------------------------------------------------------------------------
    |
    | Log channel for MTProto debug output
    |
    */
    'log_channel' => env('CLIENT_LOG_CHANNEL', 'stack'),

    /*
    |--------------------------------------------------------------------------
    | API Layer
    |--------------------------------------------------------------------------
    |
    | Telegram API layer version
    |
    */
    'layer' => 197,

    /*
    |--------------------------------------------------------------------------
    | Device Info
    |--------------------------------------------------------------------------
    |
    | Information sent during connection initialization
    |
    */
    'device' => [
        'model' => 'LaraGram',
        'system' => PHP_VERSION,
        'app_version' => '4.0.0',
        'lang_code' => 'en',
    ],
];
