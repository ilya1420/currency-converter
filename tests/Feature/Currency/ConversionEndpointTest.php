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
        (new ExchangeRateRepository)->save(new ExchangeRate(Currency::USD, Currency::BYN, '3.12', RateSource::NBRB, (new DateTimeImmutable)->modify('-7 hours')));
        Http::fake(static fn (): never => throw new ConnectionException('offline'));

        $this->postJson('/conversion', ['amount' => '1', 'from' => 'USD', 'to' => 'BYN'])
            ->assertOk()
            ->assertJsonPath('isStale', true)
            ->assertJsonPath('targetAmount', '3.120000000000000000');
    }

    public function test_it_rejects_invalid_amounts_and_currencies(): void
    {
        $this->postJson('/conversion', ['amount' => '1e3', 'from' => 'INVALID', 'to' => 'BYN'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['amount', 'from']);
    }
}
