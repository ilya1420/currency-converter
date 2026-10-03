<?php

namespace Tests\Feature\Currency;

use App\Currency\DTO\ExchangeRate;
use App\Currency\Enums\Currency;
use App\Currency\Enums\RateSource;
use App\Currency\Repositories\ExchangeRateRepository;
use App\Models\StoredExchangeRate;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\TestWith;
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

    #[TestWith(['2026-10-02 00:00:00'])]
    #[TestWith(['2026-02-30 00:00:00'])]
    #[TestWith(['not-a-date'])]
    #[TestWith([''])]
    #[TestWith(["2026-10-01 00:00:00\0"])]
    public function test_an_invalid_saved_effective_date_is_ignored_and_can_be_replaced(string $date): void
    {
        $this->travelTo(new DateTimeImmutable('2026-10-01T12:00:00+03:00'));
        DB::table('exchange_rates')->insert([
            'provider' => 'nbrb', 'from_currency' => 'USD', 'to_currency' => 'BYN',
            'rate' => '3.10', 'fetched_at' => '2026-10-01 08:00:00', 'published_at' => $date,
        ]);
        $repository = new ExchangeRateRepository;
        $this->assertNull($repository->findLatest(RateSource::NBRB, Currency::fiat('USD'), Currency::fiat('BYN')));

        $result = $repository->save($this->rate('3.12', '2026-10-01T09:00:00Z', '2026-10-01'));
        $saved = $repository->findLatest(RateSource::NBRB, Currency::fiat('USD'), Currency::fiat('BYN'));

        $this->assertSame('3.12', $result->rate);
        $this->assertFalse($result->isFallback);
        $this->assertSame('2026-10-01', $saved?->rateDate?->format('Y-m-d'));
        $this->assertDatabaseCount('exchange_rates', 1);
    }

    public function test_it_marks_a_previous_effective_day_stale_when_an_older_response_is_rejected(): void
    {
        $this->travelTo(new DateTimeImmutable('2026-10-01T12:00:00+03:00'));
        $repository = new ExchangeRateRepository;
        $repository->save($this->rate('3.12', '2026-09-30T09:00:00Z', '2026-09-30'));

        $result = $repository->save($this->rate('3.10', '2026-10-01T09:00:00Z', '2026-09-29'));

        $this->assertTrue($result->isStale);
        $this->assertTrue($result->isFallback);
        $this->assertSame('provider_outdated', $result->fallbackReason);
        $this->assertSame('3.12', $result->rate);
    }

    public function test_it_preserves_the_effective_day_and_fetch_instant_across_timezones(): void
    {
        $this->travelTo(new DateTimeImmutable('2026-10-01T00:10:00+03:00'));
        $repository = new ExchangeRateRepository;

        $repository->save($this->rate('3.12', '2026-10-01T00:05:00+03:00', '2026-10-01'));
        $saved = $repository->findLatest(RateSource::NBRB, Currency::fiat('USD'), Currency::fiat('BYN'));

        $this->assertSame('2026-10-01', $saved?->rateDate?->format('Y-m-d'));
        $this->assertSame('2026-09-30T21:05:00+00:00', $saved?->fetchedAt->format(DATE_ATOM));
        $this->assertFalse($saved?->isStale);
    }

    #[TestWith(['2026-10-01 10:00:00'])]
    #[TestWith(['not-a-date'])]
    public function test_a_bad_saved_fetch_time_is_ignored_and_can_be_replaced(string $fetchedAt): void
    {
        $this->travelTo(new DateTimeImmutable('2026-10-01T12:00:00+03:00'));
        DB::table('exchange_rates')->insert([
            'provider' => 'nbrb', 'from_currency' => 'USD', 'to_currency' => 'BYN',
            'rate' => '3.10', 'fetched_at' => $fetchedAt, 'published_at' => '2026-10-01 00:00:00',
        ]);
        $repository = new ExchangeRateRepository;
        $this->assertNull($repository->findLatest(RateSource::NBRB, Currency::fiat('USD'), Currency::fiat('BYN')));

        $result = $repository->save($this->rate('3.12', '2026-10-01T09:00:00Z', '2026-10-01'));

        $this->assertSame('3.12', $result->rate);
        $this->assertDatabaseHas('exchange_rates', ['provider' => 'nbrb', 'rate' => '3.12', 'fetched_at' => '2026-10-01 09:00:00']);
    }

    public function test_it_accepts_a_corrected_value_for_the_same_official_day(): void
    {
        $this->travelTo(new DateTimeImmutable('2026-10-01T12:00:00+03:00'));
        $repository = new ExchangeRateRepository;
        $repository->save($this->rate('3.12', '2026-10-01T08:00:00Z', '2026-10-01'));

        $result = $repository->save($this->rate('3.13', '2026-10-01T09:00:00Z', '2026-10-01'));

        $this->assertSame('3.13', $result->rate);
        $this->assertFalse($result->isFallback);
        $this->assertDatabaseHas('exchange_rates', ['provider' => 'nbrb', 'rate' => '3.13', 'fetched_at' => '2026-10-01 09:00:00']);
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
}
