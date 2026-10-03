<?php

namespace App\Currency\Repositories;

use App\Currency\DTO\ExchangeRate;
use App\Currency\Enums\Currency;
use App\Currency\Enums\RateSource;
use App\Models\StoredExchangeRate;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use ValueError;

final class ExchangeRateRepository
{
    public function save(ExchangeRate $rate): ExchangeRate
    {
        return DB::transaction(function () use ($rate): ExchangeRate {
            $identity = ['provider' => $rate->source->value, 'from_currency' => $rate->from->code, 'to_currency' => $rate->to->code];
            $stored = StoredExchangeRate::query()->where($identity)->lockForUpdate()->first();
            $storedRateDate = $stored === null ? null : $this->storedDate($stored, 'published_at')?->setTimezone(new DateTimeZone('Europe/Minsk'));

            if ($rate->source === RateSource::NBRB && $storedRateDate !== null
                && $storedRateDate->format('Y-m-d') <= now('Europe/Minsk')->format('Y-m-d')
                && ($rate->rateDate === null || $this->isOlderRateDate($rate->rateDate, $storedRateDate))) {
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

            StoredExchangeRate::query()->upsert([[
                ...$identity,
                'rate' => $rate->rate,
                'fetched_at' => $rate->fetchedAt->setTimezone(new DateTimeZone('UTC')),
                'published_at' => $rate->rateDate?->setTimezone(new DateTimeZone('UTC')),
            ]], array_keys($identity), ['rate', 'fetched_at', 'published_at']);

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
        if ($stored === null) {
            return null;
        }

        $fetchedAt = $this->storedDate($stored, 'fetched_at');
        $rateDate = $this->storedDate($stored, 'published_at');
        if ($fetchedAt === null || $fetchedAt > now() || ($stored->getRawOriginal('published_at') !== null && $rateDate === null)) {
            return null;
        }

        $source = RateSource::from($stored->provider);
        $isStale = false;
        if ($source === RateSource::NBRB && $rateDate !== null) {
            $rateDate = $rateDate->setTimezone(new DateTimeZone('Europe/Minsk'));
            $today = now('Europe/Minsk')->format('Y-m-d');
            if ($rateDate->format('Y-m-d') > $today) {
                return null;
            }
            $isStale = $rateDate->format('Y-m-d') < $today;
        }

        return new ExchangeRate(
            $this->currency($stored->from_currency, RateSource::from($stored->provider), true), $this->currency($stored->to_currency, RateSource::from($stored->provider), false), $stored->rate,
            $source, $fetchedAt, $rateDate, $isStale,
        );
    }

    private function storedDate(StoredExchangeRate $stored, string $attribute): ?DateTimeImmutable
    {
        $value = $stored->getRawOriginal($attribute);
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            $date = DateTimeImmutable::createFromFormat('!'.$stored->getDateFormat(), $value, new DateTimeZone('UTC'));
        } catch (ValueError) {
            return null;
        }

        return $date !== false && DateTimeImmutable::getLastErrors() === false ? $date : null;
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
