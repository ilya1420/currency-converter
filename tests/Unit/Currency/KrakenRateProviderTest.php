<?php

namespace Tests\Unit\Currency;

use App\Currency\Enums\Currency;
use App\Currency\Enums\RateSource;
use App\Currency\Exceptions\ProviderException;
use App\Currency\Exceptions\ProviderRateLimitException;
use App\Currency\Providers\KrakenAssetMapper;
use App\Currency\Providers\KrakenMarketDataProvider;
use App\Currency\Providers\KrakenRateProvider;
use Illuminate\Support\Facades\Http;
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
}
