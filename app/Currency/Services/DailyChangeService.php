<?php

namespace App\Currency\Services;

use App\Currency\Contracts\DailyChangeProviderInterface;
use App\Currency\Enums\Currency;
use App\Currency\Enums\CurrencyType;
use App\Currency\Enums\ProviderCapability;
use App\Currency\Exceptions\ProviderException;
use App\Currency\Exceptions\UnsupportedCurrencyPairException;

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
                $configured = $this->selections->configured($capability);
                $key = $this->cacheKey($currency, $configured?->id ?? 'automatic');
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
            $providers = $configured === null ? $this->providerList() : $this->selectedProvider($configured->adapterFor($capability));

            foreach ($providers as $provider) {
                if ($configured === null && ! $this->selections->isAvailableForAutomaticSelection($provider::class)) {
                    continue;
                }

                $supported = array_values(array_filter($pending, static fn (Currency $currency): bool => $currency->type === $type && $provider->supports($currency)));
                if ($supported === []) {
                    continue;
                }

                try {
                    $fresh = $provider->dailyChanges($supported);
                } catch (ProviderException|UnsupportedCurrencyPairException) {
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
                        $selectionId = $configured?->id ?? 'automatic';
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
        return "daily-change:v3:{$selectionId}:{$currency->type->value}:{$currency->code}";
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

    /** @return list<DailyChangeProviderInterface> */
    private function selectedProvider(?string $adapter): array
    {
        $providers = array_values(array_filter($this->providerList(), static fn (DailyChangeProviderInterface $provider): bool => $provider::class === $adapter));
        if ($adapter === null || $providers === []) {
            throw new ProviderException('The selected daily-change provider is not available in this application build.');
        }

        return $providers;
    }

    private function ttl(Currency $currency): \DateTimeInterface
    {
        return $currency->type->value === 'crypto' ? now()->addMinutes(15) : now()->addDay();
    }
}
