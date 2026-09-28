<?php

namespace Tests\Feature\Currency;

use App\Currency\DTO\ExchangeRate;
use App\Currency\Enums\Currency;
use App\Currency\Enums\RateSource;
use App\Currency\Repositories\ExchangeRateRepository;
use App\Models\StoredExchangeRate;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_it_returns_only_a_fresh_rate(): void
    {
        $repository = new ExchangeRateRepository;
        $repository->save($this->rate('3.12', '2026-09-24T10:00:00+00:00'));

        $this->assertNull($repository->findFresh(RateSource::NBRB, Currency::fiat('USD'), Currency::fiat('BYN'), new DateTimeImmutable('2026-09-24T10:01:00+00:00')));
        $this->assertNotNull($repository->findFresh(RateSource::NBRB, Currency::fiat('USD'), Currency::fiat('BYN'), new DateTimeImmutable('2026-09-24T09:59:00+00:00')));
    }

    private function rate(string $value, string $fetchedAt): ExchangeRate
    {
        return new ExchangeRate(Currency::fiat('USD'), Currency::fiat('BYN'), $value, RateSource::NBRB, new DateTimeImmutable($fetchedAt));
    }
}
