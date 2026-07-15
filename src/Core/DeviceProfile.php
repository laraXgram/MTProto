<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Core;

/**
 * Device fingerprint presented to Telegram in `initConnection`.
 *
 * Telegram fingerprints clients by the device/system/app strings sent at
 * connection init. The previous code derived these from `php_uname()` +
 * `app_version '1.0.0'`, which screams "a PHP server is logged in here" - an
 * obvious, high-signal anomaly and a ban risk (ROADMAP B1).
 *
 * This value object resolves a realistic, *stable* fingerprint from one of a
 * handful of official-client presets (config-selected). It is deliberately
 * framework-agnostic (RULE 5): build it from a plain array, no container.
 *
 * Note on api_id (B6): the api_id is the user's own (from my.telegram.org) and
 * is NOT bundled here - pinning a single shared api_id across many accounts is
 * itself a flag. Pick a preset whose platform matches the api_id you registered.
 */
final class DeviceProfile
{
    public function __construct(
        public readonly string $deviceModel,
        public readonly string $systemVersion,
        public readonly string $appVersion,
        public readonly string $systemLangCode = 'en-US',
        public readonly string $langPack = '',
        public readonly string $langCode = 'en',
    ) {
    }

    /**
     * Official-client presets. Values mirror what the real clients send so the
     * fingerprint blends in. Stable by construction (no randomisation - a
     * rotating fingerprint is itself a flag).
     *
     * @var array<string, array<string, string>>
     */
    public const PRESETS = [
        'tdesktop' => [
            'device_model'     => 'Desktop',
            'system_version'   => 'Windows 10',
            'app_version'      => '5.7.3 x64',
            'system_lang_code' => 'en-US',
            'lang_pack'        => 'tdesktop',
            'lang_code'        => 'en',
        ],
        'android' => [
            'device_model'     => 'Samsung Galaxy S23',
            'system_version'   => 'SDK 34',
            'app_version'      => '10.6.1 (4365)',
            'system_lang_code' => 'en-US',
            'lang_pack'        => 'android',
            'lang_code'        => 'en',
        ],
        'ios' => [
            'device_model'     => 'iPhone 15 Pro',
            'system_version'   => '17.4.1',
            'app_version'      => '10.6.1',
            'system_lang_code' => 'en-US',
            'lang_pack'        => 'ios',
            'lang_code'        => 'en',
        ],
        'macos' => [
            'device_model'     => 'MacBook Pro',
            'system_version'   => '14.4.1',
            'app_version'      => '10.6.1',
            'system_lang_code' => 'en-US',
            'lang_pack'        => 'macos',
            'lang_code'        => 'en',
        ],
        'web' => [
            'device_model'     => 'Chrome 123',
            'system_version'   => 'Windows',
            'app_version'      => '1.0 K',
            'system_lang_code' => 'en-US',
            'lang_pack'        => 'webk',
            'lang_code'        => 'en',
        ],
    ];

    /** The preset used when none/an unknown one is configured. */
    public const DEFAULT_PRESET = 'tdesktop';

    /**
     * Build a profile from a named preset.
     */
    public static function preset(string $name): self
    {
        $p = self::PRESETS[$name] ?? self::PRESETS[self::DEFAULT_PRESET];

        return self::fromArray($p);
    }

    /**
     * Build a profile from a raw array (preset keys or explicit overrides).
     */
    public static function fromArray(array $a): self
    {
        return new self(
            deviceModel:    (string) ($a['device_model']     ?? 'Desktop'),
            systemVersion:  (string) ($a['system_version']   ?? 'Windows 10'),
            appVersion:     (string) ($a['app_version']      ?? '5.7.3 x64'),
            systemLangCode: (string) ($a['system_lang_code'] ?? 'en-US'),
            langPack:       (string) ($a['lang_pack']        ?? ''),
            langCode:       (string) ($a['lang_code']        ?? 'en'),
        );
    }

    /**
     * Resolve a profile from the `device` config section.
     *
     * Accepts: `['preset' => 'android']`, optionally with per-field overrides
     * merged on top, or a fully explicit field array.
     */
    public static function resolve(array $config): self
    {
        $presetName = (string) ($config['preset'] ?? self::DEFAULT_PRESET);
        $base = self::PRESETS[$presetName] ?? self::PRESETS[self::DEFAULT_PRESET];

        // Per-field overrides win over the preset.
        $merged = array_merge($base, array_filter(
            [
                'device_model'     => $config['device_model']     ?? $config['model']      ?? null,
                'system_version'   => $config['system_version']   ?? $config['system']     ?? null,
                'app_version'      => $config['app_version']       ?? null,
                'system_lang_code' => $config['system_lang_code'] ?? null,
                'lang_pack'        => $config['lang_pack']         ?? null,
                'lang_code'        => $config['lang_code']         ?? null,
            ],
            static fn ($v) => $v !== null,
        ));

        return self::fromArray($merged);
    }

    /**
     * The `initConnection` field fragment for this fingerprint.
     *
     * @return array<string, string>
     */
    public function toInitConnection(): array
    {
        return [
            'device_model'     => $this->deviceModel,
            'system_version'   => $this->systemVersion,
            'app_version'      => $this->appVersion,
            'system_lang_code' => $this->systemLangCode,
            'lang_pack'        => $this->langPack,
            'lang_code'        => $this->langCode,
        ];
    }
}
