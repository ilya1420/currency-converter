<?php

namespace Tests\Unit\Currency;

use App\Currency\Enums\Currency;
use App\Currency\Providers\KrakenAssetMapper;
use App\Currency\Providers\KrakenMarketDataProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KrakenMarketDataProviderTest extends TestCase
{
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
}
