<?php

namespace Tests\Feature\Currency;

use App\Currency\Contracts\CurrencyCatalogProviderInterface;
use App\Currency\DTO\CurrencyDefinition;
use App\Currency\Enums\Currency;
use App\Currency\Enums\CurrencyType;
use App\Currency\Enums\ProviderCapability;
use App\Currency\Providers\CoinGeckoDailyChangeProvider;
use App\Currency\Providers\KrakenAssetMapper;
use App\Currency\Providers\KrakenMarketDataProvider;
use App\Currency\Services\CurrencyCache;
use App\Currency\Services\CurrencyCatalog;
use App\Currency\Services\DailyChangeService;
use App\Currency\Services\ExternalApiClientFactory;
use App\Currency\Services\MarketChartService;
use App\Currency\Services\ProviderSelectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProviderCapabilitySelectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_selected_catalog_controls_list_while_other_catalogs_only_enrich_its_entries(): void
    {
        config(['currency.coingecko.api_key' => 'test-demo-key']);
        Cache::store('array')->flush();
        Http::preventStrayRequests();
        Http::fake([
            'https://api.kraken.com/0/public/AssetPairs*' => Http::response([
                'error' => [],
                'result' => ['XBTUSD' => ['base' => 'XXBT', 'quote' => 'ZUSD', 'altname' => 'XBTUSD']],
            ]),
            'https://api.nbrb.by/exrates/rates*' => Http::response([]),
            'https://api.coingecko.com/api/v3/coins/markets*' => Http::response([
                ['id' => 'bitcoin', 'symbol' => 'btc', 'name' => 'Bitcoin'],
                ['id' => 'ethereum', 'symbol' => 'eth', 'name' => 'Ethereum'],
            ]),
        ]);
        app(ProviderSelectionService::class)->select(ProviderCapability::CATALOG, 'kraken');

        $catalog = app(CurrencyCatalog::class)->all();
        $bitcoin = collect($catalog)->firstWhere('code', 'BTC');

        $this->assertNotNull($bitcoin);
        $this->assertSame('XBTUSD', $bitcoin->providerSymbol);
        $this->assertSame('bitcoin', $bitcoin->coinGeckoId);
        $this->assertNotContains('ETH', array_map(static fn ($currency): string => $currency->code, $catalog));
        Http::assertSentCount(3);
    }

    public function test_selected_crypto_daily_change_provider_is_used_without_fallback(): void
    {
        Cache::store('array')->flush();
        Http::preventStrayRequests();
        Http::fake([
            'https://api.kraken.com/0/public/Ticker*' => Http::response([
                'error' => [],
                'result' => ['XBT/USD' => ['c' => ['110'], 'o' => '100']],
            ]),
        ]);
        app(ProviderSelectionService::class)->select(ProviderCapability::CRYPTO_DAILY_CHANGES, 'kraken');
        $catalog = new CurrencyCatalog([
            new class implements CurrencyCatalogProviderInterface
            {
                public function currencies(): array
                {
                    return [new CurrencyDefinition('BTC', CurrencyType::CRYPTO, 'XBTUSD')];
                }
            },
        ], app(CurrencyCache::class), app(ProviderSelectionService::class));
        $service = new DailyChangeService(
            $catalog,
            app(CurrencyCache::class),
            [app(CoinGeckoDailyChangeProvider::class), new KrakenMarketDataProvider(app(KrakenAssetMapper::class), app(ExternalApiClientFactory::class))],
            app(ProviderSelectionService::class),
        );

        $changes = $service->forCurrencies(['BTC']);

        $this->assertSame(10.0, round($changes['BTC'], 1));
        Http::assertSentCount(1);
    }

    public function test_selected_market_data_provider_is_used_for_crypto_charts(): void
    {
        Cache::store('array')->flush();
        Http::preventStrayRequests();
        Http::fake([
            'https://api.kraken.com/0/public/OHLC*' => Http::response([
                'error' => [],
                'result' => ['XBT/USD' => [
                    [1_700_000_000, '100', '110', '90', '105'],
                    [1_700_003_600, '105', '115', '100', '110'],
                ]],
            ]),
            'https://api.kraken.com/0/public/Ticker*' => Http::response([
                'error' => [],
                'result' => ['XBT/USD' => ['c' => ['110'], 'o' => '100', 'h' => ['0', '115'], 'l' => ['0', '90'], 'v' => ['0', '1'], 't' => ['0', 10]]],
            ]),
            'https://api.kraken.com/0/public/Depth*' => Http::response([
                'error' => [],
                'result' => ['XBT/USD' => ['bids' => [], 'asks' => []]],
            ]),
        ]);
        app(ProviderSelectionService::class)->select(ProviderCapability::CRYPTO_MARKET_DATA, 'kraken');

        $chart = app(MarketChartService::class)->chart(Currency::crypto('BTC', 'XBTUSD'), 60);

        $this->assertSame('Kraken', $chart['source']);
        $this->assertCount(1, $chart['candles']);
        Http::assertSentCount(3);
    }
}
