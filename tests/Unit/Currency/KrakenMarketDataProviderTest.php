<?php

namespace Tests\Unit\Currency;

use App\Currency\Enums\Currency;
use App\Currency\Providers\KrakenAssetMapper;
use App\Currency\Providers\KrakenMarketDataProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KrakenMarketDataProviderTest extends TestCase
{
    public function test_it_loads_a_market_snapshot_with_three_requests(): void
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
        $snapshot = $provider->snapshot(Currency::BTC, 60);
        $provider->snapshot(Currency::BTC, 60);

        $this->assertCount(1, $snapshot['candles']);
        $this->assertSame('65400', $snapshot['ticker']['last']);
        $this->assertArrayNotHasKey('recentTrades', $snapshot);
        Http::assertSentCount(3);
    }
}
