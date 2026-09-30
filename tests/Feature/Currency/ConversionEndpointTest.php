<?php

namespace Tests\Feature\Currency;

use App\Currency\DTO\ExchangeRate;
use App\Currency\Enums\Currency;
use App\Currency\Enums\RateSource;
use App\Currency\Repositories\ExchangeRateRepository;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
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
            ->assertJson(['message' => 'No connection and no saved rate is available for this conversion.']);
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
            ->assertJsonPath('conversions.EUR.error', 'Нет курса');
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
}
