<?php

use App\Currency\Enums\ProviderCapability;
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
            'fiat_rates' => NbrbRateProvider::class,
            'fiat_daily_changes' => NbrbMarketDataProvider::class,
            'fiat_market_data' => NbrbMarketDataProvider::class,
        ],
    ],
    'kraken' => [
        'name' => 'Kraken',
        'adapters' => [
            'catalog' => KrakenCurrencyCatalogProvider::class,
            'crypto_rates' => KrakenRateProvider::class,
            'crypto_daily_changes' => KrakenMarketDataProvider::class,
            'crypto_market_data' => KrakenMarketDataProvider::class,
        ],
    ],
    'coingecko' => [
        'name' => 'CoinGecko',
        'adapters' => [
            'catalog' => CoinGeckoCurrencyCatalogProvider::class,
            'crypto_rates' => CoinGeckoRateProvider::class,
            'crypto_daily_changes' => CoinGeckoDailyChangeProvider::class,
        ],
    ],
];

$providerOrder = [
    'catalog' => ['nbrb', 'kraken', 'coingecko'],
    'fiat_rates' => ['nbrb'],
    'crypto_rates' => ['kraken', 'coingecko'],
    'fiat_daily_changes' => ['nbrb'],
    'crypto_daily_changes' => ['kraken', 'coingecko'],
    'fiat_market_data' => ['nbrb'],
    'crypto_market_data' => ['kraken'],
];
$rateProviderOrder = ['nbrb', 'kraken', 'coingecko'];

$providers = ['registry' => $providerDefinitions, 'priority' => $providerOrder];
foreach ([
    'catalog' => ['catalog'],
    'daily_changes' => ['fiat_daily_changes', 'crypto_daily_changes'],
    'market' => ['fiat_market_data', 'crypto_market_data'],
] as $runtimeCapability => $capabilities) {
    $providers[$runtimeCapability] = [];
    foreach ($capabilities as $capability) {
        foreach ($providerOrder[$capability] as $providerId) {
            if (isset($providerDefinitions[$providerId]['adapters'][$capability])) {
                $providers[$runtimeCapability][] = $providerDefinitions[$providerId]['adapters'][$capability];
            }
        }
    }
    $providers[$runtimeCapability] = array_values(array_unique($providers[$runtimeCapability]));
}
$providers['rates'] = [];
foreach ($rateProviderOrder as $providerId) {
    foreach ([ProviderCapability::FIAT_RATES->value, ProviderCapability::CRYPTO_RATES->value] as $capability) {
        if (isset($providerDefinitions[$providerId]['adapters'][$capability])) {
            $providers['rates'][] = $providerDefinitions[$providerId]['adapters'][$capability];
        }
    }
}
$providers['rates'] = array_values(array_unique($providers['rates']));

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
        'fallback_is_stale' => true,
        'http' => [
            'retry_attempts' => 2,
        ],
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
        'retry_delay_ms' => 100,
    ],
];
