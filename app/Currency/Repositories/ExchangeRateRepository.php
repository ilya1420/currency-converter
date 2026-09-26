<?php

namespace App\Currency\Repositories;

use App\Currency\DTO\ExchangeRate;
use App\Currency\Enums\Currency;
use App\Currency\Enums\RateSource;
use App\Models\StoredExchangeRate;
use DateTimeImmutable;

final class ExchangeRateRepository
{
    public function save(ExchangeRate $rate): ExchangeRate
    {
        StoredExchangeRate::query()->updateOrCreate(
            ['provider' => $rate->source->value, 'from_currency' => $rate->from->value, 'to_currency' => $rate->to->value],
            ['rate' => $rate->rate, 'fetched_at' => $rate->fetchedAt, 'published_at' => $rate->publishedAt],
        );

        return $rate;
    }

    public function findLatest(RateSource $source, Currency $from, Currency $to): ?ExchangeRate
    {
        return $this->map(StoredExchangeRate::query()->where([
            'provider' => $source->value,
            'from_currency' => $from->value,
            'to_currency' => $to->value,
        ])->first());
    }

    public function findFresh(RateSource $source, Currency $from, Currency $to, DateTimeImmutable $freshAfter): ?ExchangeRate
    {
        return $this->map(StoredExchangeRate::query()->where([
            'provider' => $source->value,
            'from_currency' => $from->value,
            'to_currency' => $to->value,
        ])->where('fetched_at', '>=', $freshAfter)->first());
    }

    private function map(?StoredExchangeRate $stored): ?ExchangeRate
    {
        return $stored === null ? null : new ExchangeRate(
            Currency::from($stored->from_currency), Currency::from($stored->to_currency), $stored->rate,
            RateSource::from($stored->provider), $stored->fetched_at, $stored->published_at,
        );
    }
}
