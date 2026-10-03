<?php

namespace Tests\Feature\Currency;

use App\Currency\Enums\Currency;
use App\Currency\Enums\CurrencyType;
use App\Currency\Exceptions\ProviderRateLimitException;
use App\Currency\Exceptions\ProviderResponseException;
use App\Currency\Exceptions\ProviderTimeoutException;
use App\Currency\Services\ExternalApiClientFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ExternalApiClientFactoryTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('retryHeaders')]
    public function test_it_normalizes_rate_limits_and_retry_after(?string $header, ?int $expected): void
    {
        $this->travelTo('2026-10-03 12:00:00 UTC');
        Http::preventStrayRequests();
        Http::fake(['https://api.kraken.com/0/public/Ticker' => Http::response('', 429, $header === null ? [] : ['Retry-After' => $header])]);

        try {
            app(ExternalApiClientFactory::class)->get('kraken', 'Ticker');
            $this->fail('Expected a rate-limit exception.');
        } catch (ProviderRateLimitException $exception) {
            $this->assertSame($expected, $exception->retryAfter);
            Http::assertSentCount(1);
        }
    }

    /** @return array<string, array{?string, ?int}> */
    public static function retryHeaders(): array
    {
        return [
            'seconds' => ['45', 45], 'HTTP date' => ['Sat, 03 Oct 2026 12:00:45 GMT', 45],
            'missing' => [null, null], 'malformed' => ['tomorrow', null], 'negative' => ['-1', null],
            'integer overflow' => ['99999999999999999999', null], 'timestamp overflow' => [(string) PHP_INT_MAX, null],
        ];
    }

    public function test_it_normalizes_connection_failures(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.kraken.com/0/public/Ticker' => Http::failedConnection()]);
        $this->expectException(ProviderTimeoutException::class);

        app(ExternalApiClientFactory::class)->get('kraken', 'Ticker');
    }

    public function test_it_normalizes_failed_http_responses(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.kraken.com/0/public/Ticker' => Http::response('<h1>unavailable</h1>', 503)]);
        $this->expectException(ProviderResponseException::class);

        app(ExternalApiClientFactory::class)->get('kraken', 'Ticker');
    }

    #[DataProvider('adapterFailures')]
    public function test_adapters_preserve_normalized_http_failures(string $adapter, string $operation, int $status, string $exceptionClass): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.nbrb.by/exrates/*' => Http::response('', $status, ['Retry-After' => '45']),
            'https://api.kraken.com/0/public/*' => Http::response('', $status, ['Retry-After' => '45']),
            'https://api.coingecko.com/api/v3/*' => Http::response('', $status, ['Retry-After' => '45']),
        ]);
        $provider = app($adapter);
        $crypto = new Currency('BTC', CurrencyType::CRYPTO, 'XBTUSD', null, 'bitcoin');
        $this->expectException($exceptionClass);

        try {
            match ($operation) {
                'fiat_rate' => $provider->getRate(Currency::fiat('USD'), Currency::fiat('BYN')),
                'crypto_rate' => $provider->getRate($crypto, Currency::fiat('USD')),
                'fiat_chart' => $provider->chart(Currency::fiat('USD'), 7),
                'crypto_chart' => $provider->chart($crypto, 60),
                'daily' => $provider->dailyChanges([$crypto]),
                'catalog' => $provider->currencies(),
            };
        } catch (ProviderRateLimitException $exception) {
            $this->assertSame(45, $exception->retryAfter);
            throw $exception;
        } finally {
            Http::assertSentCount(str_contains($adapter, 'Nbrb') && $status === 503 ? 2 : 1);
        }
    }

    /** @return array<string, array{class-string, string, int, class-string}> */
    public static function adapterFailures(): array
    {
        $adapters = [
            'NbrbRateProvider' => 'fiat_rate', 'KrakenRateProvider' => 'crypto_rate', 'CoinGeckoRateProvider' => 'crypto_rate',
            'NbrbMarketDataProvider' => 'fiat_chart', 'KrakenMarketDataProvider' => 'crypto_chart', 'CoinGeckoDailyChangeProvider' => 'daily',
            'NbrbCurrencyCatalogProvider' => 'catalog', 'KrakenCurrencyCatalogProvider' => 'catalog', 'CoinGeckoCurrencyCatalogProvider' => 'catalog',
        ];
        $cases = [];
        foreach ($adapters as $adapter => $operation) {
            foreach ([429 => ProviderRateLimitException::class, 503 => ProviderResponseException::class] as $status => $exception) {
                $cases[$adapter.' '.$status] = ['App\\Currency\\Providers\\'.$adapter, $operation, $status, $exception];
            }
        }

        return $cases;
    }
}
