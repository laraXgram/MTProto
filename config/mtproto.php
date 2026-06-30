<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Telegram API Credentials
    |--------------------------------------------------------------------------
    |
    | Your Telegram API ID and Hash
    |
    */
    'api_id' => env('API_ID', ''),
    'api_hash' => env('API_HASH', ''),

    /*
    |--------------------------------------------------------------------------
    | Sessions (multi-account)
    |--------------------------------------------------------------------------
    |
    | Run several accounts (userbot + bot mix) from one process. Each entry is
    | a session name -> per-session overrides, deep-merged over the global
    | options above (api_id/api_hash/device/dc_id/etc.). Anything omitted falls
    | back to the global value. Listen files are bound to a session name in
    | bootstrap/app.php; replies route back to the originating session automatically.
    |
    */
    'sessions' => [
        // 'default'  => [],  // empty = use the global options above as-is
        //
        // 'support' => [
        //     'api_id'   => env('SUPPORT_API_ID'),
        //     'api_hash' => env('SUPPORT_API_HASH'),
        // ],
        //
        // 'announcer' => [
        //     'dc_id'  => 4,
        //     'device' => ['preset' => 'android'],
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Surge-hosted Pump
    |--------------------------------------------------------------------------
    |
    | The pump runs INSIDE the Surge server automatically - `php laragram
    | surge:start` boots the HTTP surface and the userbot pump together (app
    | booted once, non-blocking). No manual wiring: PumpProcess registers itself
    | as a Surge process when Surge is installed. Set `autostart` to false to
    | opt out (e.g. to run the pump via `client:start` instead).
    |
    | `sessions`: which sessions the pump boots - one reader coroutine each, on
    | one shared runtime. Empty = every session in 'sessions' above (plus
    | 'default'). Only already-authorized sessions start (no interactive auth in
    | a server).
    |
    */
    'surge' => [
        'autostart' => true,
        'sessions' => [],
    ],

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
    | Use "swoole" for non-blocking I/O (coroutine-based, required when
    | running under Surge).
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
    | Plain framing:  "abridged", "intermediate", "intermediate_padded", "full"
    | Obfuscated2:    "obfuscated" (abridged inner - recommended),
    |                 "obfuscated_intermediate"
    |
    | Obfuscated2 turns the whole TCP stream into  AES-CTR ciphertext after a
    | 64-byte random handshake - the traffic shape official clients/MTProxy use.
    | Plain framing exposes a fixed, fingerprintable byte pattern, so "obfuscated"
    | is the ban-safe default for userbots.
    |
    */
    'transport' => env('CLIENT_TRANSPORT', 'obfuscated'),

    /*
    |--------------------------------------------------------------------------
    | MTProxy Relay
    |--------------------------------------------------------------------------
    |
    | Route the obfuscated2 stream through an MTProxy server instead of a direct
    | DC socket - the client's TCP connection only ever touches the proxy, which
    | relays the still-end-to-end-encrypted stream to the target DC. Use this in
    | censored/high-risk networks where reaching a DC IP directly is itself a
    | signal. Enabling a proxy forces obfuscated2 regardless of "transport".
    |
    */
    'proxy' => [
        'enabled' => env('CLIENT_PROXY_ENABLED', false),
        'host'    => env('CLIENT_PROXY_HOST', ''),
        'port'    => (int) env('CLIENT_PROXY_PORT', 443),
        'secret'  => env('CLIENT_PROXY_SECRET', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | State Stores
    |--------------------------------------------------------------------------
    |
    | Each piece of MTProto state can pick its own storage backend, swapped with
    | one line. Drivers:
    |   "file" | "database" | "redis" | "cache" | "array" | "swoole-table"
    |
    | Omit a store (or its 'driver') to use the built-in file default. Wired:
    |   peer       peer-cache (id/username/phone -> access_hash)
    |   session    auth key, server salt, session id, DC, time delta
    |   state      update pts/qts/seq/date (+ per-channel pts)
    |   limit      rate-limit token buckets
    |
    | Switching a driver is zero-downtime: the new backend is read-through over
    | the old one, so a live session is recovered and copied across on first
    | access - no re-login. `file` is an implicit fallback for any non-file
    | driver (existing sessions live there). For other chains (e.g. redis ->
    | swoole-table), list earlier sources in 'migrate_from' (driver strings or
    | full blocks, highest priority first):
    |
    |   'session' => [
    |       'driver'       => 'swoole-table',
    |       'migrate_from' => ['redis', 'file'],   // file is added anyway
    |   ],
    |
    */
    'stores' => [
        'peer' => [
            'driver' => env('CLIENT_PEER_STORE', 'swoole-table'),
        ],
        'session' => [
            'driver' => env('CLIENT_SESSION_STORE', 'swoole-table'),
        ],
        'state' => [
            'driver' => env('CLIENT_STATE_STORE', 'swoole-table'),
        ],
        'limit' => [
            'driver' => env('CLIENT_LIMIT_STORE', 'swoole-table'),
        ],
    ],

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
        'driver' => env('CLIENT_SESSION_DRIVER', 'swoole-table'),
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
    | Human-like Pacing
    |--------------------------------------------------------------------------
    |
    | Where rate_limit caps throughput, this adds the opposite signal: a small
    | RANDOM delay before user-facing actions (send/edit/forward/read) so the
    | account's cadence never looks machine-regular. A bot firing sends exactly
    | back-to-back — even under the flood limit — is itself a detectable pattern.
    |
    |   min_think/max_think : think-time window (s) before any paced action
    |   chars_per_second    : simulated typing speed (drives text-length delay)
    |   max_typing          : cap on the typing-time component (s)
    |   jitter              : extra uniform random delay (s) on every action
    |
    | Off by default (adds latency); turn on for long-lived userbots where the
    | extra realism is worth it. Ban-safety is never traded — this only adds.
    |
    */
    'pacing' => [
        'enabled'          => env('CLIENT_HUMAN_PACING', false),
        'min_think'        => 0.3,
        'max_think'        => 1.5,
        'chars_per_second' => 18.0,
        'max_typing'       => 4.0,
        'jitter'           => 0.4,
    ],

    /*
    |--------------------------------------------------------------------------
    | Cross-DC Connection Pool
    |--------------------------------------------------------------------------
    |
    | Telegram media often lives on a different data centre than the account is
    | logged in on. The pool keeps the home connection live and opens secondary
    | connections to other DCs on demand, authorising each by transferring the
    | home session (auth.exportAuthorization -> auth.importAuthorization).
    | Connections are reused across downloads and closed once idle.
    |
    | Ban-safety: every secondary shares the home device fingerprint, rate
    | limiter and human pacer, so a fan of DC connections never bypasses the
    | global throttle. Keep max_connections small — a wide spread of live DC
    | sockets is itself a signal.
    |
    |   max_connections : max simultaneous secondary connections (home excluded)
    |   idle_timeout    : seconds before an idle secondary is closed (0 = never)
    |
    */
    'pool' => [
        'max_connections' => (int) env('CLIENT_POOL_MAX', 4),
        'idle_timeout'    => (float) env('CLIENT_POOL_IDLE', 300),
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
