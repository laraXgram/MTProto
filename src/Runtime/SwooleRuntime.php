<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Runtime;

use LaraGram\MTProto\Runtime\Contracts\Channel;
use LaraGram\MTProto\Runtime\Contracts\Runtime;
use LaraGram\MTProto\Runtime\Contracts\Table;

final class SwooleRuntime implements Runtime
{
    /** @var array<string, Table> named-table registry so the same name shares storage */
    private array $tables = [];

    public function __construct(bool $enableHooks = true)
    {
        if ($enableHooks && $this->isSupported() && class_exists(\Swoole\Runtime::class)) {
            \Swoole\Runtime::enableCoroutine(SWOOLE_HOOK_ALL);
        }
    }

    public function isSupported(): bool
    {
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
            \Swoole\Coroutine::create($callback);
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

    public function table(string $name, int $rows = 1024, int $valueSize = 8192): Table
    {
        return $this->tables[$name] ??= $this->isSupported()
            ? new SwooleTable($rows, $valueSize)
            : new ArrayTable($valueSize);
    }
}
