<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Core;

use LaraGram\MTProto\Exceptions\MTProtoException;

/**
 * Resolves simplified peer references into proper TL InputPeer / InputUser /
 * InputChannel arrays ready for serialisation.
 *
 * Accepted input formats:
 *  - int           → lookup by id in PeerDatabase
 *  - '@username'   → lookup by username (PeerDatabase first, then API resolve)
 *  - 'username'    → same as above (leading @ optional)
 *  - 'self' / 'me' → inputPeerSelf / inputUserSelf
 *  - array with '_' key → pass through (already a TL object)
 *
 * If the peer is not found in the cache, the resolver calls
 * contacts.resolveUsername (for strings) or users.getUsers / channels.getChannels
 * (for numeric ids with access_hash = 0) to fetch and cache it.
 *
 * Design follows Pyrogram's resolve_peer() and MadelineProto's getInfo().
 */
class PeerResolver
{
    public function __construct(
        private readonly PeerDatabase $peerDb,
        private readonly Client       $client,
    ) {}

    // ================================================================
    //  InputPeer  (for methods that take InputPeer type)
    // ================================================================

    /**
     * Resolve a peer reference to an InputPeer TL array.
     *
     * @param  int|string|array $peer
     * @return array  TL InputPeer object (with '_' key)
     */
    public function resolveInputPeer(int|string|array $peer): array
    {
        // Already a TL object
        if (is_array($peer) && isset($peer['_'])) {
            return $peer;
        }

        // 'self' / 'me'
        if (is_string($peer) && in_array(strtolower($peer), ['self', 'me'], true)) {
            return ['_' => 'inputPeerSelf'];
        }

        $entry = $this->resolvePeerEntry($peer);

        return match ($entry['type']) {
            PeerDatabase::TYPE_USER,
            PeerDatabase::TYPE_BOT     => [
                '_'           => 'inputPeerUser',
                'user_id'     => $entry['id'],
                'access_hash' => $entry['access_hash'],
            ],
            PeerDatabase::TYPE_CHAT    => [
                '_'       => 'inputPeerChat',
                'chat_id' => $entry['id'],
            ],
            PeerDatabase::TYPE_CHANNEL,
            PeerDatabase::TYPE_SUPERGROUP => [
                '_'           => 'inputPeerChannel',
                'channel_id'  => $entry['id'],
                'access_hash' => $entry['access_hash'],
            ],
            default => throw new MTProtoException("Unknown peer type: {$entry['type']}"),
        };
    }

    // ================================================================
    //  InputUser  (for methods that take InputUser type)
    // ================================================================

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
            '_'           => 'inputUser',
            'user_id'     => $entry['id'],
            'access_hash' => $entry['access_hash'],
        ];
    }

    // ================================================================
    //  InputChannel  (for methods that take InputChannel type)
    // ================================================================

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
            '_'           => 'inputChannel',
            'channel_id'  => $entry['id'],
            'access_hash' => $entry['access_hash'],
        ];
    }

    // ================================================================
    //  Core resolver
    // ================================================================

    /**
     * Resolve a peer reference to a PeerDatabase entry.
     *
     * If not found in cache, fetches from the API and caches it.
     *
     * @param  int|string $peer  Numeric id, @username, or username
     * @return array      Peer database entry
     * @throws MTProtoException If the peer cannot be resolved
     */
    private function resolvePeerEntry(int|string $peer): array
    {
        // ── Numeric id ─────────────────────────────────────────────────
        if (is_int($peer)) {
            $entry = $this->peerDb->getPeer($peer);
            if ($entry !== null) {
                return $entry;
            }

            // Not cached — try fetching with access_hash=0
            // (works for users we've interacted with)
            $this->fetchAndCachePeerById($peer);

            $entry = $this->peerDb->getPeer($peer);
            if ($entry !== null) {
                return $entry;
            }

            throw new MTProtoException("Peer id {$peer} not found — interact with this peer first or use @username");
        }

        // ── Username ───────────────────────────────────────────────────
        $username = strtolower(ltrim(trim($peer), '@'));

        // Check cache first
        $entry = $this->peerDb->getByUsername($username);
        if ($entry !== null) {
            return $entry;
        }

        // Resolve via API
        $this->fetchAndCacheByUsername($username);

        $entry = $this->peerDb->getByUsername($username);
        if ($entry !== null) {
            return $entry;
        }

        throw new MTProtoException("Username @{$username} not found");
    }

    // ================================================================
    //  API fetchers
    // ================================================================

    /**
     * Fetch a peer by numeric id from the API and cache it.
     *
     * We use users.getUsers(id=inputUser(id, 0)) for users and
     * channels.getChannels for channels. Chats always have access_hash = 0.
     */
    private function fetchAndCachePeerById(int $id): void
    {
        // Try as user first
        try {
            $result = $this->client->invokeRaw('users.getUsers', [
                'id' => [
                    ['_' => 'inputUser', 'user_id' => $id, 'access_hash' => 0]
                ]
            ]);

            if (!empty($result) && is_array($result)) {
                foreach ($result as $user) {
                    if (is_array($user) && ($user['_'] ?? '') === 'user') {
                        $this->peerDb->addFromTL($user);
                    }
                }
            }
            return;
        } catch (\Throwable) {
            // Not a user — try channel
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
        } catch (\Throwable) {
            // Ignore — caller will throw "not found"
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

            // contacts.resolvedPeer response has 'users' and 'chats' arrays
            $this->peerDb->cachePeersFromResponse($result);
        } catch (\Throwable $e) {
            error_log("[PeerResolver] Failed to resolve @{$username}: {$e->getMessage()}");
        }
    }
}
