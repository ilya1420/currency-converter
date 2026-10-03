<?php

namespace Tests\Unit\Currency;

use App\Currency\Enums\Currency;
use App\Currency\Enums\RateSource;
use App\Currency\Exceptions\ProviderException;
use App\Currency\Exceptions\ProviderRateLimitException;
use App\Currency\Exceptions\ProviderResponseException;
use App\Currency\Providers\KrakenAssetMapper;
use App\Currency\Providers\KrakenMarketDataProvider;
use App\Currency\Providers\KrakenRateProvider;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class KrakenRateProviderTest extends TestCase
{
    public function test_it_maps_btc_symbol_only_inside_kraken_provider(): void
    {
        Http::fake(['https://api.kraken.com/0/public/Ticker?pair=XBTUSD' => Http::response($this->fixture('kraken-btc-usd.json'))]);
        $provider = new KrakenRateProvider(new KrakenAssetMapper);

        $rate = $provider->getRate(Currency::crypto('BTC', 'XBTUSD'), Currency::fiat('USD'));

        $this->assertSame('65000.12345678', $rate->rate);
        $this->assertSame(RateSource::KRAKEN, $rate->source);
    }

    public function test_it_maps_kraken_errors(): void
    {
        Http::fake(['https://api.kraken.com/0/public/Ticker?pair=XBTUSD' => Http::response($this->fixture('kraken-error.json'))]);
        $this->expectException(ProviderException::class);
        (new KrakenRateProvider(new KrakenAssetMapper))->getRate(Currency::crypto('BTC', 'XBTUSD'), Currency::fiat('USD'));
    }

    public function test_it_returns_eth_usd(): void
    {
        Http::fake(['https://api.kraken.com/0/public/Ticker?pair=ETHUSD' => Http::response($this->fixture('kraken-eth-usd.json'))]);

        $rate = (new KrakenRateProvider(new KrakenAssetMapper))->getRate(Currency::crypto('ETH', 'ETHUSD'), Currency::fiat('USD'));

        $this->assertSame('3500.12345678', $rate->rate);
    }

    public function test_it_exposes_provider_rate_limit(): void
    {
        Http::fake(['https://api.kraken.com/0/public/Ticker?pair=XBTUSD' => Http::response([], 429, ['Retry-After' => '12'])]);

        try {
            (new KrakenRateProvider(new KrakenAssetMapper))->getRate(Currency::crypto('BTC', 'XBTUSD'), Currency::fiat('USD'));
            $this->fail('Expected a rate limit exception.');
        } catch (ProviderRateLimitException $exception) {
            $this->assertSame(12, $exception->retryAfter);
        }
    }

    public function test_it_supports_confirmed_usd_pairs(): void
    {
        $provider = new KrakenRateProvider(new KrakenAssetMapper);

        $this->assertTrue($provider->supports(Currency::crypto('USDT', 'USDTUSD'), Currency::fiat('USD')));
        $this->assertTrue($provider->supports(Currency::crypto('SOL', 'SOLUSD'), Currency::fiat('USD')));
        $this->assertTrue($provider->supports(Currency::crypto('XRP', 'XRPUSD'), Currency::fiat('USD')));
    }

    public function test_it_loads_daily_changes_for_multiple_crypto_assets_in_one_request(): void
    {
        Http::fake([
            'https://api.kraken.com/0/public/Ticker*' => Http::response([
                'error' => [],
                'result' => [
                    'BTC/USD' => ['c' => ['110'], 'o' => '100'],
                    'ETH/USD' => ['c' => ['180'], 'o' => '200'],
                ],
            ]),
        ]);

        $changes = (new KrakenMarketDataProvider(new KrakenAssetMapper))->dailyChanges([
            Currency::crypto('BTC', 'XBTUSD'),
            Currency::crypto('ETH', 'ETHUSD'),
            Currency::crypto('BNB'),
        ]);

        $this->assertEqualsWithDelta(10.0, $changes['BTC'], 0.000001);
        $this->assertEqualsWithDelta(-10.0, $changes['ETH'], 0.000001);
        $this->assertNull($changes['BNB']);
        Http::assertSentCount(1);
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path("tests/Fixtures/kraken/{$name}"));
    }

    #[DataProvider('invalidClosingPrices')]
    public function test_it_rejects_malformed_closing_prices(mixed $closingPrice): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.kraken.com/0/public/Ticker?pair=XBTUSD' => Http::response([
            'error' => [], 'result' => ['XXBTZUSD' => ['c' => $closingPrice]],
        ])]);

        $this->expectException(ProviderResponseException::class);
        try {
            (new KrakenRateProvider(new KrakenAssetMapper))->getRate(Currency::crypto('BTC', 'XBTUSD'), Currency::fiat('USD'));
        } finally {
            Http::assertSentCount(1);
        }
    }

    /** @return array<string, array{mixed}> */
    public static function invalidClosingPrices(): array
    {
        return [
            'array price' => [[[123]]], 'boolean price' => [[true]], 'null price' => [[null]],
            'string closing field' => ['123'], 'empty closing field' => [[]],
            'zero' => [['0']], 'negative' => [['-1']], 'garbage' => [['oops']],
            'fraction' => [['1/2']], 'excessive exponent' => [['1e1024']],
            'huge exponent' => [['1e1000000000']], 'exponent overflow' => [['1e99999999999999999999']],
        ];
    }

    public function test_it_rejects_a_json_number_that_overflows_to_infinity(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.kraken.com/0/public/Ticker?pair=XBTUSD' => Http::response(
            '{"error":[],"result":{"XXBTZUSD":{"c":[1e309]}}}',
        )]);

        $this->expectException(ProviderResponseException::class);
        try {
            (new KrakenRateProvider(new KrakenAssetMapper))->getRate(Currency::crypto('BTC', 'XBTUSD'), Currency::fiat('USD'));
        } finally {
            Http::assertSentCount(1);
        }
    }

    public function test_it_preserves_small_scientific_prices_as_decimals(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.kraken.com/0/public/Ticker?pair=XBTUSD' => Http::response([
            'error' => [], 'result' => ['XXBTZUSD' => ['c' => ['1.234567890123456789e-8']]],
        ])]);

        $rate = (new KrakenRateProvider(new KrakenAssetMapper))->getRate(Currency::crypto('BTC', 'XBTUSD'), Currency::fiat('USD'));

        $this->assertSame('0.00000001234567890123456789', $rate->rate);
        Http::assertSentCount(1);
    }
}
