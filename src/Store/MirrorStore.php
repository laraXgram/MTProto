<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Store;

use LaraGram\MTProto\Contracts\Store;

final class MirrorStore implements Store
{
    /** @var list<Store> */
    private array $mirrors;

    public function __construct(private Store $primary, Store ...$mirrors)
    {
        $this->mirrors = array_values($mirrors);
    }

    public function get(string $key): ?string
    {
        $value = $this->primary->get($key);

        if ($value !== null) {
            return $value;
        }

        foreach ($this->mirrors as $mirror) {
            $value = $mirror->get($key);

            if ($value !== null) {
                $this->adopt($key, $value);

                return $value;
            }
        }

        return null;
    }

    public function put(string $key, string $value): void
    {
        try {
            $this->primary->put($key, $value);
        } catch (\Throwable) {
            //
        }

        foreach ($this->mirrors as $mirror) {
            try {
                $mirror->put($key, $value);
            } catch (\Throwable) {
                // A mirror write failure must not break the live operation; the
                // primary already holds the value and the next write retries.
            }
        }
    }

    public function has(string $key): bool
    {
        if ($this->primary->has($key)) {
            return true;
        }

        foreach ($this->mirrors as $mirror) {
            if ($mirror->has($key)) {
                return true;
            }
        }

        return false;
    }

    public function forget(string $key): bool
    {
        $ok = $this->primary->forget($key);

        foreach ($this->mirrors as $mirror) {
            $ok = $mirror->forget($key) && $ok;
        }

        return $ok;
    }

    /**
     * Restore a recovered value into the primary (fast) store on read-miss.
     */
    private function adopt(string $key, string $value): void
    {
        try {
            $this->primary->put($key, $value);
        } catch (\Throwable) {
            // Keep serving from the mirror; promotion of this key is skipped.
        }
    }
}
