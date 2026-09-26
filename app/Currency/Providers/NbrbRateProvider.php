<?php

namespace App\Currency\Providers;

use App\Currency\Contracts\RateProviderInterface;
use App\Currency\DTO\ExchangeRate;
use App\Currency\Enums\Currency;
use App\Currency\Enums\CurrencyType;
use App\Currency\Enums\RateSource;
use App\Currency\Exceptions\ProviderException;
use App\Currency\Exceptions\UnsupportedCurrencyPairException;
use App\Currency\Services\DecimalCalculator;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

final class NbrbRateProvider implements RateProviderInterface
{
    public function supports(Currency $from, Currency $to): bool
    {
        return $from !== Currency::BYN
            && $from->type() === CurrencyType::FIAT
            && $to === Currency::BYN;
    }

    public function getRate(Currency $from, Currency $to): ExchangeRate
    {
        if (! $this->supports($from, $to)) {
            throw new UnsupportedCurrencyPairException("NBRB does not support {$from->value}/{$to->value}.");
        }

        try {
            $response = $this->request()->get('rates', ['periodicity' => 0]);
        } catch (ConnectionException $exception) {
            throw new ProviderException('NBRB is unavailable.', previous: $exception);
        }

        if ($response->failed()) {
            throw new ProviderException("NBRB returned HTTP {$response->status()}.");
        }

        $record = $this->findCurrency($response->json(), $from);

        if ($record === null) {
            throw new ProviderException("NBRB did not return {$from->value}.");
        }

        $rate = $this->normalizeRate($record);

        return new ExchangeRate(
            $from,
            Currency::BYN,
            $rate,
            RateSource::NBRB,
            new DateTimeImmutable,
            $this->publishedAt($record),
        );
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(config('currency.nbrb.base_url'))
            ->acceptJson()
            ->connectTimeout(config('currency.http.connect_timeout'))
            ->timeout(config('currency.http.timeout'))
            ->retry(
                config('currency.http.retry_times'),
                config('currency.http.retry_delay_ms'),
                static fn (Throwable $exception): bool => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response->serverError()),
                throw: false,
            );
    }

    /** @param array<mixed> $records */
    private function findCurrency(array $records, Currency $currency): ?array
    {
        foreach ($records as $record) {
            if (is_array($record) && ($record['Cur_Abbreviation'] ?? null) === $currency->value) {
                return $record;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $record */
    private function normalizeRate(array $record): string
    {
        $officialRate = $record['Cur_OfficialRate'] ?? null;
        $scale = $record['Cur_Scale'] ?? null;

        if (! is_int($scale) && ! is_float($scale) && ! is_string($scale)) {
            throw new ProviderException('NBRB response has no currency scale.');
        }

        if (! is_int($officialRate) && ! is_float($officialRate) && ! is_string($officialRate)) {
            throw new ProviderException('NBRB response has no official rate.');
        }

        try {
            return BigDecimal::of((string) $officialRate)
                ->dividedBy((string) $scale, DecimalCalculator::INTERNAL_SCALE, RoundingMode::HalfUp)
                ->__toString();
        } catch (Throwable $exception) {
            throw new ProviderException('NBRB response has invalid rate data.', previous: $exception);
        }
    }

    /** @param array<string, mixed> $record */
    private function publishedAt(array $record): ?DateTimeImmutable
    {
        $date = $record['Date'] ?? null;

        if (! is_string($date)) {
            return null;
        }

        try {
            return new DateTimeImmutable($date, new DateTimeZone('Europe/Minsk'));
        } catch (Throwable) {
            return null;
        }
    }
}
