<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Core;

use LaraGram\MTProto\Contracts\Store;
use LaraGram\MTProto\Store\FileStore;

class PeerDatabase
{
    public const TYPE_USER = 'user';
    public const TYPE_BOT = 'bot';
    public const TYPE_CHAT = 'chat';
    public const TYPE_CHANNEL = 'channel';
    public const TYPE_SUPERGROUP = 'supergroup';

    /**
     * @var array<int, array>
     */
    private array $peers = [];

    /**
     * Username -> peer id index for fast lookups.
     *
     * @var array<string, int>
     */
    private array $usernameIndex = [];

    /**
     * Phone -> peer id index.
     *
     * @var array<string, int>
     */
    private array $phoneIndex = [];

    /**
     * Backing blob store and the key this database lives under.
     */
    private Store $store;
    private string $storeKey;

    /**
     * Whether the database was modified since last save.
     */
    private bool $dirty = false;

    /**
     * @param Store|null $store
     */
    public function __construct(
        string                           $sessionDir,
        string                           $sessionName,
        ?\LaraGram\Filesystem\Filesystem $files = null,
        ?Store                           $store = null,
    )
    {
        $this->store = $store ?? new FileStore($sessionDir, '.peers', $files);
        $this->storeKey = $sessionName;

        $this->load();
    }

    /**
     * Load the database from the backing store.
     */
    public function load(): void
    {
        $json = $this->store->get($this->storeKey);
        if ($json === null) {
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
            $id = (int)($entry['id'] ?? 0);
            if ($id === 0) {
                continue;
            }

            $this->peers[$id] = $entry;
            $this->indexEntry($entry);
        }
    }

    /**
     * Persist the database to the backing store.
     */
    public function save(): void
    {
        if (!$this->dirty) {
            return;
        }

        $json = json_encode(array_values($this->peers), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $this->store->put($this->storeKey, $json);
        $this->dirty = false;
    }

    /**
     * Add or update a peer from a raw TL user/chat/channel object.
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
        $id = (int)($user['id'] ?? 0);
        if ($id === 0) {
            return;
        }

        $accessHash = $user['access_hash'] ?? null;
        if (empty($accessHash) && !empty($this->peers[$id]['access_hash'])) {
            return;
        }

        $entry = [
            'id' => $id,
            'access_hash' => (int)($accessHash ?? 0),
            'type' => !empty($user['bot']) ? self::TYPE_BOT : self::TYPE_USER,
            'username' => $this->extractUsername($user),
            'phone' => $user['phone'] ?? null,
            'first_name' => $user['first_name'] ?? null,
            'last_name' => $user['last_name'] ?? null,
            'updated_at' => time(),
        ];

        $this->putEntry($id, $entry);
    }

    /**
     * Add a chat (basic group).
     */
    private function addChat(array $chat): void
    {
        $id = (int)($chat['id'] ?? 0);
        if ($id === 0) {
            return;
        }

        $entry = [
            'id' => $id,
            'access_hash' => 0,
            'type' => self::TYPE_CHAT,
            'username' => null,
            'phone' => null,
            'first_name' => $chat['title'] ?? null,
            'last_name' => null,
            'updated_at' => time(),
        ];

        $this->putEntry($id, $entry);
    }

    /**
     * Add a channel/supergroup.
     */
    private function addChannel(array $channel): void
    {
        $id = (int)($channel['id'] ?? 0);
        if ($id === 0) {
            return;
        }

        $accessHash = $channel['access_hash'] ?? null;
        if (empty($accessHash) && !empty($this->peers[$id]['access_hash'])) {
            return; // keep existing full entry; don't clobber with a min channel
        }

        $isSupergroup = !empty($channel['megagroup']);
        $entry = [
            'id' => $id,
            'access_hash' => (int)($accessHash ?? 0),
            'type' => $isSupergroup ? self::TYPE_SUPERGROUP : self::TYPE_CHANNEL,
            'username' => $this->extractUsername($channel),
            'phone' => null,
            'first_name' => $channel['title'] ?? null,
            'last_name' => null,
            'updated_at' => time(),
        ];

        $this->putEntry($id, $entry);
    }

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

    /**
     * Recursively walk an RPC response and cache every user / chat / channel.
     */
    public function cachePeersFromResponse(array $response): void
    {
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

        if (isset($response['_'])) {
            $this->addFromTL($response);
        }
    }

    private function putEntry(int $id, array $entry): void
    {
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
            $this->usernameIndex[strtolower($entry['username'])] = (int)$entry['id'];
        }
        if (!empty($entry['phone'])) {
            $this->phoneIndex[ltrim($entry['phone'], '+')] = (int)$entry['id'];
        }
    }

    /**
     * Extract the primary username from a TL object.
     */
    private function extractUsername(array $object): ?string
    {
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
