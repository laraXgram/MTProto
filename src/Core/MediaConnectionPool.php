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

        $sameDc = $dcId === $this->home->getDcId();
        $logger = $this->home->getLogger();

        $existing = $this->sockets[$dcId] ?? [];
        if ($sameDc && $existing === []) {
            $existing[] = $this->home;
        }

        $exported = null;
        if (!$sameDc) {
            $this->home->pool()->connection($dcId);
            $exported = $this->home->invokeRaw('auth.exportAuthorization', ['dc_id' => $dcId]);
            if (!is_array($exported) || !isset($exported['id'], $exported['bytes'])) {
                throw new MTProtoException("auth.exportAuthorization for DC{$dcId} returned no credentials");
            }
        }

        $errors = [];

        $session = $this->home->getSession();
        while (count($existing) < $want) {
            try {
                if ($sameDc) {
                    $socket = $this->home->cloneForMedia(
                        $dcId,
                        $session->getAuthKey(),
                        $session->getServerSalt(),
                        $session->getTimeDelta(),
                    );
                    $socket->connect('media');
                    $socket->startPump();
                } else {
                    $socket = $this->home->cloneForDc($dcId, ['use_pump' => true]);
                    $socket->connect("media.dc{$dcId}." . count($existing));
                    $socket->startPump();
                    $socket->invokeRaw('auth.importAuthorization', [
                        'id' => $exported['id'],
                        'bytes' => $exported['bytes'],
                    ]);
                }

                $existing[] = $socket;
            } catch (\Throwable $e) {
                $errors[] = $e;
                $logger?->warning('MediaConnectionPool: extra socket to DC' . $dcId . " failed: {$e->getMessage()}");
                if (str_contains($e->getMessage(), 'FLOOD') || str_contains($e->getMessage(), '-429')) {
                    break;
                }
            }
        }

        if ($existing === []) {
            $first = $errors === [] ? null : reset($errors);
            throw new MTProtoException(
                "Failed to open any media socket to DC{$dcId}" . ($first !== null ? ": {$first->getMessage()}" : ''),
                0,
                $first,
            );
        }

        if ($errors !== []) {
            $logger?->warning(
                'MediaConnectionPool: ' . count($errors) . " socket(s) failed for DC{$dcId}; continuing with " . count($existing)
            );
        }

        $this->sockets[$dcId] = $existing;

        return array_slice($existing, 0, $want);
    }

    /**
     * Number of live media sockets currently open to a DC.
     */
    public function countFor(int $dcId): int
    {
        return count($this->sockets[DataCenter::getBaseDcId($dcId)] ?? []);
    }

    /**
     * Close and drop every media socket.
     */
    public function closeAll(): void
    {
        foreach ($this->sockets as $list) {
            foreach ($list as $socket) {
                if ($socket === $this->home) {
                    continue; // home is owned by the caller, never close it here
                }
                try {
                    $socket->disconnect();
                } catch (\Throwable) {
                }
            }
        }

        $this->sockets = [];
    }
}
