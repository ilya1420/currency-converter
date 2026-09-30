<?php

use App\Currency\Providers\CoinGeckoCurrencyCatalogProvider;
use App\Currency\Providers\CoinGeckoDailyChangeProvider;
use App\Currency\Providers\CoinGeckoRateProvider;
use App\Currency\Providers\KrakenCurrencyCatalogProvider;
use App\Currency\Providers\KrakenMarketDataProvider;
use App\Currency\Providers\KrakenRateProvider;
use App\Currency\Providers\NbrbCurrencyCatalogProvider;
use App\Currency\Providers\NbrbMarketDataProvider;
use App\Currency\Providers\NbrbRateProvider;

$providerDefinitions = [
    'nbrb' => [
        'name' => 'НБРБ',
        'adapters' => [
            'catalog' => NbrbCurrencyCatalogProvider::class,
            'rates' => NbrbRateProvider::class,
            'daily_changes' => NbrbMarketDataProvider::class,
            'market' => NbrbMarketDataProvider::class,
        ],
    ],
    'kraken' => [
        'name' => 'Kraken',
        'adapters' => [
            'catalog' => KrakenCurrencyCatalogProvider::class,
            'rates' => KrakenRateProvider::class,
            'daily_changes' => KrakenMarketDataProvider::class,
            'market' => KrakenMarketDataProvider::class,
        ],
    ],
    'coingecko' => [
        'name' => 'CoinGecko',
        'adapters' => [
            'catalog' => CoinGeckoCurrencyCatalogProvider::class,
            'rates' => CoinGeckoRateProvider::class,
            'daily_changes' => CoinGeckoDailyChangeProvider::class,
        ],
    ],
];

$providerOrder = [
    'catalog' => ['nbrb', 'kraken', 'coingecko'],
    'rates' => ['nbrb', 'kraken', 'coingecko'],
    'daily_changes' => ['coingecko', 'kraken', 'nbrb'],
    'market' => ['kraken', 'nbrb'],
];

$providers = ['registry' => $providerDefinitions, 'priority' => $providerOrder];
foreach ($providerOrder as $capability => $providerIds) {
    $providers[$capability] = array_map(
        static fn (string $providerId): string => $providerDefinitions[$providerId]['adapters'][$capability],
        $providerIds,
    );
}

return [
    'cache' => [
        'store' => env('CURRENCY_CACHE_STORE', 'file'),
    ],
    'providers' => $providers,
    'catalog' => [
        'popular_limit' => (int) env('CURRENCY_POPULAR_LIMIT', 25),
    ],
    'nbrb' => [
        'base_url' => env('NBRB_API_BASE_URL', 'https://api.nbrb.by/exrates'),
        'rate_ttl_seconds' => (int) env('NBRB_RATE_TTL_SECONDS', 21600),
        'max_stale_age_seconds' => (int) env('NBRB_MAX_STALE_AGE_SECONDS', 604800),
    ],
    'kraken' => [
        'base_url' => env('KRAKEN_API_BASE_URL', 'https://api.kraken.com/0/public'),
        'rate_ttl_seconds' => (int) env('KRAKEN_RATE_TTL_SECONDS', 60),
        'max_stale_age_seconds' => (int) env('KRAKEN_MAX_STALE_AGE_SECONDS', 86400),
        'market_data_ttl_seconds' => (int) env('KRAKEN_MARKET_DATA_TTL_SECONDS', 15),
    ],
    'coingecko' => [
        'base_url' => env('COINGECKO_API_BASE_URL', 'https://api.coingecko.com/api/v3'),
        'api_key' => env('COINGECKO_API_KEY'),
        'rate_ttl_seconds' => (int) env('COINGECKO_RATE_TTL_SECONDS', 120),
        'max_stale_age_seconds' => (int) env('COINGECKO_MAX_STALE_AGE_SECONDS', 86400),
    ],
    'http' => [
        'connect_timeout' => 1,
        'timeout' => 2,
        'retry_times' => 1,
        'retry_delay_ms' => 100,
    ],
];
