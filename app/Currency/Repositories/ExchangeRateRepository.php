<?php

namespace App\Currency\Repositories;

use App\Currency\DTO\ExchangeRate;
use App\Currency\Enums\Currency;
use App\Currency\Enums\RateSource;
use App\Models\StoredExchangeRate;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

final class ExchangeRateRepository
{
    public function save(ExchangeRate $rate): ExchangeRate
    {
        return DB::transaction(function () use ($rate): ExchangeRate {
            $identity = ['provider' => $rate->source->value, 'from_currency' => $rate->from->code, 'to_currency' => $rate->to->code];
            $stored = StoredExchangeRate::query()->where($identity)->lockForUpdate()->first();

            if ($rate->source === RateSource::NBRB && $stored?->published_at !== null
                && ($rate->rateDate === null || $this->isOlderRateDate($rate->rateDate, $stored->published_at))) {
                $cached = $this->map($stored);
                if ($cached !== null) {
                    return new ExchangeRate(
                        $cached->from,
                        $cached->to,
                        $cached->rate,
                        $cached->source,
                        $cached->fetchedAt,
                        $cached->rateDate,
                        $cached->isStale,
                        'provider_outdated',
                        true,
                    );
                }
            }

            StoredExchangeRate::query()->updateOrCreate($identity, [
                'rate' => $rate->rate, 'fetched_at' => $rate->fetchedAt, 'published_at' => $rate->rateDate,
            ]);

            return $rate;
        });
    }

    public function findLatest(RateSource $source, Currency $from, Currency $to): ?ExchangeRate
    {
        return $this->map(StoredExchangeRate::query()->where([
            'provider' => $source->value,
            'from_currency' => $from->code,
            'to_currency' => $to->code,
        ])->first());
    }

    public function findFresh(RateSource $source, Currency $from, Currency $to, DateTimeImmutable $freshAfter): ?ExchangeRate
    {
        return $this->map(StoredExchangeRate::query()->where([
            'provider' => $source->value,
            'from_currency' => $from->code,
            'to_currency' => $to->code,
        ])->where('fetched_at', '>=', $freshAfter)->first());
    }

    private function map(?StoredExchangeRate $stored): ?ExchangeRate
    {
        return $stored === null ? null : new ExchangeRate(
            $this->currency($stored->from_currency, RateSource::from($stored->provider), true), $this->currency($stored->to_currency, RateSource::from($stored->provider), false), $stored->rate,
            RateSource::from($stored->provider), $stored->fetched_at, $stored->published_at,
        );
    }

    private function currency(string $code, RateSource $source, bool $from): Currency
    {
        return in_array($source, [RateSource::KRAKEN, RateSource::COINGECKO], true) && $from ? Currency::crypto($code) : Currency::fiat($code);
    }

    private function isOlderRateDate(DateTimeImmutable $incoming, DateTimeImmutable $stored): bool
    {
        $timezone = new DateTimeZone('Europe/Minsk');

        return $incoming->setTimezone($timezone)->format('Y-m-d') < $stored->setTimezone($timezone)->format('Y-m-d');
    }
}
