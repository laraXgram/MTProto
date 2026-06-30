<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Core;

use LaraGram\MTProto\Exceptions\MTProtoException;

class ConnectionPool
{
    private Client $home;

    /**
     * Authorized secondary clients, keyed by base DC id.
     * @var array<int, Client>
     */
    private array $clients = [];

    /**
     * Last-use timestamp per DC (microtime), for idle eviction / LRU cap.
     * @var array<int, float>
     */
    private array $lastUsed = [];

    /**
     * Max simultaneous secondary connections (excludes the home connection).
     * Kept deliberately small: a wide fan of DC sockets is itself a signal.
     */
    private int $maxConnections;

    /** Close a secondary connection after this many idle seconds (0 = never). */
    private float $idleTimeout;

    public function __construct(Client $home, array $options = [])
    {
        $this->home           = $home;
        $this->maxConnections = max(1, (int) ($options['max_connections'] ?? 4));
        $this->idleTimeout    = max(0.0, (float) ($options['idle_timeout'] ?? 300.0));
    }

    /**
     * Get a connected, authorized client for the given DC.
     */
    public function connection(int $dcId): Client
    {
        $dcId = DataCenter::getBaseDcId($dcId);

        if ($dcId <= 0 || $dcId === $this->home->getDcId()) {
            return $this->home;
        }

        if (isset($this->clients[$dcId])) {
            $this->lastUsed[$dcId] = microtime(true);
            return $this->clients[$dcId];
        }

        $this->closeIdle();
        $this->enforceCap();

        $sibling = $this->home->cloneForDc($dcId);
        $sibling->connect($this->siblingSession($dcId));

        $this->importAuthorization($sibling, $dcId);

        $this->clients[$dcId]  = $sibling;
        $this->lastUsed[$dcId] = microtime(true);

        $this->home->getLogger()?->info("ConnectionPool: opened DC{$dcId} connection");

        return $sibling;
    }

    /**
     * Re-transfer the home authorization to an already-open secondary client.
     */
    public function ensureAuthorized(int $dcId): void
    {
        $dcId = DataCenter::getBaseDcId($dcId);

        if (isset($this->clients[$dcId])) {
            $this->importAuthorization($this->clients[$dcId], $dcId);
        }
    }

    /**
     * Close and drop every secondary connection (home is left untouched).
     */
    public function closeAll(): void
    {
        foreach ($this->clients as $client) {
            try { $client->disconnect(); } catch (\Throwable) {}
        }
        $this->clients  = [];
        $this->lastUsed = [];
    }

    /**
     * DC ids of the currently-open secondary connections.
     * @return int[]
     */
    public function openConnections(): array
    {
        return array_keys($this->clients);
    }

    /**
     * Transfer the home authorization onto a secondary client.
     */
    private function importAuthorization(Client $sibling, int $dcId): void
    {
        $exported = $this->home->invoke('auth.exportAuthorization', ['dc_id' => $dcId]);

        if (!is_array($exported) || !isset($exported['id'], $exported['bytes'])) {
            throw new MTProtoException("auth.exportAuthorization for DC{$dcId} returned no credentials");
        }

        $sibling->invoke('auth.importAuthorization', [
            'id'    => $exported['id'],
            'bytes' => $exported['bytes'],
        ]);
    }

    /**
     * Stable session name for a DC's secondary connection, derived from the home
     * session so multiple accounts never collide (e.g. `default.dc4`).
     */
    private function siblingSession(int $dcId): string
    {
        $base = $this->home->getSessionName();
        if ($base === '') {
            $base = 'default';
        }

        return "{$base}.dc{$dcId}";
    }

    /**
     * Close secondary connections that have been idle past the timeout.
     */
    private function closeIdle(): void
    {
        if ($this->idleTimeout <= 0.0) {
            return;
        }

        $now = microtime(true);
        foreach ($this->lastUsed as $dcId => $ts) {
            if (($now - $ts) >= $this->idleTimeout) {
                $this->close($dcId);
            }
        }
    }

    /**
     * Keep the pool within its connection cap by closing the least-recently-used
     * secondary before a new one is opened.
     */
    private function enforceCap(): void
    {
        while (count($this->clients) >= $this->maxConnections && $this->lastUsed !== []) {
            asort($this->lastUsed);
            $lruDc = array_key_first($this->lastUsed);
            $this->close((int) $lruDc);
        }
    }

    private function close(int $dcId): void
    {
        if (isset($this->clients[$dcId])) {
            try { $this->clients[$dcId]->disconnect(); } catch (\Throwable) {}
        }
        unset($this->clients[$dcId], $this->lastUsed[$dcId]);
    }
}
