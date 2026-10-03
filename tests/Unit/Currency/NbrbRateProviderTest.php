<?php

namespace Tests\Unit\Currency;

use App\Currency\Enums\Currency;
use App\Currency\Enums\RateSource;
use App\Currency\Exceptions\ProviderException;
use App\Currency\Exceptions\ProviderRateLimitException;
use App\Currency\Exceptions\ProviderResponseException;
use App\Currency\Exceptions\UnsupportedCurrencyPairException;
use App\Currency\Providers\NbrbRateProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class NbrbRateProviderTest extends TestCase
{
    private NbrbRateProvider $provider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->provider = new NbrbRateProvider;
    }

    public function test_it_supports_only_non_byn_fiat_to_byn(): void
    {
        $this->assertTrue($this->provider->supports(Currency::fiat('USD'), Currency::fiat('BYN')));
        $this->assertFalse($this->provider->supports(Currency::fiat('BYN'), Currency::fiat('USD')));
        $this->assertFalse($this->provider->supports(Currency::crypto('BTC'), Currency::fiat('BYN')));
    }

    public function test_it_returns_a_normalized_usd_to_byn_rate(): void
    {
        Http::fake(['https://api.nbrb.by/exrates/rates?periodicity=0' => Http::response($this->fixture('nbrb-usd.json'))]);

        $rate = $this->provider->getRate(Currency::fiat('USD'), Currency::fiat('BYN'));

        $this->assertSame('3.120000000000000000', $rate->rate);
        $this->assertSame(RateSource::NBRB, $rate->source);
        $this->assertSame('2026-09-24T00:00:00+03:00', $rate->rateDate?->format(DATE_ATOM));
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.nbrb.by/exrates/rates?periodicity=0');
    }

    public function test_it_normalizes_cur_scale_to_one_currency_unit(): void
    {
        Http::fake(['https://api.nbrb.by/exrates/rates?periodicity=0' => Http::response($this->fixture('nbrb-rub-scale-100.json'))]);

        $rate = $this->provider->getRate(Currency::fiat('RUB'), Currency::fiat('BYN'));

        $this->assertSame('0.035000000000000000', $rate->rate);
    }

    public function test_force_refresh_bypasses_the_provider_catalog_cache(): void
    {
        Http::fake(['https://api.nbrb.by/exrates/rates?periodicity=0' => Http::response($this->fixture('nbrb-usd.json'))]);

        $this->provider->getRate(Currency::fiat('USD'), Currency::fiat('BYN'));
        $this->provider->getRate(Currency::fiat('USD'), Currency::fiat('BYN'));
        $this->provider->getRate(Currency::fiat('USD'), Currency::fiat('BYN'), true);

        Http::assertSentCount(2);
    }

    public function test_it_rejects_a_rate_without_an_effective_date(): void
    {
        Http::fake(['https://api.nbrb.by/exrates/rates?periodicity=0' => Http::response([[
            'Cur_Abbreviation' => 'USD',
            'Cur_Scale' => 1,
            'Cur_OfficialRate' => 3.12,
        ]])]);

        $this->expectException(ProviderResponseException::class);

        $this->provider->getRate(Currency::fiat('USD'), Currency::fiat('BYN'));
    }

    #[TestWith([''])]
    #[TestWith(['today'])]
    #[TestWith(['2026-02-30T00:00:00'])]
    #[TestWith(['2026-09-24T25:00:00'])]
    #[TestWith(['2026-09-24T00:00:00+25:00'])]
    #[TestWith(['2026-09-24T00:00:00+03:75'])]
    #[TestWith(['2026-09-24 trailing text'])]
    public function test_it_rejects_a_malformed_effective_date(string $date): void
    {
        $this->travelTo(new \DateTimeImmutable('2026-10-01T12:00:00+03:00'));
        Http::preventStrayRequests();
        Http::fake(['https://api.nbrb.by/exrates/rates?periodicity=0' => Http::response([[
            'Cur_Abbreviation' => 'USD', 'Cur_Scale' => 1, 'Cur_OfficialRate' => 3.12, 'Date' => $date,
        ]])]);

        $this->expectException(ProviderResponseException::class);

        $this->provider->getRate(Currency::fiat('USD'), Currency::fiat('BYN'));
    }

    public function test_it_rejects_a_future_effective_day_in_minsk(): void
    {
        $this->travelTo(new \DateTimeImmutable('2026-10-01T23:30:00+03:00'));
        Http::preventStrayRequests();
        Http::fake(['https://api.nbrb.by/exrates/rates?periodicity=0' => Http::response([[
            'Cur_Abbreviation' => 'USD', 'Cur_Scale' => 1, 'Cur_OfficialRate' => 3.12, 'Date' => '2026-10-01T21:00:00Z',
        ]])]);

        $this->expectException(ProviderResponseException::class);

        $this->provider->getRate(Currency::fiat('USD'), Currency::fiat('BYN'));
    }

    public function test_it_marks_a_successful_previous_day_response_stale_without_fallback(): void
    {
        $this->travelTo(new \DateTimeImmutable('2026-10-01T00:01:00+03:00'));
        Http::preventStrayRequests();
        Http::fake(['https://api.nbrb.by/exrates/rates?periodicity=0' => Http::response([[
            'Cur_Abbreviation' => 'USD', 'Cur_Scale' => 1, 'Cur_OfficialRate' => 3.12, 'Date' => '2026-09-30T00:00:00',
        ]])]);

        $rate = $this->provider->getRate(Currency::fiat('USD'), Currency::fiat('BYN'));

        $this->assertTrue($rate->isStale);
        $this->assertFalse($rate->isFallback);
        $this->assertSame('2026-09-30', $rate->rateDate?->format('Y-m-d'));
        $this->assertSame('2026-09-30T21:01:00+00:00', $rate->fetchedAt->format(DATE_ATOM));
        Http::assertSentCount(1);
    }

    public function test_it_normalizes_an_offset_date_to_the_minsk_effective_day(): void
    {
        $this->travelTo(new \DateTimeImmutable('2028-03-01T00:10:00+03:00'));
        Http::preventStrayRequests();
        Http::fake(['https://api.nbrb.by/exrates/rates?periodicity=0' => Http::response([[
            'Cur_Abbreviation' => 'USD', 'Cur_Scale' => 1, 'Cur_OfficialRate' => 3.12, 'Date' => '2028-02-29T21:00:00.000Z',
        ]])]);

        $rate = $this->provider->getRate(Currency::fiat('USD'), Currency::fiat('BYN'));

        $this->assertSame('2028-03-01T00:00:00+03:00', $rate->rateDate?->format(DATE_ATOM));
        $this->assertFalse($rate->isStale);
        Http::assertSentCount(1);
    }

    public function test_it_maps_http_errors_to_a_provider_exception(): void
    {
        Http::fake(['https://api.nbrb.by/exrates/rates?periodicity=0' => Http::response([], 503)]);

        $this->expectException(ProviderException::class);

        $this->provider->getRate(Currency::fiat('USD'), Currency::fiat('BYN'));
    }

    public function test_it_retries_a_transient_server_error_once_then_returns_the_rate(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.nbrb.by/exrates/rates?periodicity=0' => Http::sequence()
                ->push([], 503)
                ->push($this->fixture('nbrb-usd.json')),
        ]);

        $rate = $this->provider->getRate(Currency::fiat('USD'), Currency::fiat('BYN'));

        $this->assertSame('3.120000000000000000', $rate->rate);
        Http::assertSentCount(2);
    }

    public function test_it_retries_a_connection_failure_once_then_returns_the_rate(): void
    {
        Http::preventStrayRequests();
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            if (++$attempts === 1) {
                throw new ConnectionException('Temporary connection failure.');
            }

            return Http::response($this->fixture('nbrb-usd.json'));
        });

        $rate = $this->provider->getRate(Currency::fiat('USD'), Currency::fiat('BYN'));

        $this->assertSame('3.120000000000000000', $rate->rate);
        $this->assertSame(2, $attempts);
    }

    public function test_it_does_not_retry_a_rate_limited_response(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.nbrb.by/exrates/rates?periodicity=0' => Http::sequence()
                ->push([], 429, ['Retry-After' => '30'])
                ->push($this->fixture('nbrb-usd.json')),
        ]);

        try {
            $this->provider->getRate(Currency::fiat('USD'), Currency::fiat('BYN'));
            $this->fail('A rate-limited response must remain visible to the caller.');
        } catch (ProviderRateLimitException $exception) {
            $this->assertSame(30, $exception->retryAfter);
            Http::assertSentCount(1);
        }
    }

    public function test_it_stops_after_the_configured_number_of_attempts(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.nbrb.by/exrates/rates?periodicity=0' => Http::sequence()
                ->push([], 503)
                ->push([], 503)
                ->push($this->fixture('nbrb-usd.json')),
        ]);

        try {
            $this->provider->getRate(Currency::fiat('USD'), Currency::fiat('BYN'));
            $this->fail('A persistent upstream failure must stop after the configured attempts.');
        } catch (ProviderException) {
            Http::assertSentCount(2);
        }
    }

    public function test_it_rejects_an_unsupported_direction_without_a_request(): void
    {
        Http::fake();

        try {
            $this->provider->getRate(Currency::fiat('BYN'), Currency::fiat('USD'));
            $this->fail('Expected unsupported pair exception.');
        } catch (UnsupportedCurrencyPairException) {
            Http::assertNothingSent();
        }

    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(base_path("tests/Fixtures/nbrb/{$name}"));
    }

    #[DataProvider('invalidRateData')]
    public function test_it_rejects_invalid_rate_or_scale(mixed $officialRate, mixed $scale): void
    {
        $this->travelTo('2026-09-24 12:00:00');
        Http::preventStrayRequests();
        Http::fake(['https://api.nbrb.by/exrates/rates?periodicity=0' => Http::response([[
            'Cur_Abbreviation' => 'USD', 'Date' => '2026-09-24T00:00:00',
            'Cur_OfficialRate' => $officialRate, 'Cur_Scale' => $scale,
        ]])]);

        $this->expectException(ProviderResponseException::class);
        try {
            $this->provider->getRate(Currency::fiat('USD'), Currency::fiat('BYN'));
        } finally {
            Http::assertSentCount(1);
        }
    }

    /** @return array<string, array{mixed, mixed}> */
    public static function invalidRateData(): array
    {
        return [
            'array rate' => [[3.12], 1], 'boolean rate' => [true, 1],
            'missing rate' => [null, 1], 'garbage rate' => ['oops', 1],
            'zero rate' => [0, 1], 'negative rate' => [-3.12, 1],
            'double negative' => [-3.12, -1], 'negative scale' => [3.12, -1],
            'zero scale' => [3.12, 0], 'array scale' => [3.12, [1]],
            'boolean scale' => [3.12, true], 'missing scale' => [3.12, null],
            'excessive exponent' => ['1e1024', 1], 'fraction' => ['1/2', 1],
        ];
    }
}
