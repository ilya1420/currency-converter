<?php

namespace App\Currency\Services;

use App\Currency\Contracts\MarketDataProviderInterface;
use App\Currency\Enums\Currency;
use App\Currency\Enums\CurrencyType;
use App\Currency\Enums\ProviderCapability;
use App\Currency\Exceptions\ProviderException;
use App\Currency\Exceptions\UnsupportedCurrencyPairException;

final class MarketChartService
{
    /** @param iterable<MarketDataProviderInterface> $providers */
    public function __construct(
        private iterable $providers,
        private CurrencyCache $cache,
        private ProviderSelectionService $selections,
    ) {}

    /** @return array<string, mixed> */
    public function chart(Currency $currency, int $interval): array
    {
        $ttl = $currency->type->value === 'fiat'
            ? now()->addHour()
            : now()->addSeconds(match ($interval) {
                60 => 30,
                240 => 120,
                default => 300,
            });

        $capability = $currency->type === CurrencyType::CRYPTO
            ? ProviderCapability::CRYPTO_MARKET_DATA
            : ProviderCapability::FIAT_MARKET_DATA;
        $configured = $this->selections->configured($capability);

        return $this->cache->remember(
            'market-chart:v3:'.($configured?->id ?? 'automatic').":{$currency->type->value}:{$currency->code}:{$interval}",
            $ttl,
            fn (): array => $this->loadFromProvider($currency, $interval, $configured?->adapterFor($capability)),
        );
    }

    /** @return array<string, mixed> */
    private function loadFromProvider(Currency $currency, int $interval, ?string $selectedAdapter): array
    {
        $providers = is_array($this->providers) ? array_values($this->providers) : iterator_to_array($this->providers, false);
        if ($selectedAdapter !== null) {
            $providers = array_values(array_filter($providers, static fn (MarketDataProviderInterface $provider): bool => $provider::class === $selectedAdapter));
            if ($providers === []) {
                throw new ProviderException('The selected market-data provider is not available in this application build.');
            }
        }

        foreach ($providers as $provider) {
            if (! $provider->supports($currency)) {
                continue;
            }

            return $provider->chart($currency, $interval) + ['source' => $provider->source()];
        }
        throw new UnsupportedCurrencyPairException('No market data provider supports this asset.');
    }
}
