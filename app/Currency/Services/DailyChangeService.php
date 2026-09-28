<?php

namespace App\Currency\Services;

use App\Currency\Exceptions\ProviderException;
use App\Currency\Contracts\DailyChangeProviderInterface;
use App\Currency\Enums\Currency;
use App\Currency\Exceptions\UnsupportedCurrencyPairException;

final class DailyChangeService
{
    public function __construct(
        private CurrencyCatalog $catalog,
        private CurrencyCache $cache,
        /** @var iterable<DailyChangeProviderInterface> */
        private iterable $providers,
    ) {}

    /** @param list<string> $codes @return array<string, ?float> */
    public function forCurrencies(array $codes): array
    {
        $changes = [];
        $pending = [];

        foreach (array_unique($codes) as $code) {
            try {
                $currency = $this->catalog->resolve($code);
                if ($currency->code === 'BYN') {
                    $changes[$code] = null;
                    continue;
                }

                $key = $this->cacheKey($currency);
                if (($cached = $this->cache->get($key)) !== null) {
                    $changes[$code] = $cached;
                    continue;
                }

                $pending[$code] = $currency;
            } catch (ProviderException|UnsupportedCurrencyPairException) {
                $changes[$code] = null;
            }
        }

        foreach ($this->providers as $provider) {
            $supported = array_values(array_filter($pending, static fn (Currency $currency): bool => $provider->supports($currency)));
            if ($supported === []) {
                continue;
            }

            try {
                $fresh = $provider->dailyChanges($supported);
                foreach ($supported as $currency) {
                    $change = $fresh[$currency->code] ?? null;
                    if ($change === null) {
                        continue;
                    }

                    $changes[$currency->code] = $change;
                    $this->cache->put($this->cacheKey($currency), $change, $this->ttl($currency));
                    unset($pending[$currency->code]);
                }
            } catch (ProviderException|UnsupportedCurrencyPairException) {
                continue;
            }
        }

        foreach (array_keys($pending) as $code) {
            $changes[$code] = null;
        }

        return $changes;
    }

    private function cacheKey(Currency $currency): string
    {
        return "daily-change:v2:{$currency->type->value}:{$currency->code}";
    }

    private function ttl(Currency $currency): \DateTimeInterface
    {
        return $currency->type->value === 'crypto' ? now()->addMinutes(15) : now()->addDay();
    }
}
