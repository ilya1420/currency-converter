<?php

namespace App\Currency\Services;

use App\Currency\Contracts\MarketDataProviderInterface;
use App\Currency\Enums\Currency;
use App\Currency\Exceptions\ProviderException;
use App\Currency\Exceptions\UnsupportedCurrencyPairException;

final class MarketChartService
{
    /** @param iterable<MarketDataProviderInterface> $providers */
    public function __construct(private iterable $providers, private CurrencyCache $cache) {}

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

        return $this->cache->remember(
            "market-chart:v1:{$currency->type->value}:{$currency->code}:{$interval}",
            $ttl,
            fn (): array => $this->loadFromProvider($currency, $interval),
        );
    }

    /** @return array<string, mixed> */
    private function loadFromProvider(Currency $currency, int $interval): array
    {
        $lastFailure = null;
        foreach ($this->providers as $provider) {
            if (! $provider->supports($currency)) continue;
            try {
                return $provider->chart($currency, $interval) + ['source' => $provider->source()];
            } catch (ProviderException $exception) {
                $lastFailure = $exception;
            }
        }
        if ($lastFailure) throw $lastFailure;
        throw new UnsupportedCurrencyPairException('No market data provider supports this asset.');
    }
}
