<?php

namespace App\Currency\Services;

use App\Currency\Contracts\MarketDataProviderInterface;
use App\Currency\Enums\Currency;
use App\Currency\Enums\CurrencyType;
use App\Currency\Enums\ProviderCapability;
use App\Currency\Exceptions\ProviderException;
use App\Currency\Exceptions\RateUnavailableException;
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
        $providers = $this->selections->candidates($capability, $this->providers);

        return $this->cache->remember(
            'market-chart:v4:'.$this->selections->cacheIdentity($capability).":{$currency->type->value}:{$currency->code}:{$interval}",
            $ttl,
            fn (): array => $this->loadFromProvider($currency, $interval, $providers),
        );
    }

    /** @param list<MarketDataProviderInterface> $providers @return array<string, mixed> */
    private function loadFromProvider(Currency $currency, int $interval, array $providers): array
    {
        foreach ($providers as $provider) {
            if (! $provider->supports($currency)) {
                continue;
            }

            try {
                return $provider->chart($currency, $interval) + ['source' => $provider->source()];
            } catch (ProviderException $exception) {
                throw new RateUnavailableException(
                    'Market data is unavailable.',
                    $this->selections->definitionForAdapter($provider::class)?->id,
                    previous: $exception,
                );
            }
        }
        throw new UnsupportedCurrencyPairException('No market data provider supports this asset.');
    }
}
