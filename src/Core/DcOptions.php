<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Core;

/**
 * Process-wide registry of the DC endpoints Telegram advertises in
 * `help.getConfig` (`dcOption` list). Official clients send file transfers to
 * the `media_only` endpoints of a DC; media sockets do the same when one is known.
 */
final class DcOptions
{
    /**
     * @var array<string, array<int, list<array{ip: string, port: int, ipv6: bool, media_only: bool, tcpo_only: bool, cdn: bool}>>>
     *      keyed by "prod"/"test", then DC id
     */
    private static array $options = [];

    /**
     * Remember the endpoints of a `config` object (the initConnection answer).
     */
    public static function remember(array $config, bool $test = false): void
    {
        $byDc = [];
        foreach ($config['dc_options'] ?? [] as $option) {
            if (!is_array($option) || !isset($option['id'], $option['ip_address'], $option['port'])) {
                continue;
            }

            $byDc[(int) $option['id']][] = [
                'ip' => (string) $option['ip_address'],
                'port' => (int) $option['port'],
                'ipv6' => !empty($option['ipv6']),
                'media_only' => !empty($option['media_only']),
                'tcpo_only' => !empty($option['tcpo_only']),
                'cdn' => !empty($option['cdn']),
            ];
        }

        if ($byDc !== []) {
            self::$options[$test ? 'test' : 'prod'] = $byDc;
        }
    }

    /**
     * The media-only endpoint of a DC usable with the given transport, or null.
     *
     * @return array{0: string, 1: int}|null
     */
    public static function media(int $dcId, bool $test = false, bool $ipv6 = false, bool $obfuscated = false): ?array
    {
        foreach (self::$options[$test ? 'test' : 'prod'][$dcId] ?? [] as $option) {
            if ($option['media_only'] && !$option['cdn'] && $option['ipv6'] === $ipv6
                && (!$option['tcpo_only'] || $obfuscated)) {
                return [$option['ip'], $option['port']];
            }
        }

        return null;
    }

    /**
     * Forget every remembered endpoint.
     */
    public static function flush(): void
    {
        self::$options = [];
    }
}
