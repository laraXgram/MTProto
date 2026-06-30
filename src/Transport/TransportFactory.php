<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Transport;

use LaraGram\MTProto\Contracts\TransportInterface;

final class TransportFactory
{
    /**
     * @return array{transport: TransportInterface, obfuscated: bool, tag: string}
     */
    public static function make(string $name): array
    {
        return match (strtolower(trim($name))) {
            'intermediate'        => self::plain(new IntermediateTransport()),
            'intermediate_padded' => self::plain(new IntermediatePaddedTransport()),
            'full'                => self::plain(new FullTransport()),

            'obfuscated', 'obfuscated_abridged' => [
                'transport'  => new AbridgedTransport(),
                'obfuscated' => true,
                'tag'        => ObfuscatedConnection::TAG_ABRIDGED,
            ],
            'obfuscated_intermediate' => [
                'transport'  => new IntermediateTransport(),
                'obfuscated' => true,
                'tag'        => ObfuscatedConnection::TAG_INTERMEDIATE,
            ],

            default => self::plain(new AbridgedTransport()),
        };
    }

    /**
     * @return array{transport: TransportInterface, obfuscated: bool, tag: string}
     */
    private static function plain(TransportInterface $transport): array
    {
        return [
            'transport'  => $transport,
            'obfuscated' => false,
            'tag'        => ObfuscatedConnection::TAG_ABRIDGED,
        ];
    }
}
