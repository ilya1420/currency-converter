<?php

namespace Tests\Unit\Currency;

use App\Currency\Enums\Currency;
use App\Currency\Exceptions\ProviderException;
use App\Currency\Providers\KrakenAssetMapper;
use App\Currency\Providers\KrakenMarketDataProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KrakenMarketDataProviderTest extends TestCase
{
    public function test_it_supports_only_crypto_with_a_kraken_usd_pair(): void
    {
        $provider = new KrakenMarketDataProvider(new KrakenAssetMapper);

        $this->assertTrue($provider->supports(Currency::crypto('ZEC', 'ZECUSD')));
        $this->assertFalse($provider->supports(Currency::crypto('ZEC')));
        $this->assertFalse($provider->supports(Currency::fiat('USD')));
    }

    public function test_it_reads_daily_change_from_kraken_internal_pair_symbols(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.kraken.com/0/public/Ticker*' => Http::response([
                'error' => [],
                'result' => ['XZECZUSD' => ['c' => ['110'], 'o' => '100']],
            ]),
        ]);

        $provider = new KrakenMarketDataProvider(new KrakenAssetMapper);
        $changes = $provider->dailyChanges([Currency::crypto('ZEC', 'ZECUSD')]);

        $this->assertSame(10.0, round($changes['ZEC'], 1));
    }

    public function test_it_returns_normalized_chart_data_from_three_kraken_endpoints(): void
    {
        Http::fake([
            'https://api.kraken.com/0/public/OHLC*' => Http::response([
                'error' => [],
                'result' => ['XBT/USD' => [
                    [1_700_000_000, '65000', '65500', '64500', '65200'],
                    [1_700_003_600, '65200', '65600', '65000', '65400'],
                ]],
            ]),
            'https://api.kraken.com/0/public/Ticker*' => Http::response([
                'error' => [],
                'result' => ['XBT/USD' => ['c' => ['65400'], 'h' => ['0', '66000'], 'l' => ['0', '64000'], 'v' => ['0', '12'], 't' => ['0', 99]]],
            ]),
            'https://api.kraken.com/0/public/Depth*' => Http::response([
                'error' => [],
                'result' => ['XBT/USD' => ['bids' => [['65390', '1']], 'asks' => [['65410', '2']]]],
            ]),
        ]);

        $provider = new KrakenMarketDataProvider(new KrakenAssetMapper);
        $chart = $provider->chart(Currency::crypto('BTC', 'XBTUSD'), 60);

        $this->assertCount(1, $chart['candles']);
        $this->assertSame('65400', $chart['ticker']['last']);
        $this->assertSame([['65390', '1']], $chart['depth']['bids']);
        Http::assertSentCount(3);
    }

    public function test_it_converts_chart_connection_failures_to_provider_exceptions(): void
    {
        Http::preventStrayRequests();
        Http::fake(static fn (): never => throw new ConnectionException('offline'));

        $provider = new KrakenMarketDataProvider(new KrakenAssetMapper);

        $this->expectException(ProviderException::class);

        $provider->chart(Currency::crypto('BTC', 'XBTUSD'), 60);
    }
}
