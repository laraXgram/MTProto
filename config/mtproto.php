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
    | Supported: "sync", "swoole"
    | Default is sync (blocking). Use "swoole" for non-blocking I/O
    | (coroutine-based, required when running under Surge).
    |
    */
    'driver' => env('CLIENT_LOOP_DRIVER', 'swoole'),

    /*
    |--------------------------------------------------------------------------
    | Message Pump
    |--------------------------------------------------------------------------
    |
    | Single-reader coroutine core. Routes every RPC and pushed update through
    | one socket reader so handlers run non-blocking, each in its own coroutine.
    | Requires the "swoole" driver.
    |
    */
    'use_pump' => env('CLIENT_USE_PUMP', true),

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
    | Proactive Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Pace outgoing calls before they hit Telegram's flood limits (avoids
    | reactive FLOOD_WAIT and lowers ban risk). Token-bucket per scope:
    |   global  — overall msgs/sec across the account (rate, capacity=burst)
    |   per_peer— per chat/user (Telegram allows ~1 msg/sec to a given peer)
    |
    | Set 'enabled' => false to disable. Rates are tokens-per-second.
    |
    */
    'rate_limit' => [
        'enabled'  => env('CLIENT_RATE_LIMIT', true),
        'global'   => ['rate' => 30.0, 'capacity' => 30.0],
        'per_peer' => ['rate' => 1.0, 'capacity' => 5.0],
    ],

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
    | Telegram API layer version. MUST match the compiled TL schema + Generated
    | types (currently 227). Do not raise this without regenerating both the
    | .tl schema and the Generated/Types together (php bin/compile-tl.php) — the
    | server would otherwise reply with constructors the deserializer cannot parse.
    |
    */
    'layer' => 227,

    /*
    |--------------------------------------------------------------------------
    | Device Fingerprint
    |--------------------------------------------------------------------------
    |
    | The device/system/app strings sent at connection init. Telegram
    | fingerprints clients by these — a php_uname()-derived value reveals a PHP
    | server and is a ban risk. Pick a realistic official-client preset instead.
    |
    | preset: one of "tdesktop", "android", "ios", "macos", "web".
    |         Choose the platform that matches the api_id you registered.
    |         Keep it STABLE — a rotating fingerprint is itself a flag.
    |
    | You may override individual fields (device_model, system_version,
    | app_version, system_lang_code, lang_pack, lang_code) on top of the preset.
    |
    */
    'device' => [
        'preset' => env('CLIENT_DEVICE_PRESET', 'tdesktop'),
        // 'device_model'   => 'My Device',
        // 'system_version' => 'Windows 10',
        // 'app_version'    => '5.7.3 x64',
        // 'lang_code'      => 'en',
    ],
];
