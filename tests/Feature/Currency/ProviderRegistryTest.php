<?php

namespace Tests\Feature\Currency;

use App\Currency\Enums\ProviderCapability;
use App\Currency\Providers\CoinGeckoDailyChangeProvider;
use App\Currency\Providers\KrakenMarketDataProvider;
use App\Currency\Providers\NbrbMarketDataProvider;
use App\Currency\Providers\NbrbRateProvider;
use App\Currency\Services\ProviderRegistry;
use Tests\TestCase;

class ProviderRegistryTest extends TestCase
{
    public function test_it_lists_builtin_providers_in_configured_capability_priority(): void
    {
        $registry = app(ProviderRegistry::class);

        $this->assertSame(
            ['nbrb'],
            array_map(static fn ($provider): string => $provider->id, $registry->forCapability(ProviderCapability::FIAT_RATES)),
        );
        $this->assertSame(
            ['kraken', 'coingecko'],
            array_map(static fn ($provider): string => $provider->id, $registry->forCapability(ProviderCapability::CRYPTO_RATES)),
        );
        $this->assertSame(
            ['nbrb'],
            array_map(static fn ($provider): string => $provider->id, $registry->forCapability(ProviderCapability::FIAT_DAILY_CHANGES)),
        );
        $this->assertSame(
            ['kraken', 'coingecko'],
            array_map(static fn ($provider): string => $provider->id, $registry->forCapability(ProviderCapability::CRYPTO_DAILY_CHANGES)),
        );
        $this->assertSame(
            ['nbrb'],
            array_map(static fn ($provider): string => $provider->id, $registry->forCapability(ProviderCapability::FIAT_MARKET_DATA)),
        );
        $this->assertSame(
            ['kraken'],
            array_map(static fn ($provider): string => $provider->id, $registry->forCapability(ProviderCapability::CRYPTO_MARKET_DATA)),
        );
    }

    public function test_provider_definition_resolves_capability_adapter(): void
    {
        $provider = app(ProviderRegistry::class)->find('nbrb');

        $this->assertNotNull($provider);
        $this->assertSame(NbrbRateProvider::class, $provider->adapterFor(ProviderCapability::FIAT_RATES));
        $this->assertSame(CoinGeckoDailyChangeProvider::class, app(ProviderRegistry::class)
            ->find('coingecko')
            ->adapterFor(ProviderCapability::CRYPTO_DAILY_CHANGES));
        $this->assertSame(KrakenMarketDataProvider::class, app(ProviderRegistry::class)
            ->find('kraken')
            ->adapterFor(ProviderCapability::CRYPTO_MARKET_DATA));
        $this->assertSame(NbrbMarketDataProvider::class, $provider->adapterFor(ProviderCapability::FIAT_MARKET_DATA));
        $this->assertSame(
            ['catalog', 'crypto_rates', 'crypto_daily_changes'],
            array_map(static fn (ProviderCapability $capability): string => $capability->value, app(ProviderRegistry::class)
                ->find('coingecko')
                ->capabilities()),
        );
    }
}
