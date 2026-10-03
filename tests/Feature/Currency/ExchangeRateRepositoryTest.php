<?php

namespace Tests\Feature\Currency;

use App\Currency\DTO\ExchangeRate;
use App\Currency\Enums\Currency;
use App\Currency\Enums\RateSource;
use App\Currency\Repositories\ExchangeRateRepository;
use App\Models\StoredExchangeRate;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ExchangeRateRepositoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_saves_and_returns_a_rate(): void
    {
        $rate = $this->rate('3.120000000000000000', '2026-09-24T10:00:00+00:00');
        $repository = new ExchangeRateRepository;

        $repository->save($rate);

        $stored = $repository->findLatest(RateSource::NBRB, Currency::fiat('USD'), Currency::fiat('BYN'));

        $this->assertSame($rate->rate, $stored?->rate);
        $this->assertSame($rate->fetchedAt->format(DATE_ATOM), $stored?->fetchedAt->format(DATE_ATOM));
    }

    public function test_it_updates_the_existing_canonical_pair(): void
    {
        $repository = new ExchangeRateRepository;
        $repository->save($this->rate('3.12', '2026-09-24T10:00:00+00:00'));
        $repository->save($this->rate('3.15', '2026-09-24T11:00:00+00:00'));

        $this->assertSame(1, StoredExchangeRate::query()->count());
        $this->assertSame('3.15', $repository->findLatest(RateSource::NBRB, Currency::fiat('USD'), Currency::fiat('BYN'))?->rate);
    }

    public function test_it_does_not_replace_a_newer_official_rate_date_with_an_older_response(): void
    {
        $repository = new ExchangeRateRepository;
        $repository->save($this->rate('3.12', '2026-09-24T10:00:00+00:00', '2026-09-24'));

        $result = $repository->save($this->rate('3.10', '2026-09-25T10:00:00+00:00', '2026-09-23'));
        $stored = $repository->findLatest(RateSource::NBRB, Currency::fiat('USD'), Currency::fiat('BYN'));

        $this->assertSame('3.12', $stored?->rate);
        $this->assertSame('2026-09-24', $stored?->rateDate?->format('Y-m-d'));
        $this->assertTrue($result->isFallback);
        $this->assertSame('provider_outdated', $result->fallbackReason);
    }

    public function test_it_returns_only_a_fresh_rate(): void
    {
        $repository = new ExchangeRateRepository;
        $repository->save($this->rate('3.12', '2026-09-24T10:00:00+00:00'));

        $this->assertNull($repository->findFresh(RateSource::NBRB, Currency::fiat('USD'), Currency::fiat('BYN'), new DateTimeImmutable('2026-09-24T10:01:00+00:00')));
        $this->assertNotNull($repository->findFresh(RateSource::NBRB, Currency::fiat('USD'), Currency::fiat('BYN'), new DateTimeImmutable('2026-09-24T09:59:00+00:00')));
    }

    private function rate(string $value, string $fetchedAt, ?string $rateDate = null): ExchangeRate
    {
        return new ExchangeRate(
            Currency::fiat('USD'),
            Currency::fiat('BYN'),
            $value,
            RateSource::NBRB,
            new DateTimeImmutable($fetchedAt),
            $rateDate === null ? null : new DateTimeImmutable($rateDate, new \DateTimeZone('Europe/Minsk')),
        );
    }

    #[DataProvider('invalidStoredRates')]
    public function test_it_ignores_invalid_saved_rates(string $value): void
    {
        StoredExchangeRate::query()->create([
            'provider' => 'nbrb', 'from_currency' => 'USD', 'to_currency' => 'BYN',
            'rate' => $value, 'fetched_at' => '2026-09-24 10:00:00', 'published_at' => '2026-09-24 00:00:00',
        ]);
        $repository = new ExchangeRateRepository;

        $this->assertNull($repository->findLatest(RateSource::NBRB, Currency::fiat('USD'), Currency::fiat('BYN')));
        $this->assertNull($repository->findFresh(RateSource::NBRB, Currency::fiat('USD'), Currency::fiat('BYN'), new DateTimeImmutable('2026-09-24T09:00:00Z')));
    }

    public function test_it_replaces_a_corrupt_rate_even_when_its_official_date_is_newer(): void
    {
        StoredExchangeRate::query()->create([
            'provider' => 'nbrb', 'from_currency' => 'USD', 'to_currency' => 'BYN',
            'rate' => '0', 'fetched_at' => '2026-09-24 10:00:00', 'published_at' => '2026-09-24 00:00:00',
        ]);

        $result = (new ExchangeRateRepository)->save($this->rate('3.12', '2026-09-24T11:00:00Z', '2026-09-23'));

        $this->assertSame('3.12', $result->rate);
        $this->assertFalse($result->isFallback);
        $this->assertDatabaseHas('exchange_rates', ['provider' => 'nbrb', 'rate' => '3.12']);
        $this->assertDatabaseCount('exchange_rates', 1);
    }

    /** @return array<string, array{string}> */
    public static function invalidStoredRates(): array
    {
        return [
            'zero' => ['0'], 'negative' => ['-3.12'], 'empty' => [''],
            'text' => ['invalid'], 'json' => ['[3.12]'], 'infinity' => ['INF'],
            'fraction' => ['1/2'], 'excessive exponent' => ['1e1024'],
        ];
    }

    public function test_it_accepts_a_decimal_at_the_digit_limit_without_losing_precision(): void
    {
        $value = '0.'.str_repeat('1', 1023);
        $repository = new ExchangeRateRepository;

        $repository->save($this->rate($value, '2026-09-24T10:00:00Z'));

        $this->assertSame($value, $repository->findLatest(RateSource::NBRB, Currency::fiat('USD'), Currency::fiat('BYN'))?->rate);
    }
}
