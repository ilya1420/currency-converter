<?php

namespace Tests\Unit\Currency;

use App\Currency\Enums\Currency;
use App\Currency\Exceptions\ProviderException;
use App\Currency\Providers\KrakenAssetMapper;
use App\Currency\Providers\KrakenMarketDataProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_it_discards_malformed_ohlc_values_before_returning_chart_data(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.kraken.com/0/public/OHLC*' => Http::response([
                'error' => [],
                'result' => ['XBT/USD' => [
                    [1_700_000_000, '10', '12', '9', '11'],
                    [1_700_003_600, '10" onload="alert(1)', '12', '9', '11'],
                    [1_700_007_200, '1e999', '1e999', '1', '2'],
                    [1_700_010_800, '10', '9', '8', '11'],
                    [1_700_014_400, '11', '13', '10', '12'],
                ]],
            ]),
            'https://api.kraken.com/0/public/Ticker*' => Http::response([
                'error' => [], 'result' => ['XBT/USD' => ['c' => ['12'], 'o' => '11']],
            ]),
            'https://api.kraken.com/0/public/Depth*' => Http::response([
                'error' => [], 'result' => ['XBT/USD' => ['bids' => [], 'asks' => []]],
            ]),
        ]);

        $chart = (new KrakenMarketDataProvider(new KrakenAssetMapper))
            ->chart(Currency::crypto('BTC', 'XBTUSD'), 60);

        $this->assertSame([
            ['time' => 1_700_000_000, 'open' => '10', 'high' => '12', 'low' => '9', 'close' => '11'],
        ], $chart['candles']);
    }

    /** @param list<mixed> $ohlc @param list<array{time: int, open: string, high: string, low: string, close: string}> $expected */
    #[DataProvider('closedCandleCases')]
    public function test_it_keeps_valid_closed_candles_before_a_malformed_current_candle(array $ohlc, array $expected): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.kraken.com/0/public/OHLC*' => Http::response([
                'error' => [], 'result' => ['XBT/USD' => $ohlc],
            ]),
            'https://api.kraken.com/0/public/Ticker*' => Http::response([
                'error' => [], 'result' => ['XBT/USD' => ['c' => ['12'], 'o' => '11']],
            ]),
            'https://api.kraken.com/0/public/Depth*' => Http::response([
                'error' => [], 'result' => ['XBT/USD' => ['bids' => [], 'asks' => []]],
            ]),
        ]);

        $chart = (new KrakenMarketDataProvider(new KrakenAssetMapper))
            ->chart(Currency::crypto('BTC', 'XBTUSD'), 60);

        $this->assertSame($expected, $chart['candles']);
    }

    /** @return array<string, array{list<mixed>, list<array{time: int, open: string, high: string, low: string, close: string}>}> */
    public static function closedCandleCases(): array
    {
        $closed = [1_700_000_000, '10', '12', '9', '11'];
        $expected = [['time' => 1_700_000_000, 'open' => '10', 'high' => '12', 'low' => '9', 'close' => '11']];

        return [
            'malformed current prices' => [[$closed, [1_700_003_600, 'invalid', '12', '9', '11']], $expected],
            'malformed current timestamp' => [[$closed, ['invalid', '11', '13', '10', '12']], $expected],
            'empty current entry' => [[$closed, []], $expected],
            'only current candle' => [[$closed], []],
            'empty response' => [[], []],
            'unsafe closed timestamp' => [[[9_007_199_254_740_992, '10', '12', '9', '11'], $closed], []],
        ];
    }
}
