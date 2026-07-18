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
                "Peer id {$peer} has no usable access_hash - the account must share a "
                . "dialog/chat/contact with it, or resolve it once by @username first."
            );
        }

        $ref = $this->parseReference($peer);

        return match ($ref['kind']) {
            'phone' => $this->resolveByPhone($ref['value']),
            'invite' => $this->resolveByInvite($ref['value']),
            default => $this->resolveByUsername($ref['value']),
        };
    }

    /**
     * Classify a string peer reference into a username, phone, or invite hash.
     * Understands @handle, bare handle, t.me / telegram.me / telegram.dog links
     * (username, `+hash`, `joinchat/hash`), and tg:// deep links.
     *
     * @return array{kind: string, value: string}
     */
    public function parseReference(string $ref): array
    {
        $ref = trim($ref);
        $lower = strtolower($ref);

        if (str_starts_with($lower, 'tg://')) {
            if (preg_match('~[?&]invite=([A-Za-z0-9_-]+)~', $ref, $m)) {
                return ['kind' => 'invite', 'value' => $m[1]];
            }
            if (preg_match('~[?&]domain=([A-Za-z0-9_]+)~', $ref, $m)) {
                return ['kind' => 'username', 'value' => strtolower($m[1])];
            }
            if (preg_match('~[?&]phone=\+?(\d+)~', $ref, $m)) {
                return ['kind' => 'phone', 'value' => $m[1]];
            }
        }

        if (preg_match('~(?:https?://)?(?:t(?:elegram)?\.me|telegram\.dog)/(.+)~i', $ref, $m)) {
            $path = ltrim($m[1], '/');

            if (preg_match('~^joinchat/([A-Za-z0-9_-]+)~i', $path, $mm)) {
                return ['kind' => 'invite', 'value' => $mm[1]];
            }
            if (preg_match('~^\+([A-Za-z0-9_-]+)~', $path, $mm)) {
                // t.me/+<digits> is a phone deep link; +<mixed> is an invite hash.
                return ctype_digit($mm[1])
                    ? ['kind' => 'phone', 'value' => $mm[1]]
                    : ['kind' => 'invite', 'value' => $mm[1]];
            }

            $seg = preg_split('~[/?#]~', $path)[0] ?? '';
            if ($seg !== '') {
                return ['kind' => 'username', 'value' => strtolower(ltrim($seg, '@'))];
            }
        }

        if (str_starts_with($ref, '+') && ctype_digit(substr($ref, 1))) {
            return ['kind' => 'phone', 'value' => substr($ref, 1)];
        }

        return ['kind' => 'username', 'value' => strtolower(ltrim($ref, '@'))];
    }

    /**
     * @throws MTProtoException
     */
    private function resolveByUsername(string $username): array
    {
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
     * @throws MTProtoException
     */
    private function resolveByPhone(string $phone): array
    {
        try {
            $result = $this->client->invokeRaw('contacts.resolvePhone', ['phone' => $phone]);
        } catch (\Throwable $e) {
            throw new MTProtoException("Failed to resolve phone +{$phone}: {$e->getMessage()}", 0, $e);
        }

        $this->peerDb->cachePeersFromResponse($result);
        $this->peerDb->save();

        $entry = $this->entryFromResolvedPeer($result);
        if ($entry !== null) {
            return $entry;
        }

        throw new MTProtoException("Phone +{$phone} did not resolve to a reachable peer");
    }

    /**
     * @throws MTProtoException
     */
    private function resolveByInvite(string $hash): array
    {
        $result = $this->checkInvite($hash);
        $ctor = $result['_'] ?? '';

        if (in_array($ctor, ['chatInviteAlready', 'chatInvitePeek'], true) && isset($result['chat']) && is_array($result['chat'])) {
            $this->peerDb->addFromTL($result['chat']);
            $this->peerDb->save();

            $id = $result['chat']['id'] ?? null;
            if ($id !== null) {
                $entry = $this->peerDb->getPeer((int) $id);
                if ($entry !== null) {
                    return $entry;
                }
            }
        }

        throw new MTProtoException(
            "Invite hash {$hash} points to a chat this account has not joined - call joinChat() first."
        );
    }

    /**
     * Inspect an invite hash without joining (`messages.checkChatInvite`).
     *
     * @throws MTProtoException
     */
    public function checkInvite(string $hash): array
    {
        try {
            $result = $this->client->invokeRaw('messages.checkChatInvite', ['hash' => $hash]);
        } catch (\Throwable $e) {
            throw new MTProtoException("Failed to check invite {$hash}: {$e->getMessage()}", 0, $e);
        }

        return is_array($result) ? $result : [];
    }

    /**
     * Extract the cached peer entry named by a `contacts.resolvedPeer`-shaped
     * response ({peer, chats, users}).
     */
    private function entryFromResolvedPeer(array $result): ?array
    {
        $peer = $result['peer'] ?? null;
        if (!is_array($peer)) {
            return null;
        }

        $id = $peer['user_id'] ?? $peer['channel_id'] ?? $peer['chat_id'] ?? null;

        return $id !== null ? $this->peerDb->getPeer((int) $id) : null;
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
                                "users.getUsers returned a min user for id {$id} (access_hash 0) - "
                                . "not addressable by id until a shared dialog/contact is primed."
                            );
                        }
                        $this->peerDb->addFromTL($user);
                    }
                }
            }
        } catch (\Throwable $e) {
            $logger?->debug("users.getUsers({$id}, hash 0) failed: {$e->getMessage()}");
        }

        // users.getUsers does not throw for a channel id - it just returns an
        // empty/userEmpty vector. Only stop here if it actually produced a
        // usable user; otherwise fall through and try the channel API, which is
        // where a member/admin bot learns a channel's real access_hash.
        $entry = $this->peerDb->getPeer($id);
        if ($entry !== null && $this->isComplete($entry)) {
            return;
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
            $this->client->getLogger()?->warning("Failed to resolve @{$username}: {$e->getMessage()}");
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
