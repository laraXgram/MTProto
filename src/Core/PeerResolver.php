<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Core;

use LaraGram\MTProto\Exceptions\MTProtoException;

class PeerResolver
{
    public function __construct(
        private readonly PeerDatabase $peerDb,
        private readonly Client       $client,
    )
    {
    }

    /**
     * Resolve a peer reference to an InputPeer TL array.
     *
     * @param int|string|array $peer
     * @return array
     */
    public function resolveInputPeer(int|string|array $peer): array
    {
        if (is_array($peer) && isset($peer['_'])) {
            return $peer;
        }

        if (is_string($peer) && in_array(strtolower($peer), ['self', 'me'], true)) {
            return ['_' => 'inputPeerSelf'];
        }

        $entry = $this->resolvePeerEntry($peer);

        return match ($entry['type']) {
            PeerDatabase::TYPE_USER,
            PeerDatabase::TYPE_BOT => [
                '_' => 'inputPeerUser',
                'user_id' => $entry['id'],
                'access_hash' => $entry['access_hash'],
            ],
            PeerDatabase::TYPE_CHAT => [
                '_' => 'inputPeerChat',
                'chat_id' => $entry['id'],
            ],
            PeerDatabase::TYPE_CHANNEL,
            PeerDatabase::TYPE_SUPERGROUP => [
                '_' => 'inputPeerChannel',
                'channel_id' => $entry['id'],
                'access_hash' => $entry['access_hash'],
            ],
            default => throw new MTProtoException("Unknown peer type: {$entry['type']}"),
        };
    }

    /**
     * Resolve a peer reference to an InputUser TL array.
     */
    public function resolveInputUser(int|string|array $peer): array
    {
        if (is_array($peer) && isset($peer['_'])) {
            return $peer;
        }

        if (is_string($peer) && in_array(strtolower($peer), ['self', 'me'], true)) {
            return ['_' => 'inputUserSelf'];
        }

        $entry = $this->resolvePeerEntry($peer);

        if (!in_array($entry['type'], [PeerDatabase::TYPE_USER, PeerDatabase::TYPE_BOT], true)) {
            throw new MTProtoException("Expected user, got {$entry['type']} for peer {$peer}");
        }

        return [
            '_' => 'inputUser',
            'user_id' => $entry['id'],
            'access_hash' => $entry['access_hash'],
        ];
    }

    /**
     * Resolve a peer reference to an InputChannel TL array.
     */
    public function resolveInputChannel(int|string|array $peer): array
    {
        if (is_array($peer) && isset($peer['_'])) {
            return $peer;
        }

        $entry = $this->resolvePeerEntry($peer);

        if (!in_array($entry['type'], [PeerDatabase::TYPE_CHANNEL, PeerDatabase::TYPE_SUPERGROUP], true)) {
            throw new MTProtoException("Expected channel/supergroup, got {$entry['type']} for peer {$peer}");
        }

        return [
            '_' => 'inputChannel',
            'channel_id' => $entry['id'],
            'access_hash' => $entry['access_hash'],
        ];
    }

    /**
     * Resolve a peer reference to a PeerDatabase entry.
     *
     * @param int|string $peer
     * @return array
     * @throws MTProtoException
     */
    private function resolvePeerEntry(int|string $peer): array
    {
        if (is_int($peer)) {
            if ($peer < 0) {
                $peer = $this->normalizeBotApiId($peer);
            }

            $entry = $this->peerDb->getPeer($peer);
            if ($entry !== null && $this->isComplete($entry)) {
                return $entry;
            }

            $this->fetchAndCachePeerById($peer);

            $entry = $this->peerDb->getPeer($peer);
            if ($entry !== null && $this->isComplete($entry)) {
                return $entry;
            }

            throw new MTProtoException(
                "Peer id {$peer} has no usable access_hash — the account must share a "
                . "dialog/chat/contact with it, or resolve it once by @username first."
            );
        }

        $username = strtolower(ltrim(trim($peer), '@'));

        $entry = $this->peerDb->getByUsername($username);
        if ($entry !== null) {
            return $entry;
        }

        $this->fetchAndCacheByUsername($username);

        $entry = $this->peerDb->getByUsername($username);
        if ($entry !== null) {
            return $entry;
        }

        throw new MTProtoException("Username @{$username} not found");
    }

    /**
     * Whether a cached entry is usable for input construction. Users and
     * channels need a non-zero access_hash; a zero hash means a "min" peer that
     * must be re-resolved before it can be addressed. Basic groups carry no
     * access_hash and are always complete.
     */
    private function isComplete(array $entry): bool
    {
        if (($entry['type'] ?? '') === PeerDatabase::TYPE_CHAT) {
            return true;
        }

        return !empty($entry['access_hash']);
    }

    /**
     * Fetch a peer by numeric id from the API and cache it.
     */
    private function fetchAndCachePeerById(int $id): void
    {
        $logger = $this->client->getLogger();

        try {
            $result = $this->client->invokeRaw('users.getUsers', [
                'id' => [
                    ['_' => 'inputUser', 'user_id' => $id, 'access_hash' => 0]
                ]
            ]);

            if (!empty($result) && is_array($result)) {
                foreach ($result as $user) {
                    if (is_array($user) && ($user['_'] ?? '') === 'user') {
                        if (empty($user['access_hash'])) {
                            $logger?->warning(
                                "users.getUsers returned a min user for id {$id} (access_hash 0) — "
                                . "not addressable by id until a shared dialog/contact is primed."
                            );
                        }
                        $this->peerDb->addFromTL($user);
                    }
                }
            }
            return;
        } catch (\Throwable $e) {
            $logger?->debug("users.getUsers({$id}, hash 0) failed: {$e->getMessage()}");
        }

        try {
            $result = $this->client->invokeRaw('channels.getChannels', [
                'id' => [
                    ['_' => 'inputChannel', 'channel_id' => $id, 'access_hash' => 0]
                ]
            ]);

            if (isset($result['chats'])) {
                foreach ($result['chats'] as $chat) {
                    if (is_array($chat)) {
                        $this->peerDb->addFromTL($chat);
                    }
                }
            }
        } catch (\Throwable $e) {
            $logger?->debug("channels.getChannels({$id}, hash 0) failed: {$e->getMessage()}");
        }
    }

    /**
     * Resolve a username via contacts.resolveUsername and cache the result.
     */
    private function fetchAndCacheByUsername(string $username): void
    {
        try {
            $result = $this->client->invokeRaw('contacts.resolveUsername', [
                'username' => $username
            ]);

            $this->peerDb->cachePeersFromResponse($result);
        } catch (\Throwable $e) {
            $this->client->getLogger()->warning("Failed to resolve @{$username}: {$e->getMessage()}");
        }
    }

    /**
     * Convert Bot API negative IDs to MTProto raw IDs.
     *
     * @param int $id
     * @return int
     */
    private function normalizeBotApiId(int $id): int
    {
        if ($id <= -1000000000000) {
            return -($id + 1000000000000);
        }

        return -$id;
    }
}
