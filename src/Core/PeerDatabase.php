<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Core;

/**
 * Persistent peer database.
 *
 * Caches id → {access_hash, type, username, first_name, last_name}
 * so that the client can construct InputPeer / InputUser / InputChannel
 * without a prior contacts.resolveUsername call.
 *
 * Storage is a simple JSON file kept alongside session files.
 *
 * Design inspired by:
 *  - MadelineProto  PeerDatabase (DbArray indexed by peer id)
 *  - Pyrogram       SQLite "peers" table  (id, access_hash, type, username, phone)
 */
class PeerDatabase
{
    // ── peer types (mirroring TL constructors) ─────────────────────────
    public const TYPE_USER    = 'user';
    public const TYPE_BOT     = 'bot';
    public const TYPE_CHAT    = 'chat';
    public const TYPE_CHANNEL = 'channel';
    public const TYPE_SUPERGROUP = 'supergroup';

    /**
     * Peer entries indexed by numeric id.
     * Each entry: [
     *   'id'          => int,
     *   'access_hash' => int,
     *   'type'        => string,   // user|bot|chat|channel|supergroup
     *   'username'    => ?string,
     *   'phone'       => ?string,
     *   'first_name'  => ?string,
     *   'last_name'   => ?string,
     *   'updated_at'  => int,      // unix ts
     * ]
     *
     * @var array<int, array>
     */
    private array $peers = [];

    /**
     * Username → peer id index for fast lookups.
     *
     * @var array<string, int>
     */
    private array $usernameIndex = [];

    /**
     * Phone → peer id index.
     *
     * @var array<string, int>
     */
    private array $phoneIndex = [];

    /**
     * Path to the JSON file.
     */
    private string $filePath;

    /**
     * Whether the database was modified since last save.
     */
    private bool $dirty = false;

    // ================================================================
    //  Construction / Persistence
    // ================================================================

    public function __construct(string $sessionDir, string $sessionName)
    {
        if (!is_dir($sessionDir)) {
            mkdir($sessionDir, 0700, true);
        }

        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $sessionName);
        $this->filePath = rtrim($sessionDir, '/') . '/' . $safe . '.peers';

