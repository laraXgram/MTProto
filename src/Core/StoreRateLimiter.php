<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Core;

use LaraGram\MTProto\Contracts\RateLimiterInterface;
use LaraGram\MTProto\Contracts\Store;

final class StoreRateLimiter implements RateLimiterInterface
{
    public function __construct(
        private readonly Store $store,
        private readonly float $defaultRate = 30.0,
        private readonly float $defaultCapacity = 30.0,
        private readonly string $prefix = 'rl:',
    ) {
    }

    public function reserve(string $key, ?float $rate = null, ?float $capacity = null): float
    {
        $rate     = $rate     ?? $this->defaultRate;
        $capacity = $capacity ?? $this->defaultCapacity;

        if ($rate <= 0.0 || $capacity <= 0.0) {
            return 0.0;
        }

        $now = microtime(true);
        [$tokens, $last] = $this->read($key, $capacity, $now);

        // Refill for the elapsed time, capped at capacity.
        $tokens = min($capacity, $tokens + max(0.0, $now - $last) * $rate);

        if ($tokens >= 1.0) {
            $this->write($key, $tokens - 1.0, $now);
            return 0.0;
        }

        $wait = (1.0 - $tokens) / $rate;
        $this->write($key, 0.0, $now + $wait);

        return $wait;
    }

    /**
     * @return array{0: float, 1: float}  [tokens, lastRefillTs]
     */
    private function read(string $key, float $capacity, float $now): array
    {
        $raw = $this->store->get($this->prefix . $key);

        if ($raw === null) {
            return [$capacity, $now];
        }

        $data = json_decode($raw, true);

        if (!is_array($data) || !isset($data[0], $data[1])) {
            return [$capacity, $now];
        }

        return [(float) $data[0], (float) $data[1]];
    }

    private function write(string $key, float $tokens, float $last): void
    {
        $this->store->put($this->prefix . $key, json_encode([$tokens, $last]));
    }
}
