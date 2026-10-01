<?php

namespace Tests\Unit\Currency;

use App\Currency\Enums\Currency;
use App\Currency\Enums\RateSource;
use App\Currency\Exceptions\ProviderException;
use App\Currency\Exceptions\ProviderRateLimitException;
use App\Currency\Exceptions\UnsupportedCurrencyPairException;
use App\Currency\Providers\NbrbRateProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
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
        $this->assertSame('2026-09-24T00:00:00+03:00', $rate->publishedAt?->format(DATE_ATOM));
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.nbrb.by/exrates/rates?periodicity=0');
    }

    public function test_it_normalizes_cur_scale_to_one_currency_unit(): void
    {
        Http::fake(['https://api.nbrb.by/exrates/rates?periodicity=0' => Http::response($this->fixture('nbrb-rub-scale-100.json'))]);

        $rate = $this->provider->getRate(Currency::fiat('RUB'), Currency::fiat('BYN'));

        $this->assertSame('0.035000000000000000', $rate->rate);
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
}
