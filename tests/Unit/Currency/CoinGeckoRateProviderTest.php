<?php

namespace Tests\Unit\Currency;

use App\Currency\Enums\Currency;
use App\Currency\Enums\CurrencyType;
use App\Currency\Exceptions\ProviderRateLimitException;
use App\Currency\Providers\CoinGeckoRateProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class CoinGeckoRateProviderTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_caches_a_price_response(): void
    {
        Cache::store('file')->forget('coingecko:price:v1:bitcoin');
        Http::fake(['https://api.coingecko.com/api/v3/simple/price*' => Http::response(['bitcoin' => ['usd' => 100]], 200)]);
        $provider = app(CoinGeckoRateProvider::class);
        $currency = new Currency('BTC', CurrencyType::CRYPTO, null, null, 'bitcoin');

        $provider->getRate($currency, Currency::fiat('USD'));
        $provider->getRate($currency, Currency::fiat('USD'));

        Http::assertSentCount(1);
    }

    public function test_it_exposes_coingecko_rate_limit(): void
    {
        Cache::store('file')->forget('coingecko:price:v1:ethereum');
        Http::fake(['https://api.coingecko.com/api/v3/simple/price*' => Http::response([], 429, ['Retry-After' => '20'])]);
        $currency = new Currency('ETH', CurrencyType::CRYPTO, null, null, 'ethereum');

        $this->expectException(ProviderRateLimitException::class);
        try {
            app(CoinGeckoRateProvider::class)->getRate($currency, Currency::fiat('USD'));
        } catch (ProviderRateLimitException $exception) {
            $this->assertSame(20, $exception->retryAfter);
            throw $exception;
        }
    }
}
