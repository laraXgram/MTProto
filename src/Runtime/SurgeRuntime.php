<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Runtime;

use LaraGram\MTProto\Runtime\Contracts\Channel;
use LaraGram\MTProto\Runtime\Contracts\Runtime;
use LaraGram\MTProto\Runtime\Contracts\Table;

final class SurgeRuntime implements Runtime
{
    private SwooleRuntime $inner;

    /** @var array<string, Table> memo for Surge-backed tables resolved here */
    private array $surgeTables = [];

    /**
     * @param (callable(string): (\Swoole\Table|null))|null $surgeTableResolver
     */
    public function __construct(private $surgeTableResolver = null, bool $enableHooks = true)
    {
        $this->inner = new SwooleRuntime($enableHooks);
    }

    public function isSupported(): bool
    {
        return $this->inner->isSupported();
    }

    public function inCoroutine(): bool
    {
        return $this->inner->inCoroutine();
    }

    public function run(callable $main): void
    {
        $this->inner->run($main);
    }

    public function spawn(callable $fn): void
    {
        $this->inner->spawn($fn);
    }

    public function channel(int $capacity = 1): Channel
    {
        return $this->inner->channel($capacity);
    }

    public function timer(float $seconds, callable $callback): int
    {
        return $this->inner->timer($seconds, $callback);
    }

    public function clearTimer(int $timerId): void
    {
        $this->inner->clearTimer($timerId);
    }

    public function sleep(float $seconds): void
    {
        $this->inner->sleep($seconds);
    }

    public function table(string $name, int $rows = 1024, int $valueSize = 8192): Table
    {
        if (isset($this->surgeTables[$name])) {
            return $this->surgeTables[$name];
        }

        $shared = $this->resolveSurgeTable($name);

        if ($shared !== null) {
            return $this->surgeTables[$name] = new SurgeTable($shared, $valueSize);
        }

        return $this->inner->table($name, $rows, $valueSize);
    }

    /**
     * @return \Swoole\Table|null
     */
    private function resolveSurgeTable(string $name)
    {
        if ($this->surgeTableResolver === null) {
            return null;
        }

        try {
            $table = ($this->surgeTableResolver)($name);
        } catch (\Throwable) {
            return null;
        }

        return $table instanceof \Swoole\Table ? $table : null;
    }
}
