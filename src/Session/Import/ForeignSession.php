<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Session\Import;

final class ForeignSession
{
    /**
     * @param int    $dcId     Home datacenter id (address resolved via DataCenter).
     * @param string $authKey  Raw 256-byte permanent auth key.
     * @param int|null $userId  Logged-in account/bot id, when known.
     * @param bool   $isBot    Whether the session belongs to a bot.
     * @param bool   $testMode Whether the key was minted on Telegram's test DCs.
     * @param string $source   Originating library: pyrogram|telethon|madeline.
     * @param list<array<string,mixed>> $peers PeerDatabase-shaped entries.
     */
    public function __construct(
        public readonly int $dcId,
        public readonly string $authKey,
        public readonly ?int $userId = null,
        public readonly bool $isBot = false,
        public readonly bool $testMode = false,
        public readonly string $source = 'unknown',
        public array $peers = [],
    ) {
        if (strlen($authKey) !== 256) {
            throw new \InvalidArgumentException(
                'Foreign auth key must be exactly 256 bytes, got ' . strlen($authKey) . '.'
            );
        }

        if ($dcId < 1 || $dcId > 10) {
            throw new \InvalidArgumentException("Foreign session has an implausible dc_id ({$dcId}).");
        }
    }

    /**
     * Build a PeerDatabase-shaped entry from a marked bot-API id.
     *
     * @return array<string,mixed>
     */
    public static function makePeer(
        int $markedId,
        int $accessHash,
        ?string $sourceType = null,
        ?string $username = null,
        ?string $phone = null,
        ?string $firstName = null,
        ?string $lastName = null,
    ): array {
        [$rawId, $type] = PeerMarking::unmarkWithType($markedId, $sourceType);

        return [
            'id' => $rawId,
            'access_hash' => $accessHash,
            'type' => $type,
            'username' => $username !== null && $username !== '' ? ltrim($username, '@') : null,
            'phone' => $phone !== null && $phone !== '' ? $phone : null,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'updated_at' => time(),
        ];
    }

    public function peerCount(): int
    {
        return count($this->peers);
    }
}
