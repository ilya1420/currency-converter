<?php

namespace App\Providers;

use App\Currency\Providers\KrakenAssetMapper;
use App\Currency\Repositories\ExchangeRateRepository;
use App\Currency\Repositories\ProviderSelectionRepository;
use App\Currency\Services\CurrencyCache;
use App\Currency\Services\CurrencyCatalog;
use App\Currency\Services\DailyChangeService;
use App\Currency\Services\ExternalApiClientFactory;
use App\Currency\Services\MarketChartService;
use App\Currency\Services\ProviderRegistry;
use App\Currency\Services\ProviderSelectionService;
use App\Currency\Services\RateService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ExchangeRateRepository::class);
        $this->app->singleton(ProviderSelectionRepository::class);
        $this->app->singleton(ProviderRegistry::class, fn (): ProviderRegistry => new ProviderRegistry(
            config('currency.providers.registry', []),
            config('currency.providers.priority', []),
        ));
        $this->app->singleton(ProviderSelectionService::class);
        $this->app->scoped(CurrencyCache::class);
        $this->app->singleton(ExternalApiClientFactory::class);
        $this->app->singleton(KrakenAssetMapper::class);
        $catalogProviders = config('currency.providers.catalog', []);
        $rateProviders = config('currency.providers.rates', []);
        $dailyChangeProviders = config('currency.providers.daily_changes', []);
        $marketProviders = config('currency.providers.market', []);
        foreach (array_unique([...$catalogProviders, ...$rateProviders, ...$dailyChangeProviders, ...$marketProviders]) as $provider) {
            $this->app->singleton($provider);
        }
        $this->app->tag($catalogProviders, 'currency.catalog-providers');
        $this->app->tag($rateProviders, 'currency.rate-providers');
        $this->app->tag($dailyChangeProviders, 'currency.daily-change-providers');
        $this->app->tag($marketProviders, 'currency.market-data-providers');
        $this->app->scoped(CurrencyCatalog::class, fn (): CurrencyCatalog => new CurrencyCatalog(
            $this->app->tagged('currency.catalog-providers'),
            $this->app->make(CurrencyCache::class),
        ));
        $this->app->scoped(RateService::class, fn (): RateService => new RateService(
            $this->app->make(ExchangeRateRepository::class),
            $this->app->tagged('currency.rate-providers'),
            $this->app->make(ProviderSelectionService::class),
        ));
        $this->app->scoped(DailyChangeService::class, fn (): DailyChangeService => new DailyChangeService(
            $this->app->make(CurrencyCatalog::class),
            $this->app->make(CurrencyCache::class),
            $this->app->tagged('currency.daily-change-providers'),
        ));
        $this->app->scoped(MarketChartService::class, fn (): MarketChartService => new MarketChartService(
            $this->app->tagged('currency.market-data-providers'),
            $this->app->make(CurrencyCache::class),
        ));
    }
}
