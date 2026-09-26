<?php

namespace App\Providers;

use App\Currency\Providers\KrakenAssetMapper;
use App\Currency\Providers\KrakenMarketDataProvider;
use App\Currency\Providers\NbrbMarketDataProvider;
use App\Currency\Providers\KrakenRateProvider;
use App\Currency\Providers\NbrbRateProvider;
use App\Currency\Repositories\ExchangeRateRepository;
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
        $this->app->singleton(NbrbRateProvider::class);
        $this->app->singleton(KrakenAssetMapper::class);
        $this->app->singleton(KrakenMarketDataProvider::class);
        $this->app->singleton(NbrbMarketDataProvider::class);
        $this->app->singleton(KrakenRateProvider::class);
        $this->app->singleton(RateService::class, fn (): RateService => new RateService(
            $this->app->make(ExchangeRateRepository::class),
            [$this->app->make(NbrbRateProvider::class), $this->app->make(KrakenRateProvider::class)],
        ));
    }
}
