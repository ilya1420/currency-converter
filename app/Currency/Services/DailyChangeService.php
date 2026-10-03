<?php

namespace App\Currency\Services;

use App\Currency\Contracts\DailyChangeProviderInterface;
use App\Currency\Enums\Currency;
use App\Currency\Enums\CurrencyType;
use App\Currency\Enums\ProviderCapability;
use App\Currency\Exceptions\ProviderException;
use App\Currency\Exceptions\UnsupportedCurrencyPairException;
use Illuminate\Http\Client\ConnectionException;

final class DailyChangeService
{
    /** @var list<DailyChangeProviderInterface>|null */
    private ?array $resolvedProviders = null;

    public function __construct(
        private CurrencyCatalog $catalog,
        private CurrencyCache $cache,
        /** @var iterable<DailyChangeProviderInterface> */
        private iterable $providers,
        private ProviderSelectionService $selections,
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

                $capability = $this->capabilityFor($currency);
                $key = $this->cacheKey($currency, $this->selections->cacheIdentity($capability));
                if (($cached = $this->cache->get($key)) !== null) {
                    $changes[$code] = $cached;

                    continue;
                }

                $pending[$code] = $currency;
            } catch (ProviderException|UnsupportedCurrencyPairException) {
                $changes[$code] = null;
            }
        }

        foreach ([CurrencyType::FIAT, CurrencyType::CRYPTO] as $type) {
            $capability = $this->capabilityForType($type);
            $configured = $this->selections->configured($capability);
            if (array_filter($pending, static fn (Currency $currency): bool => $currency->type === $type) === []) {
                continue;
            }
            try {
                $providers = $this->selections->candidates($capability, $this->providerList());
            } catch (ProviderException) {
                continue;
            }

            foreach ($providers as $provider) {
                $supported = array_values(array_filter($pending, static fn (Currency $currency): bool => $currency->type === $type && $provider->supports($currency)));
                if ($supported === []) {
                    continue;
                }

                try {
                    $fresh = $provider->dailyChanges($supported);
                } catch (ConnectionException|ProviderException|UnsupportedCurrencyPairException) {
                    foreach ($supported as $currency) {
                        $changes[$currency->code] = null;
                        unset($pending[$currency->code]);
                    }

                    continue;
                }

                foreach ($supported as $currency) {
                    $change = $fresh[$currency->code] ?? null;
                    $changes[$currency->code] = $change;
                    if ($change !== null) {
                        $selectionId = $this->selections->cacheIdentity($capability);
                        $this->cache->put($this->cacheKey($currency, $selectionId), $change, $this->ttl($currency));
                        unset($pending[$currency->code]);
                    } elseif ($configured !== null) {
                        unset($pending[$currency->code]);
                    }
                }
            }
        }

        foreach (array_keys($pending) as $code) {
            $changes[$code] = null;
        }

        return $changes;
    }

    private function cacheKey(Currency $currency, string $selectionId): string
    {
        return "daily-change:v4:{$selectionId}:{$currency->type->value}:{$currency->code}";
    }

    private function capabilityFor(Currency $currency): ProviderCapability
    {
        return $this->capabilityForType($currency->type);
    }

    private function capabilityForType(CurrencyType $type): ProviderCapability
    {
        return $type === CurrencyType::CRYPTO ? ProviderCapability::CRYPTO_DAILY_CHANGES : ProviderCapability::FIAT_DAILY_CHANGES;
    }

    /** @return list<DailyChangeProviderInterface> */
    private function providerList(): array
    {
        return $this->resolvedProviders ??= is_array($this->providers)
            ? array_values($this->providers)
            : iterator_to_array($this->providers, false);
    }

    private function ttl(Currency $currency): \DateTimeInterface
    {
        return $currency->type->value === 'crypto' ? now()->addMinutes(15) : now()->addDay();
    }
}