        $this->load();
    }

    /**
     * Load the database from disk.
     */
    public function load(): void
    {
        if (!file_exists($this->filePath)) {
            return;
        }

        $json = file_get_contents($this->filePath);
        if ($json === false) {
            return;
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return;
        }

        $this->peers = [];
        $this->usernameIndex = [];
        $this->phoneIndex = [];

        foreach ($data as $entry) {
            $id = (int) ($entry['id'] ?? 0);
            if ($id === 0) {
                continue;
            }

            $this->peers[$id] = $entry;
            $this->indexEntry($entry);
        }
    }

    /**
     * Persist the database to disk.
     */
    public function save(): void
    {
        if (!$this->dirty) {
            return;
        }

        $json = json_encode(array_values($this->peers), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        file_put_contents($this->filePath, $json, LOCK_EX);
        $this->dirty = false;
    }

    // ================================================================
    //  Adding peers
    // ================================================================

    /**
     * Add or update a peer from a raw TL user/chat/channel object.
     *
     * Pyrogram-style: called after every RPC response via `cachePeersFromResponse()`.
     */
    public function addFromTL(array $object): void
    {
        $constructor = $object['_'] ?? '';

        switch ($constructor) {
            case 'user':
                $this->addUser($object);
                break;

            case 'chat':
            case 'chatForbidden':
                $this->addChat($object);
                break;

            case 'channel':
            case 'channelForbidden':
                $this->addChannel($object);
                break;

            // Silently ignore empties / unknowns
        }
    }

    /**
     * Add a user.
     */
    private function addUser(array $user): void
    {
        $id = (int) ($user['id'] ?? 0);
        if ($id === 0) {
            return;
        }

        // Skip "min" users without access_hash if we already have a full entry
        $accessHash = $user['access_hash'] ?? null;
        if ($accessHash === null && isset($this->peers[$id]['access_hash'])) {
            return; // keep existing full entry
        }

        $entry = [
            'id'          => $id,
            'access_hash' => (int) ($accessHash ?? 0),
            'type'        => !empty($user['bot']) ? self::TYPE_BOT : self::TYPE_USER,
            'username'    => $this->extractUsername($user),
            'phone'       => $user['phone'] ?? null,
            'first_name'  => $user['first_name'] ?? null,
            'last_name'   => $user['last_name'] ?? null,
            'updated_at'  => time(),
        ];

        $this->putEntry($id, $entry);
    }

    /**
     * Add a chat (basic group).
     */
    private function addChat(array $chat): void
    {
        $id = (int) ($chat['id'] ?? 0);
        if ($id === 0) {
            return;
        }

        $entry = [
            'id'          => $id,
            'access_hash' => 0,
            'type'        => self::TYPE_CHAT,
            'username'    => null,
            'phone'       => null,
            'first_name'  => $chat['title'] ?? null,
            'last_name'   => null,
            'updated_at'  => time(),
        ];

        $this->putEntry($id, $entry);
    }

    /**
     * Add a channel/supergroup.
     */
    private function addChannel(array $channel): void
    {
        $id = (int) ($channel['id'] ?? 0);
        if ($id === 0) {
            return;
        }

        $accessHash = $channel['access_hash'] ?? null;
        if ($accessHash === null && isset($this->peers[$id]['access_hash'])) {
            return;
        }

        $isSupergroup = !empty($channel['megagroup']);
        $entry = [
            'id'          => $id,
            'access_hash' => (int) ($accessHash ?? 0),
            'type'        => $isSupergroup ? self::TYPE_SUPERGROUP : self::TYPE_CHANNEL,
            'username'    => $this->extractUsername($channel),
            'phone'       => null,
            'first_name'  => $channel['title'] ?? null,
            'last_name'   => null,
            'updated_at'  => time(),
        ];

        $this->putEntry($id, $entry);
    }

    // ================================================================
    //  Lookups
    // ================================================================

    /**
     * Get a peer entry by numeric id.
     *
     * @return array|null  The peer entry or null
     */
    public function getPeer(int $id): ?array
    {
        return $this->peers[$id] ?? null;
    }

    /**
     * Check if a peer exists.
     */
    public function hasPeer(int $id): bool
    {
        return isset($this->peers[$id]);
    }

    /**
     * Get a peer by username (case-insensitive, with or without @).
     */
    public function getByUsername(string $username): ?array
    {
        $username = strtolower(ltrim($username, '@'));
        $id = $this->usernameIndex[$username] ?? null;

        if ($id === null) {
            return null;
        }

        return $this->peers[$id] ?? null;
    }

    /**
     * Get a peer by phone number.
     */
    public function getByPhone(string $phone): ?array
    {
        $phone = ltrim($phone, '+');
        $id = $this->phoneIndex[$phone] ?? null;

        return $id !== null ? ($this->peers[$id] ?? null) : null;
    }

    /**
     * Get all cached peers.
     *
     * @return array<int, array>
     */
    public function getAll(): array
    {
        return $this->peers;
    }

    /**
     * Get the total number of cached peers.
     */
    public function count(): int
    {
        return count($this->peers);
    }

    // ================================================================
    //  Bulk caching from RPC responses  (Pyrogram-style)
    // ================================================================

    /**
     * Recursively walk an RPC response and cache every user / chat / channel.
     *
     * Pyrogram does this in `invoke()`:
     *   await self.fetch_peers(getattr(r, "users", []))
     *   await self.fetch_peers(getattr(r, "chats", []))
     *
     * We do the same but also walk nested objects.
     */
    public function cachePeersFromResponse(array $response): void
    {
        // Top-level "users" and "chats" arrays (very common in Telegram responses)
        if (isset($response['users']) && is_array($response['users'])) {
            foreach ($response['users'] as $user) {
                if (is_array($user)) {
                    $this->addFromTL($user);
                }
            }
        }

        if (isset($response['chats']) && is_array($response['chats'])) {
            foreach ($response['chats'] as $chat) {
                if (is_array($chat)) {
                    $this->addFromTL($chat);
                }
            }
        }

        // Also check the response itself (e.g. contacts.resolvedPeer has 'peer')
        if (isset($response['_'])) {
            $this->addFromTL($response);
        }
    }

    // ================================================================
    //  Internals
    // ================================================================

    private function putEntry(int $id, array $entry): void
    {
        // Remove old username/phone indexes if entry exists
        if (isset($this->peers[$id])) {
            $old = $this->peers[$id];
            if (!empty($old['username'])) {
                unset($this->usernameIndex[strtolower($old['username'])]);
            }
            if (!empty($old['phone'])) {
                unset($this->phoneIndex[ltrim($old['phone'], '+')]);
            }
        }

        $this->peers[$id] = $entry;
        $this->indexEntry($entry);
        $this->dirty = true;
    }

    private function indexEntry(array $entry): void
    {
        if (!empty($entry['username'])) {
            $this->usernameIndex[strtolower($entry['username'])] = (int) $entry['id'];
        }
        if (!empty($entry['phone'])) {
            $this->phoneIndex[ltrim($entry['phone'], '+')] = (int) $entry['id'];
        }
    }

    /**
     * Extract the primary username from a TL object.
     */
    private function extractUsername(array $object): ?string
    {
        // Prefer 'username' field; fall back to first entry of 'usernames' array
        if (!empty($object['username'])) {
            return $object['username'];
        }

        if (!empty($object['usernames']) && is_array($object['usernames'])) {
            foreach ($object['usernames'] as $u) {
                if (is_array($u) && !empty($u['username'])) {
                    return $u['username'];
                }
            }
        }

        return null;
    }
}
