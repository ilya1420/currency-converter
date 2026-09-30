<?php

namespace App\Currency\Providers;

use App\Currency\Contracts\RateProviderInterface;
use App\Currency\DTO\ExchangeRate;
use App\Currency\Enums\Currency;
use App\Currency\Enums\CurrencyType;
use App\Currency\Enums\RateSource;
use App\Currency\Exceptions\ProviderException;
use App\Currency\Exceptions\ProviderRateLimitException;
use App\Currency\Exceptions\ProviderResponseException;
use App\Currency\Exceptions\UnsupportedCurrencyPairException;
use App\Currency\Services\DecimalCalculator;
use App\Currency\Services\ExternalApiClientFactory;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Throwable;

final class NbrbRateProvider implements RateProviderInterface
{
    public function __construct(private ?ExternalApiClientFactory $clients = null) {}

    /** @var array<mixed>|null */
    private ?array $catalog = null;

    private ?DateTimeImmutable $catalogFetchedAt = null;

    public function source(): RateSource
    {
        return RateSource::NBRB;
    }

    public function supports(Currency $from, Currency $to): bool
    {
        return $from->code !== 'BYN'
            && $from->type === CurrencyType::FIAT
            && $to->type === CurrencyType::FIAT
            && $to->code === 'BYN';
    }

    public function getRate(Currency $from, Currency $to): ExchangeRate
    {
        if (! $this->supports($from, $to)) {
            throw new UnsupportedCurrencyPairException("NBRB does not support {$from->code}/{$to->code}.");
        }

        try {
            $records = $this->catalog();
        } catch (ConnectionException $exception) {
            throw new ProviderException('NBRB is unavailable.', previous: $exception);
        }

        $record = $this->findCurrency($records, $from);

        if ($record === null) {
            throw new ProviderException("NBRB did not return {$from->code}.");
        }

        $rate = $this->normalizeRate($record);

        return new ExchangeRate(
            $from,
            Currency::fiat('BYN'),
            $rate,
            RateSource::NBRB,
            new DateTimeImmutable,
            $this->publishedAt($record),
        );
    }

    private function request(): PendingRequest
    {
        return ($this->clients ??= app(ExternalApiClientFactory::class))->for('nbrb');
    }

    /** @return array<mixed> */
    private function catalog(): array
    {
        if ($this->catalog !== null && $this->catalogFetchedAt?->modify('+30 minutes') > new DateTimeImmutable) {
            return $this->catalog;
        }

        $response = $this->request()->get('rates', ['periodicity' => 0]);
        if ($response->status() === 429) {
            throw new ProviderRateLimitException('NBRB rate limit reached.', is_numeric($response->header('Retry-After')) ? (int) $response->header('Retry-After') : null);
        }
        if ($response->failed() || ! is_array($response->json())) {
            throw new ProviderResponseException("NBRB returned HTTP {$response->status()}.");
        }

        $this->catalog = $response->json();
        $this->catalogFetchedAt = new DateTimeImmutable;

        return $this->catalog;
    }

    /** @param array<mixed> $records */
    private function findCurrency(array $records, Currency $currency): ?array
    {
        foreach ($records as $record) {
            if (is_array($record) && ($record['Cur_Abbreviation'] ?? null) === $currency->code) {
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
