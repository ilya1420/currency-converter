<?php

namespace Tests\Unit\Currency;

use App\Currency\Enums\Currency;
use App\Currency\Enums\RateSource;
use App\Currency\Exceptions\ProviderException;
use App\Currency\Providers\KrakenAssetMapper;
use App\Currency\Providers\KrakenRateProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class KrakenRateProviderTest extends TestCase
{
    public function test_it_maps_btc_symbol_only_inside_kraken_provider(): void
    {
        Http::fake(['https://api.kraken.com/0/public/Ticker?pair=XBTUSD' => Http::response($this->fixture('kraken-btc-usd.json'))]);
        $provider = new KrakenRateProvider(new KrakenAssetMapper);

        $rate = $provider->getRate(Currency::BTC, Currency::USD);

        $this->assertSame('65000.12345678', $rate->rate);
        $this->assertSame(RateSource::KRAKEN, $rate->source);
    }

    public function test_it_maps_kraken_errors(): void
    {
        Http::fake(['https://api.kraken.com/0/public/Ticker?pair=XBTUSD' => Http::response($this->fixture('kraken-error.json'))]);
        $this->expectException(ProviderException::class);
        (new KrakenRateProvider(new KrakenAssetMapper))->getRate(Currency::BTC, Currency::USD);
    }

    public function test_it_returns_eth_usd(): void
    {
        Http::fake(['https://api.kraken.com/0/public/Ticker?pair=ETHUSD' => Http::response($this->fixture('kraken-eth-usd.json'))]);

        $rate = (new KrakenRateProvider(new KrakenAssetMapper))->getRate(Currency::ETH, Currency::USD);

        $this->assertSame('3500.12345678', $rate->rate);
    }

    public function test_it_supports_confirmed_usd_pairs(): void
    {
        $provider = new KrakenRateProvider(new KrakenAssetMapper);

        $this->assertTrue($provider->supports(Currency::USDT, Currency::USD));
        $this->assertTrue($provider->supports(Currency::SOL, Currency::USD));
        $this->assertTrue($provider->supports(Currency::XRP, Currency::USD));
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path("tests/Fixtures/kraken/{$name}"));
    }
}
