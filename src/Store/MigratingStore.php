<?php

declare(strict_types=1);

namespace LaraGram\MTProto\Store;

use LaraGram\MTProto\Contracts\Store;

final class MigratingStore implements Store
{
    /** @var list<Store> */
    private array $fallbacks;

    public function __construct(private Store $primary, Store ...$fallbacks)
    {
        $this->fallbacks = array_values($fallbacks);
    }

    public function get(string $key): ?string
    {
        $value = $this->primary->get($key);

        if ($value !== null) {
            return $value;
        }

        foreach ($this->fallbacks as $fallback) {
            $value = $fallback->get($key);

            if ($value !== null) {
                $this->adopt($key, $value);

                return $value;
            }
        }

        return null;
    }

    public function put(string $key, string $value): void
    {
        $this->primary->put($key, $value);
    }

    public function has(string $key): bool
    {
        if ($this->primary->has($key)) {
            return true;
        }

        foreach ($this->fallbacks as $fallback) {
            if ($fallback->has($key)) {
                return true;
            }
        }

        return false;
    }

    public function forget(string $key): bool
    {
        $ok = $this->primary->forget($key);

        foreach ($this->fallbacks as $fallback) {
            $ok = $fallback->forget($key) && $ok;
        }

        return $ok;
    }

    private function adopt(string $key, string $value): void
    {
        try {
            $this->primary->put($key, $value);
        } catch (\Throwable) {
            // Keep serving from the fallback; migration of this key is skipped.
        }
    }
}
