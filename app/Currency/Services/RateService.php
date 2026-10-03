<?php

namespace App\Currency\Services;

use App\Currency\Contracts\RateProviderInterface;
use App\Currency\DTO\ExchangeRate;
use App\Currency\Enums\Currency;
use App\Currency\Enums\CurrencyType;
use App\Currency\Enums\ProviderCapability;
use App\Currency\Enums\RateSource;
use App\Currency\Exceptions\ProviderException;
use App\Currency\Exceptions\ProviderRateLimitException;
use App\Currency\Exceptions\ProviderTimeoutException;
use App\Currency\Exceptions\RateUnavailableException;
use App\Currency\Repositories\ExchangeRateRepository;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

final class RateService
{
    /** @var array<string, ExchangeRate> */
    private array $resolvedRates = [];

    /** @var array<string, true> */
    private array $refreshAttempts = [];

    /** @var array<class-string<RateProviderInterface>, ProviderException> */
    private array $providerFailures = [];

    /** @var array<string, true> */
    private array $refreshedCatalogs = [];

    /** @param iterable<RateProviderInterface> $providers */
    public function __construct(
        private ExchangeRateRepository $rates,
        private iterable $providers,
        private ProviderSelectionService $selections,
    ) {}

    public function getRate(Currency $from, Currency $to, bool $forceRefresh = false): ExchangeRate
    {
        $key = "{$from->type->value}:{$from->code}:{$to->type->value}:{$to->code}";
        if (isset($this->resolvedRates[$key]) && (! $forceRefresh || isset($this->refreshAttempts[$key]))) {
            return $this->resolvedRates[$key];
        }

        $capability = $from->type === CurrencyType::CRYPTO
            ? ProviderCapability::CRYPTO_RATES
            : ProviderCapability::FIAT_RATES;
        $configuredProvider = $this->selections->configured($capability);
        try {
            $providers = $this->selections->candidates($capability, $this->providers);
        } catch (ProviderException $exception) {
            throw new RateUnavailableException('The selected rate provider is not available.', $configuredProvider?->id, previous: $exception);
        }

        foreach ($providers as $provider) {
            if (! $provider->supports($from, $to)) {
                if ($configuredProvider !== null) {
                    throw new RateUnavailableException("Selected provider [{$configuredProvider->id}] does not support {$from->code}/{$to->code}.", $configuredProvider->id, 'unsupported_pair');
                }

                continue;
            }
            $source = $provider->source();
            $cached = $this->rates->findLatest($source, $from, $to);
            $cacheIsFresh = $cached !== null && $this->isFresh($source, $cached);
            $officialRateIsCurrent = $source === RateSource::NBRB && $cached !== null && $this->isCurrentOfficialRate($cached);
            if ($cacheIsFresh && (! $forceRefresh || $officialRateIsCurrent)) {
                return $this->resolvedRates[$key] = $cached;
            }

            if (isset($this->providerFailures[$provider::class])) {
                $cached = $this->rates->findLatest($source, $from, $to);
                if ($cached && $cached->fetchedAt >= (new DateTimeImmutable)->sub(new DateInterval('PT'.$this->maxStaleAge($source).'S'))) {
                    return $this->resolvedRates[$key] = $this->fallback($cached, $this->providerFailures[$provider::class]);
                }

                throw new RateUnavailableException("No rate is available from {$source->value} for {$from->code}/{$to->code}.", $source->value, previous: $this->providerFailures[$provider::class]);
            }

            try {
                $this->refreshAttempts[$key] = true;
                $refreshProviderCache = $forceRefresh || ! $cacheIsFresh;
                if ($source === RateSource::NBRB && isset($this->refreshedCatalogs[$source->value])) {
                    $refreshProviderCache = false;
                }
                if ($source === RateSource::NBRB) {
                    $this->refreshedCatalogs[$source->value] = true;
                }

                return $this->resolvedRates[$key] = $this->rates->save($provider->getRate($from, $to, $refreshProviderCache));
            } catch (ProviderException $exception) {
                $this->providerFailures[$provider::class] = $exception;
                $cached = $this->rates->findLatest($source, $from, $to);
                if ($cached && $cached->fetchedAt >= (new DateTimeImmutable)->sub(new DateInterval('PT'.$this->maxStaleAge($source).'S'))) {
                    return $this->resolvedRates[$key] = $this->fallback($cached, $exception);
                }

                throw new RateUnavailableException("No rate is available from {$source->value} for {$from->code}/{$to->code}.", $source->value, previous: $exception);
            }
        }
        throw new RateUnavailableException("No rate is available for {$from->code}/{$to->code}.");
    }

    private function ttl(RateSource $source): int
    {
        return (int) config("currency.{$source->value}.rate_ttl_seconds");
    }

    private function isFresh(RateSource $source, ExchangeRate $rate): bool
    {
        $now = DateTimeImmutable::createFromInterface(now());
        $freshAfter = $now->sub(new DateInterval('PT'.$this->ttl($source).'S'));
        if ($source !== RateSource::NBRB || $rate->rateDate === null) {
            return $rate->fetchedAt >= $freshAfter;
        }

        return $this->isCurrentOfficialRate($rate);
    }

    private function isCurrentOfficialRate(ExchangeRate $rate): bool
    {
        if ($rate->source !== RateSource::NBRB || $rate->rateDate === null) {
            return false;
        }

        $timezone = new DateTimeZone('Europe/Minsk');
        $today = DateTimeImmutable::createFromInterface(now())->setTimezone($timezone)->format('Y-m-d');

        return $rate->rateDate->setTimezone($timezone)->format('Y-m-d') === $today;
    }

    private function fallback(ExchangeRate $rate, ProviderException $exception): ExchangeRate
    {
        $reason = match (true) {
            $exception instanceof ProviderRateLimitException => 'provider_rate_limited',
            $exception instanceof ProviderTimeoutException => 'provider_timeout',
            default => 'provider_unavailable',
        };

        return new ExchangeRate(
            $rate->from,
            $rate->to,
            $rate->rate,
            $rate->source,
            $rate->fetchedAt,
            $rate->rateDate,
            $rate->source === RateSource::NBRB ? ! $this->isCurrentOfficialRate($rate) : true,
            $reason,
            true,
        );
    }

    private function maxStaleAge(RateSource $source): int
    {
        return (int) config("currency.{$source->value}.max_stale_age_seconds");
    }
}
