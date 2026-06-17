<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Runtime;

use LaraGram\MTProto\Runtime\Contracts\Channel;
use LaraGram\MTProto\Runtime\Contracts\Runtime;

/**
 * Swoole/OpenSwoole-backed {@see Runtime} — the single isolation point for the
 * coroutine extension. Every other class talks to the contract.
 *
 * Enables `SWOOLE_HOOK_ALL` on construction so the blocking socket I/O used by
 * SyncConnection yields to the scheduler instead of blocking the worker.
 */
final class SwooleRuntime implements Runtime
{
    public function __construct(bool $enableHooks = true)
    {
        if ($enableHooks && $this->isSupported() && class_exists(\Swoole\Runtime::class)) {
            \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
        }
    }

    public function isSupported(): bool
    {
        // Prefer Surge's extension probe so detection matches the server runtime;
        // fall back to a bare check when Surge is not installed (RULE 2).
        if (class_exists(\LaraGram\Surge\Swoole\SwooleExtension::class)) {
            return (new \LaraGram\Surge\Swoole\SwooleExtension())->isInstalled();
        }

        return extension_loaded('swoole') || extension_loaded('openswoole');
    }

    public function inCoroutine(): bool
    {
        return $this->isSupported() && \Swoole\Coroutine::getCid() !== -1;
    }

    public function run(callable $main): void
    {
        if ($this->inCoroutine()) {
            $main();
            return;
        }
        \Swoole\Coroutine\run($main);
    }

    public function spawn(callable $fn): void
    {
        \Swoole\Coroutine::create($fn);
    }

    public function channel(int $capacity = 1): Channel
    {
        return new SwooleChannel($capacity);
    }

    public function timer(float $seconds, callable $callback): int
    {
        $ms = max(1, (int) ($seconds * 1000));

        return \Swoole\Timer::tick($ms, static function () use ($callback): void {
            $callback();
        });
    }

    public function clearTimer(int $timerId): void
    {
        \Swoole\Timer::clear($timerId);
    }

    public function sleep(float $seconds): void
    {
        \Swoole\Coroutine::sleep($seconds);
    }
}
