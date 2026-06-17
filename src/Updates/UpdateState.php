<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Updates;

use LaraGram\MTProto\Contracts\SessionInterface;

/**
 * Persistent update state (pts, qts, seq, date) for common and per-channel boxes.
 *
 * Stored alongside the session file as `<session>_updates.json`.
 * Loaded on startup, saved after every significant state change.
 */
class UpdateState
{
    /** Common message-box state */
    private int $pts  = 0;
    private int $qts  = 0;
    private int $date = 0;
    private int $seq  = 0;

    /** Per-channel pts: channel_id → pts */
    private array $channelPts = [];

    /** Whether this state has been initialised from the server */
    private bool $initialised = false;

    /** File path for persistence */
    private string $filePath;

    private \LaraGram\Filesystem\Filesystem $files;

    public function __construct(string $sessionDir, string $sessionName, ?\LaraGram\Filesystem\Filesystem $files = null)
    {
        $this->files = $files ?? new \LaraGram\Filesystem\Filesystem();
        $safeName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $sessionName);
        $this->filePath = rtrim($sessionDir, '/') . '/' . $safeName . '_updates.json';
        $this->load();
    }

    // ════════════════════════════════════════════════════════════════════
    //  Getters / Setters
    // ════════════════════════════════════════════════════════════════════

    public function getPts(): int  { return $this->pts; }
    public function getQts(): int  { return $this->qts; }
    public function getDate(): int { return $this->date; }
    public function getSeq(): int  { return $this->seq; }
    public function isInitialised(): bool { return $this->initialised; }

    public function setPts(int $pts): void   { $this->pts = $pts; }
    public function setQts(int $qts): void   { $this->qts = $qts; }
    public function setDate(int $date): void { $this->date = $date; }
    public function setSeq(int $seq): void   { $this->seq = $seq; }
    public function setInitialised(bool $v = true): void { $this->initialised = $v; }

    /**
     * Bulk-set common state from an updates.State response.
     */
    public function applyState(array $state): void
    {
        $this->pts  = $state['pts']  ?? $this->pts;
        $this->qts  = $state['qts']  ?? $this->qts;
        $this->date = $state['date'] ?? $this->date;
        $this->seq  = $state['seq']  ?? $this->seq;
        $this->initialised = true;
    }

    // ── Channel pts ────────────────────────────────────────────────────

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

    // ════════════════════════════════════════════════════════════════════
    //  Gap detection helpers
    // ════════════════════════════════════════════════════════════════════

    /**
     * Check pts gap for common message box.
     *
     * @return int  0 = no gap (apply), <0 = duplicate (skip), >0 = gap size
     */
    public function checkPtsGap(int $newPts, int $ptsCount): int
    {
        if ($this->pts === 0) {
            return 0; // First update, no gap
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
     * @return int  0 = expected, <0 = duplicate, >0 = gap
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
     */
    public function checkChannelPtsGap(int $channelId, int $newPts, int $ptsCount): int
    {
        $current = $this->getChannelPts($channelId);
        if ($current === 0) {
            return 0;
        }
        return ($current + $ptsCount) - $newPts;
    }

    // ════════════════════════════════════════════════════════════════════
    //  Persistence
    // ════════════════════════════════════════════════════════════════════

    public function save(): void
    {
        $data = [
            'pts'         => $this->pts,
            'qts'         => $this->qts,
            'date'        => $this->date,
            'seq'         => $this->seq,
            'initialised' => $this->initialised,
            'channel_pts' => $this->channelPts,
            'updated_at'  => time(),
        ];

        $this->files->ensureDirectoryExists(dirname($this->filePath), 0700);
        $this->files->replace($this->filePath, json_encode($data, JSON_PRETTY_PRINT));
    }

    public function load(): void
    {
        if (!$this->files->exists($this->filePath)) {
            return;
        }

        try {
            $json = $this->files->get($this->filePath);
        } catch (\Throwable) {
            return;
        }

        $data = json_decode($json, true);
        if (!is_array($data)) {
            return;
        }

        $this->pts         = $data['pts']  ?? 0;
        $this->qts         = $data['qts']  ?? 0;
        $this->date        = $data['date'] ?? 0;
        $this->seq         = $data['seq']  ?? 0;
        $this->initialised = $data['initialised'] ?? false;
        $this->channelPts  = $data['channel_pts'] ?? [];
    }

    /**
     * Export current state as array (useful for logging / debugging).
     */
    public function toArray(): array
    {
        return [
            'pts'  => $this->pts,
            'qts'  => $this->qts,
            'date' => $this->date,
            'seq'  => $this->seq,
        ];
    }
}
