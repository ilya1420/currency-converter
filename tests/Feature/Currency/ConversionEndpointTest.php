<?php

namespace Tests\Feature\Currency;

use App\Currency\DTO\ExchangeRate;
use App\Currency\Enums\Currency;
use App\Currency\Enums\RateSource;
use App\Currency\Repositories\ExchangeRateRepository;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ConversionEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_a_safe_offline_error_when_no_rate_is_cached(): void
    {
        Http::fake(static fn (): never => throw new ConnectionException('offline'));

        $this->postJson('/conversion', ['amount' => '1', 'from' => 'USD', 'to' => 'BYN'])
            ->assertServiceUnavailable()
            ->assertJsonPath('code', 'provider_unavailable')
            ->assertJsonPath('provider', 'nbrb')
            ->assertJsonPath('message', 'НБРБ временно недоступен. Попробуйте позже.');
    }

    public function test_it_uses_a_saved_rate_when_offline(): void
    {
        (new ExchangeRateRepository)->save(new ExchangeRate(Currency::fiat('USD'), Currency::fiat('BYN'), '3.12', RateSource::NBRB, (new DateTimeImmutable)->modify('-7 hours')));
        Http::fake(['https://api.nbrb.by/exrates/rates?periodicity=0' => Http::response($this->fixture('nbrb-usd.json'))]);
        $this->getJson('/currencies')->assertOk();
        Http::fake(static fn (): never => throw new ConnectionException('offline'));

        $this->postJson('/conversion', ['amount' => '1', 'from' => 'USD', 'to' => 'BYN'])
            ->assertOk()
            ->assertJsonPath('isStale', true)
            ->assertJsonPath('targetAmount', '3.120000000000000000')
            ->assertJsonPath('factorDisplay', '3.12')
            ->assertJsonPath('sources.0', 'nbrb');
    }

    public function test_batch_conversion_returns_stale_rate_and_error_for_unavailable_pair(): void
    {
        (new ExchangeRateRepository)->save(new ExchangeRate(Currency::fiat('USD'), Currency::fiat('BYN'), '3.12', RateSource::NBRB, (new DateTimeImmutable)->modify('-7 hours')));
        Http::preventStrayRequests();
        Http::fake(static fn () => Http::response([], 503));

        $this->postJson('/conversions', ['from' => 'USD', 'targets' => ['BYN', 'EUR']])
            ->assertOk()
            ->assertJsonPath('conversions.BYN.factor', '3.120000000000000000')
            ->assertJsonPath('conversions.BYN.sources.0', 'nbrb')
            ->assertJsonPath('conversions.BYN.isStale', true)
            ->assertJsonPath('conversions.EUR.error', 'provider_unavailable')
            ->assertJsonPath('conversions.EUR.message', 'НБРБ временно недоступен. Попробуйте позже.');
    }

    public function test_single_conversion_returns_429_and_retry_after_when_provider_is_rate_limited(): void
    {
        Http::preventStrayRequests();
        $this->primeCryptoCatalog();
        Http::fake([
            'https://api.kraken.com/0/public/Ticker*' => Http::response([
                'error' => ['EAPI:Rate limit exceeded'],
                'result' => [],
            ], 429, ['Retry-After' => '17']),
        ]);

        $this->postJson('/conversion', [
            'amount' => '1', 'from' => 'BTC', 'fromType' => 'crypto', 'to' => 'USD', 'toType' => 'fiat',
        ])
            ->assertTooManyRequests()
            ->assertHeader('Retry-After', '17')
            ->assertJsonPath('code', 'provider_rate_limited')
            ->assertJsonPath('provider', 'kraken')
            ->assertJsonPath('retryAfter', 17)
            ->assertJsonPath('message', 'Kraken временно ограничил частоту запросов. Попробуйте позже.');
    }

    public function test_batch_conversion_preserves_provider_error_details_per_currency(): void
    {
        Http::preventStrayRequests();
        $this->primeCryptoCatalog();
        Http::fake([
            'https://api.kraken.com/0/public/Ticker*' => Http::response([
                'error' => [],
                'result' => [],
            ], 503),
        ]);

        $this->postJson('/conversions', [
            'from' => 'USD', 'fromType' => 'fiat', 'targets' => ['BTC'],
        ])
            ->assertOk()
            ->assertJsonPath('conversions.BTC.error', 'provider_unavailable')
            ->assertJsonPath('conversions.BTC.code', 'provider_unavailable')
            ->assertJsonPath('conversions.BTC.provider', 'kraken')
            ->assertJsonPath('conversions.BTC.status', 503)
            ->assertJsonPath('conversions.BTC.message', 'Kraken временно недоступен. Попробуйте позже.');
    }

    public function test_batch_conversion_does_not_wait_for_nbrb_daily_changes(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.nbrb.by/exrates/rates?periodicity=0' => Http::response($this->fixture('nbrb-usd.json')),
            'https://api.nbrb.by/exrates/rates/dynamics/*' => Http::failedConnection(),
            'https://api.kraken.com/0/public/AssetPairs*' => Http::response(['error' => [], 'result' => []]),
            'https://api.coingecko.com/api/v3/coins/markets*' => Http::response([]),
        ]);

        $this->postJson('/conversions', ['from' => 'USD', 'fromType' => 'fiat', 'targets' => ['BYN']])
            ->assertOk()
            ->assertJsonPath('conversions.BYN.factor', '3.120000000000000000')
            ->assertJsonMissingPath('changes');

        Http::assertNotSent(static fn (Request $request): bool => str_contains($request->url(), '/dynamics/'));
    }

    public function test_daily_change_connection_failure_returns_null_instead_of_server_error(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.nbrb.by/exrates/rates?periodicity=0' => Http::response($this->fixture('nbrb-usd.json')),
            'https://api.nbrb.by/exrates/rates/dynamics/*' => Http::failedConnection(),
            'https://api.kraken.com/0/public/AssetPairs*' => Http::response(['error' => [], 'result' => []]),
            'https://api.coingecko.com/api/v3/coins/markets*' => Http::response([]),
        ]);

        $this->getJson('/daily-changes?currencies[]=USD')
            ->assertOk()
            ->assertJsonPath('changes.USD', null);
    }

    public function test_usd_to_crypto_uses_crypto_usd_rate_without_fiat_rates(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.nbrb.by/exrates/rates?periodicity=0' => Http::response($this->fixture('nbrb-usd.json')),
            'https://api.kraken.com/0/public/AssetPairs*' => Http::response([
                'error' => [],
                'result' => ['XBTUSD' => ['base' => 'XXBT', 'quote' => 'ZUSD', 'altname' => 'XBTUSD']],
            ]),
            'https://api.kraken.com/0/public/Ticker*' => Http::response([
                'error' => [],
                'result' => ['XXBTZUSD' => ['c' => ['64000'], 'o' => '63000']],
            ]),
            'https://api.coingecko.com/api/v3/coins/markets*' => Http::response([]),
        ]);

        $this->postJson('/conversion', [
            'amount' => '1',
            'from' => 'USD',
            'fromType' => 'fiat',
            'to' => 'BTC',
            'toType' => 'crypto',
        ])
            ->assertOk()
            ->assertJsonPath('factor', '0.000015625000000000')
            ->assertJsonPath('sources.0', 'kraken')
            ->assertJsonPath('isStale', false);

    }

    public function test_it_converts_usd_to_byn(): void
    {
        Http::fake([
            'https://api.nbrb.by/exrates/rates?periodicity=0' => Http::response(
                file_get_contents(base_path('tests/Fixtures/nbrb/nbrb-usd.json')),
            ),
        ]);

        $this->postJson('/conversion', ['amount' => '1', 'from' => 'USD', 'to' => 'BYN'])
            ->assertOk()
            ->assertJsonPath('targetAmount', '3.120000000000000000')
            ->assertJsonPath('targetDisplay', '3.12')
            ->assertJsonPath('factor', '3.120000000000000000')
            ->assertJsonPath('sources.0', 'nbrb')
            ->assertJsonPath('isStale', false);
    }

    public function test_it_rejects_invalid_amounts_and_currencies(): void
    {
        $this->postJson('/conversion', ['amount' => '1e3', 'from' => 'TOO-LONG-CODE', 'to' => 'BYN'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount', 'from']);
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path("tests/Fixtures/nbrb/{$name}"));
    }

    private function primeCryptoCatalog(): void
    {
        Http::fake([
            'https://api.nbrb.by/exrates/rates?periodicity=0' => Http::response($this->fixture('nbrb-usd.json')),
            'https://api.kraken.com/0/public/AssetPairs*' => Http::response([
                'error' => [],
                'result' => ['XBTUSD' => ['base' => 'XXBT', 'quote' => 'ZUSD', 'altname' => 'XBTUSD']],
            ]),
            'https://api.coingecko.com/api/v3/coins/markets*' => Http::response([]),
        ]);

        $this->getJson('/currencies')->assertOk();
    }
}
