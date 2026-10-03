<?php

namespace App\Currency\Services;

use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;

final class CurrencyCache
{
    private ?Repository $repository = null;

    public function remember(string $key, DateTimeInterface|int $ttl, Closure $resolver): mixed
    {
        $repository = $this->repository();
        $cached = $repository->get($key);
        if ($cached !== null) {
            return $cached;
        }

        $store = $repository->getStore();
        if (! $store instanceof LockProvider) {
            return $repository->remember($key, $ttl, $resolver);
        }

        return $store->lock("currency-cache:{$key}", 15)->block(5, function () use ($repository, $key, $ttl, $resolver): mixed {
            $cached = $repository->get($key);
            if ($cached !== null) {
                return $cached;
            }

            $value = $resolver();
            $repository->put($key, $value, $ttl);

            return $value;
        });
    }

    public function get(string $key): mixed
    {
        return $this->repository()->get($key);
    }

    public function put(string $key, mixed $value, DateTimeInterface|int $ttl): void
    {
        $this->repository()->put($key, $value, $ttl);
    }

    public function forget(string $key): void
    {
        $this->repository()->forget($key);
    }

    private function repository(): Repository
    {
        return $this->repository ??= Cache::store(config('currency.cache.store', 'file'));
    }
}
