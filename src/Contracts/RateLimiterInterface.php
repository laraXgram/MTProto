<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Contracts;

/**
 * Proactive client-side rate limiter.
 *
 * Telegram bans accounts that send faster than a human/official client would
 * (ROADMAP B2). Rather than wait for a FLOOD_WAIT and react, we *pace* sends
 * before they leave the process. A driver may keep state in-process or in a
 * cross-worker store (Surge table / Cache) — the contract is the same.
 */
interface RateLimiterInterface
{
    /**
     * Reserve one slot on the bucket identified by $key and return how many
     * seconds the caller must wait before the action is permitted (0.0 = now).
     *
     * Implementations consume the slot as part of this call, so a non-zero
     * return means "sleep this long, then proceed" — do not call again.
     *
     * @param  string      $key       Bucket identity (e.g. "global", "peer:123").
     * @param  float|null  $rate      Tokens refilled per second (null = driver default).
     * @param  float|null  $capacity  Bucket size / max burst (null = driver default).
     * @return float  Seconds to wait before proceeding.
     */
    public function reserve(string $key, ?float $rate = null, ?float $capacity = null): float;
}
