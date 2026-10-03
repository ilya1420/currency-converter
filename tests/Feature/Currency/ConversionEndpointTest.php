<?php

namespace Tests\Feature\Currency;

use App\Currency\DTO\ExchangeRate;
use App\Currency\Enums\Currency;
use App\Currency\Enums\RateSource;
use App\Currency\Repositories\ExchangeRateRepository;
use App\Models\StoredExchangeRate;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
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

    public function test_a_corrupted_saved_rate_date_returns_503_when_the_provider_is_offline(): void
    {
        $this->travelTo(new DateTimeImmutable('2026-10-01T12:00:00Z'));
        Http::preventStrayRequests();
        $this->primeCryptoCatalog();
        $this->getJson('/currencies')->assertOk();
        DB::table('exchange_rates')->insert([
            'provider' => 'nbrb', 'from_currency' => 'USD', 'to_currency' => 'BYN',
            'rate' => '3.12', 'fetched_at' => '2026-10-01 11:00:00', 'published_at' => 'not-a-date',
        ]);
        Http::fake(['https://api.nbrb.by/exrates/rates?periodicity=0' => Http::failedConnection('offline')]);

        $this->postJson('/conversion', ['amount' => '1', 'from' => 'USD', 'to' => 'BYN'])
            ->assertServiceUnavailable()
            ->assertJsonPath('code', 'provider_unavailable')
            ->assertJsonPath('provider', 'nbrb');

        $this->assertDatabaseHas('exchange_rates', ['published_at' => 'not-a-date', 'rate' => '3.12']);
    }

    public function test_it_uses_a_saved_rate_when_offline(): void
    {
        (new ExchangeRateRepository)->save(new ExchangeRate(Currency::fiat('USD'), Currency::fiat('BYN'), '3.12', RateSource::NBRB, (new DateTimeImmutable)->modify('-7 hours')));
        Http::fake([
            'https://api.nbrb.by/exrates/rates?periodicity=0' => Http::response($this->fixture('nbrb-usd.json')),
            'https://api.kraken.com/0/public/AssetPairs*' => Http::response(['error' => [], 'result' => []]),
        ]);
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
        $this->travelTo(new DateTimeImmutable('2026-09-24T12:00:00+03:00'));
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

    public function test_a_successful_cross_rate_reports_the_oldest_official_day_and_staleness(): void
    {
        $this->travelTo(new DateTimeImmutable('2026-10-01T12:00:00+03:00'));
        Http::preventStrayRequests();
        Http::fake([
            'https://api.nbrb.by/exrates/rates?periodicity=0' => Http::response([
                ['Cur_ID' => 431, 'Cur_Abbreviation' => 'USD', 'Cur_Name' => 'Доллар США', 'Cur_Scale' => 1, 'Cur_OfficialRate' => 3, 'Date' => '2026-09-30T00:00:00'],
                ['Cur_ID' => 451, 'Cur_Abbreviation' => 'EUR', 'Cur_Name' => 'Евро', 'Cur_Scale' => 1, 'Cur_OfficialRate' => 4, 'Date' => '2026-10-01T00:00:00'],
            ]),
            'https://api.kraken.com/0/public/AssetPairs*' => Http::response(['error' => [], 'result' => []]),
            'https://api.coingecko.com/api/v3/coins/markets*' => Http::response([]),
        ]);

        $this->postJson('/conversions', ['from' => 'EUR', 'targets' => ['USD'], 'refresh' => true])
            ->assertOk()
            ->assertJsonPath('conversions.USD.factor', '1.333333333333333333')
            ->assertJsonPath('conversions.USD.isStale', true)
            ->assertJsonPath('conversions.USD.isFallback', false)
            ->assertJsonPath('conversions.USD.rateDate', '2026-09-30')
            ->assertJsonPath('conversions.USD.rateDates', ['2026-09-30', '2026-10-01']);

        $this->assertDatabaseCount('exchange_rates', 2);
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

    public function test_it_returns_503_when_a_saved_rate_is_corrupt_and_provider_is_offline(): void
    {
        StoredExchangeRate::query()->create([
            'provider' => 'kraken', 'from_currency' => 'BTC', 'to_currency' => 'USD',
            'rate' => '0', 'fetched_at' => now(),
        ]);
        Http::preventStrayRequests();
        $this->primeCryptoCatalog();
        Http::fake(['https://api.kraken.com/0/public/Ticker*' => Http::failedConnection()]);

        $this->postJson('/conversion', ['amount' => '1', 'from' => 'BTC', 'fromType' => 'crypto', 'to' => 'USD'])
            ->assertServiceUnavailable()
            ->assertJsonPath('code', 'provider_timeout')
            ->assertJsonPath('provider', 'kraken');

        $this->assertDatabaseHas('exchange_rates', ['provider' => 'kraken', 'rate' => '0']);
    }

    public function test_it_refreshes_a_corrupt_saved_rate_and_persists_the_valid_response(): void
    {
        StoredExchangeRate::query()->create([
            'provider' => 'kraken', 'from_currency' => 'BTC', 'to_currency' => 'USD',
            'rate' => 'invalid', 'fetched_at' => now(),
        ]);
        Http::preventStrayRequests();
        $this->primeCryptoCatalog();
        Http::fake(['https://api.kraken.com/0/public/Ticker*' => Http::response([
            'error' => [], 'result' => ['XXBTZUSD' => ['c' => ['64000']]],
        ])]);

        $this->postJson('/conversion', ['amount' => '1', 'from' => 'BTC', 'fromType' => 'crypto', 'to' => 'USD'])
            ->assertOk()
            ->assertJsonPath('targetAmount', '64000.000000000000000000')
            ->assertJsonPath('isFallback', false);

        $this->assertDatabaseHas('exchange_rates', ['provider' => 'kraken', 'rate' => '64000']);
        $this->assertDatabaseCount('exchange_rates', 1);
        Http::assertSentCount(1);
    }

    public function test_it_returns_503_for_a_malformed_provider_price_without_persisting_it(): void
    {
        Http::preventStrayRequests();
        $this->primeCryptoCatalog();
        Http::fake(['https://api.kraken.com/0/public/Ticker*' => Http::response([
            'error' => [], 'result' => ['XXBTZUSD' => ['c' => [true]]],
        ])]);

        $this->postJson('/conversion', ['amount' => '1', 'from' => 'BTC', 'fromType' => 'crypto', 'to' => 'USD'])
            ->assertServiceUnavailable()
            ->assertJsonPath('code', 'provider_unavailable')
            ->assertJsonPath('provider', 'kraken');

        $this->assertDatabaseCount('exchange_rates', 0);
        Http::assertSentCount(1);
    }

    public function test_it_keeps_a_valid_saved_rate_when_the_refreshed_price_is_invalid(): void
    {
        $repository = new ExchangeRateRepository;
        $repository->save(new ExchangeRate(
            Currency::crypto('BTC', 'XBTUSD'), Currency::fiat('USD'), '64000', RateSource::KRAKEN,
            (new DateTimeImmutable)->modify('-2 minutes'),
        ));
        Http::preventStrayRequests();
        $this->primeCryptoCatalog();
        Http::fake(['https://api.kraken.com/0/public/Ticker*' => Http::response([
            'error' => [], 'result' => ['XXBTZUSD' => ['c' => [[123]]]],
        ])]);

        $this->postJson('/conversion', ['amount' => '1', 'from' => 'BTC', 'fromType' => 'crypto', 'to' => 'USD', 'refresh' => true])
            ->assertOk()
            ->assertJsonPath('targetAmount', '64000.000000000000000000')
            ->assertJsonPath('isFallback', true)
            ->assertJsonPath('isStale', true)
            ->assertJsonPath('fallbackReasons.0', 'provider_unavailable')
            ->assertJsonPath('sources.0', 'kraken');

        $this->assertDatabaseHas('exchange_rates', ['provider' => 'kraken', 'rate' => '64000']);
        Http::assertSentCount(1);
    }
}
