<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Updates;

use LaraGram\MTProto\Contracts\SessionInterface;
use LaraGram\MTProto\Contracts\Store;
use LaraGram\MTProto\Store\FileStore;

class UpdateState
{
    /** Common message-box state */
    private int $pts = 0;
    private int $qts = 0;
    private int $date = 0;
    private int $seq = 0;

    /** Per-channel pts: channel_id -> pts */
    private array $channelPts = [];

    /** Whether this state has been initialised from the server */
    private bool $initialised = false;

    /** Backing blob store and the key this state lives under. */
    private Store $store;
    private string $storeKey;

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
        $this->store = $store ?? new FileStore($sessionDir, '_updates.json', $files);
        $this->storeKey = $sessionName;
        $this->load();
    }

    public function getPts(): int
    {
        return $this->pts;
    }

    public function getQts(): int
    {
        return $this->qts;
    }

    public function getDate(): int
    {
        return $this->date;
    }

    public function getSeq(): int
    {
        return $this->seq;
    }

    public function isInitialised(): bool
    {
        return $this->initialised;
    }

    public function setPts(int $pts): void
    {
        $this->pts = $pts;
    }

    public function setQts(int $qts): void
    {
        $this->qts = $qts;
    }

    public function setDate(int $date): void
    {
        $this->date = $date;
    }

    public function setSeq(int $seq): void
    {
        $this->seq = $seq;
    }

    public function setInitialised(bool $v = true): void
    {
        $this->initialised = $v;
    }

    /**
     * Bulk-set common state from an updates.State response.
     */
    public function applyState(array $state): void
    {
        $this->pts = $state['pts'] ?? $this->pts;
        $this->qts = $state['qts'] ?? $this->qts;
        $this->date = $state['date'] ?? $this->date;
        $this->seq = $state['seq'] ?? $this->seq;
        $this->initialised = true;
    }

    public function getChannelPts(int $channelId): int
    {
        return $this->channelPts[$channelId] ?? 0;
    }

    public function setChannelPts(int $channelId, int $pts): void
    {
        $this->channelPts[$channelId] = $pts;
    }

    public function getAllChannelPts(): array
    {
        return $this->channelPts;
    }

    /**
     * Check pts gap for common message box.
     *
     * @return int
     */
    public function checkPtsGap(int $newPts, int $ptsCount): int
    {
        if ($this->pts === 0) {
            return 0;
        }
        return ($this->pts + $ptsCount) - $newPts;
    }

    /**
     * Check qts gap.
     */
    public function checkQtsGap(int $newQts): int
    {
        if ($this->qts === 0) {
            return 0;
        }
        return ($this->qts + 1) - $newQts;
    }

    /**
     * Check seq gap for container ordering.
     *
     * @return int
     */
    public function checkSeqGap(int $newSeq): int
    {
        if ($this->seq === 0 || $newSeq === 0) {
            return 0; // seq=0 means no ordering info
        }
        return ($this->seq + 1) - $newSeq;
    }

    /**
     * Check channel pts gap.
     *
     * @return int
     */
    public function checkChannelPtsGap(int $channelId, int $newPts, int $ptsCount): int
    {
        $current = $this->getChannelPts($channelId);
        if ($current === 0) {
            return 0;
        }
        return ($current + $ptsCount) - $newPts;
    }

    public function save(): void
    {
        $data = [
            'pts' => $this->pts,
            'qts' => $this->qts,
            'date' => $this->date,
            'seq' => $this->seq,
            'initialised' => $this->initialised,
            'channel_pts' => $this->channelPts,
            'updated_at' => time(),
        ];

        $this->store->put($this->storeKey, json_encode($data));
    }

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

        $this->pts = $data['pts'] ?? 0;
        $this->qts = $data['qts'] ?? 0;
        $this->date = $data['date'] ?? 0;
        $this->seq = $data['seq'] ?? 0;
        $this->initialised = $data['initialised'] ?? false;
        $this->channelPts = $data['channel_pts'] ?? [];
    }

    /**
     * Export current state as array (useful for logging / debugging).
     */
    public function toArray(): array
    {
        return [
            'pts' => $this->pts,
            'qts' => $this->qts,
            'date' => $this->date,
            'seq' => $this->seq,
        ];
    }
}
