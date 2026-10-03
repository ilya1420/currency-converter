<?php

namespace Tests\Unit\Currency;

use App\Currency\Enums\Currency;
use App\Currency\Enums\CurrencyType;
use App\Currency\Exceptions\ProviderRateLimitException;
use App\Currency\Exceptions\ProviderResponseException;
use App\Currency\Providers\CoinGeckoRateProvider;
use App\Currency\Services\CurrencyCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
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

    #[DataProvider('invalidPrices')]
    public function test_it_rejects_invalid_prices_without_caching_them(mixed $price): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.coingecko.com/api/v3/simple/price*' => Http::response(['bitcoin' => ['usd' => $price]])]);
        $provider = app(CoinGeckoRateProvider::class);

        $this->expectException(ProviderResponseException::class);
        try {
            $provider->getRate(new Currency('BTC', CurrencyType::CRYPTO, null, null, 'bitcoin'), Currency::fiat('USD'));
        } finally {
            $this->assertNull(app(CurrencyCache::class)->get('coingecko:price:v1:bitcoin'));
            Http::assertSentCount(1);
        }
    }

    public function test_it_recovers_after_an_invalid_response_without_forced_refresh(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.coingecko.com/api/v3/simple/price*' => Http::sequence()
            ->push(['bitcoin' => ['usd' => 0]])
            ->push(['bitcoin' => ['usd' => '123.123456789012345678']])]);
        $provider = app(CoinGeckoRateProvider::class);
        $currency = new Currency('BTC', CurrencyType::CRYPTO, null, null, 'bitcoin');
        try {
            $provider->getRate($currency, Currency::fiat('USD'));
            $this->fail('An invalid price must fail.');
        } catch (ProviderResponseException) {
        }

        $rate = $provider->getRate($currency, Currency::fiat('USD'));

        $this->assertSame('123.123456789012345678', $rate->rate);
        Http::assertSentCount(2);
    }

    /** @return array<string, array{mixed}> */
    public static function invalidPrices(): array
    {
        return [
            'array' => [[123]], 'boolean' => [true], 'null' => [null],
            'zero' => [0], 'negative' => [-1], 'garbage' => ['oops'],
            'fraction' => ['1/2'], 'excessive exponent' => ['1e1024'],
        ];
    }

    public function test_it_discards_a_corrupt_legacy_cached_price(): void
    {
        $cache = app(CurrencyCache::class);
        $cache->put('coingecko:price:v1:bitcoin', [
            'payload' => ['bitcoin' => ['usd' => -1]], 'fetchedAt' => now()->toIso8601String(),
        ], 120);
        Http::preventStrayRequests();
        Http::fake(['https://api.coingecko.com/api/v3/simple/price*' => Http::response(['bitcoin' => ['usd' => 123]])]);
        $provider = app(CoinGeckoRateProvider::class);
        $currency = new Currency('BTC', CurrencyType::CRYPTO, null, null, 'bitcoin');
        try {
            $provider->getRate($currency, Currency::fiat('USD'));
            $this->fail('An invalid cached price must fail.');
        } catch (ProviderResponseException) {
        }

        $rate = $provider->getRate($currency, Currency::fiat('USD'));

        $this->assertSame('123', $rate->rate);
        Http::assertSentCount(1);
    }

    #[DataProvider('malformedCoinRecords')]
    public function test_it_rejects_non_object_coin_records(mixed $record): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.coingecko.com/api/v3/simple/price*' => Http::response(['bitcoin' => $record])]);

        $this->expectException(ProviderResponseException::class);
        try {
            app(CoinGeckoRateProvider::class)->getRate(new Currency('BTC', CurrencyType::CRYPTO, null, null, 'bitcoin'), Currency::fiat('USD'));
        } finally {
            $this->assertNull(app(CurrencyCache::class)->get('coingecko:price:v1:bitcoin'));
            Http::assertSentCount(1);
        }
    }

    /** @return array<string, array{mixed}> */
    public static function malformedCoinRecords(): array
    {
        return ['string' => ['123'], 'number' => [123], 'boolean' => [true], 'null' => [null]];
    }
}
