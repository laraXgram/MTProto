<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Core;

use LaraGram\MTProto\Exceptions\MTProtoException;

final class MediaConnectionPool
{
    /** Hard ceiling on media sockets per DC, regardless of config. */
    public const MAX_SOCKETS = 16;

    private Client $home;

    /** Configured default socket count. */
    private int $defaultCount;

    /**
     * Live media sockets, keyed by base DC id.
     * @var array<int, list<Client>>
     */
    private array $sockets = [];

    public function __construct(Client $home, array $options = [])
    {
        $this->home = $home;

        $count = (int) ($options['media_sockets'] ?? 4);
        $this->defaultCount = max(1, min(self::MAX_SOCKETS, $count));
    }

    /**
     * Return up to $want concurrent-capable clients for $dcId, minting and
     * caching them on first use. Must be called from inside a coroutine
     * container (it opens sockets and starts pumps).
     *
     * @return list<Client> Always at least one client.
     */
    public function sockets(int $dcId, ?int $want = null): array
    {
        $dcId = DataCenter::getBaseDcId($dcId);
        $want = max(1, min(self::MAX_SOCKETS, $want ?? $this->defaultCount));

        if (isset($this->sockets[$dcId]) && count($this->sockets[$dcId]) >= $want) {
            return array_slice($this->sockets[$dcId], 0, $want);
        }

        // Guarantee a valid auth key exists for this DC (home, or an
        // auth-imported sibling from the primary pool), then share it.
        $primary = $this->home->pool()->connection($dcId);
        $session = $primary->getSession();
        $authKey = $session->getAuthKey();

        if ($authKey === null) {
            throw new MTProtoException("No auth key available for DC{$dcId}; cannot open media sockets.");
        }

        $salt = $session->getServerSalt();
        $delta = $session->getTimeDelta();

        $existing = $this->sockets[$dcId] ?? [];
        for ($i = count($existing); $i < $want; $i++) {
            $socket = $this->home->cloneForMedia($dcId, $authKey, $salt, $delta);
            // Session name MUST match the key cloneForMedia seeded ('media'), so
            // StoreSession loads the shared auth key instead of handshaking. Each
            // socket has its own store, so the shared name never collides.
            $socket->connect('media');
            $socket->startPump();
            $existing[] = $socket;
        }

        $this->sockets[$dcId] = $existing;

        return array_slice($existing, 0, $want);
    }

    /**
     * Close and drop every media socket.
     */
    public function closeAll(): void
    {
        foreach ($this->sockets as $list) {
            foreach ($list as $socket) {
                try {
                    $socket->disconnect();
                } catch (\Throwable) {
                }
            }
        }

        $this->sockets = [];
    }
}
