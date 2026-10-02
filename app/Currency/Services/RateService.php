<?php

namespace App\Currency\Services;

use App\Currency\Contracts\RateProviderInterface;
use App\Currency\DTO\ExchangeRate;
use App\Currency\Enums\Currency;
use App\Currency\Enums\CurrencyType;
use App\Currency\Enums\ProviderCapability;
use App\Currency\Enums\RateSource;
use App\Currency\Exceptions\ProviderException;
use App\Currency\Exceptions\RateUnavailableException;
use App\Currency\Repositories\ExchangeRateRepository;
use DateInterval;
use DateTimeImmutable;

final class RateService
{
    /** @var array<string, ExchangeRate> */
    private array $resolvedRates = [];

    /** @var array<class-string<RateProviderInterface>, ProviderException> */
    private array $providerFailures = [];

    /** @param iterable<RateProviderInterface> $providers */
    public function __construct(
        private ExchangeRateRepository $rates,
        private iterable $providers,
        private ProviderSelectionService $selections,
    ) {}

    public function getRate(Currency $from, Currency $to, bool $forceRefresh = false): ExchangeRate
    {
        $key = "{$from->type->value}:{$from->code}:{$to->type->value}:{$to->code}";
        if (! $forceRefresh && isset($this->resolvedRates[$key])) {
            return $this->resolvedRates[$key];
        }

        $capability = $from->type === CurrencyType::CRYPTO
            ? ProviderCapability::CRYPTO_RATES
            : ProviderCapability::FIAT_RATES;
        $configuredProvider = $this->selections->configured($capability);
        $providers = $configuredProvider === null
            ? $this->providers
            : $this->selectedProvider($configuredProvider->adapterFor($capability));

        foreach ($providers as $provider) {
            if ($configuredProvider === null && ! $this->selections->isAvailableForAutomaticSelection($provider::class)) {
                continue;
            }

            if (! $provider->supports($from, $to)) {
                if ($configuredProvider !== null) {
                    throw new RateUnavailableException("Selected provider [{$configuredProvider->id}] does not support {$from->code}/{$to->code}.", $configuredProvider->id, 'unsupported_pair');
                }

                continue;
            }
            $source = $provider->source();
            $freshAfter = (new DateTimeImmutable)->sub(new DateInterval('PT'.$this->ttl($source).'S'));
            if (! $forceRefresh && ($fresh = $this->rates->findFresh($source, $from, $to, $freshAfter))) {
                return $this->resolvedRates[$key] = $fresh;
            }

            if (isset($this->providerFailures[$provider::class])) {
                $cached = $this->rates->findLatest($source, $from, $to);
                if ($cached && $cached->fetchedAt >= (new DateTimeImmutable)->sub(new DateInterval('PT'.$this->maxStaleAge($source).'S'))) {
                    return $this->resolvedRates[$key] = new ExchangeRate($cached->from, $cached->to, $cached->rate, $cached->source, $cached->fetchedAt, $cached->publishedAt, true);
                }

                throw new RateUnavailableException("No rate is available from {$source->value} for {$from->code}/{$to->code}.", $source->value, previous: $this->providerFailures[$provider::class]);
            }

            try {
                return $this->resolvedRates[$key] = $this->rates->save($provider->getRate($from, $to));
            } catch (ProviderException $exception) {
                $this->providerFailures[$provider::class] = $exception;
                $cached = $this->rates->findLatest($source, $from, $to);
                if ($cached && $cached->fetchedAt >= (new DateTimeImmutable)->sub(new DateInterval('PT'.$this->maxStaleAge($source).'S'))) {
                    return $this->resolvedRates[$key] = new ExchangeRate($cached->from, $cached->to, $cached->rate, $cached->source, $cached->fetchedAt, $cached->publishedAt, true);
                }

                throw new RateUnavailableException("No rate is available from {$source->value} for {$from->code}/{$to->code}.", $source->value, previous: $exception);
            }
        }
        throw new RateUnavailableException("No rate is available for {$from->code}/{$to->code}.");
    }

    /** @return iterable<RateProviderInterface> */
    private function selectedProvider(?string $adapter): iterable
    {
        if ($adapter !== null) {
            foreach ($this->providers as $provider) {
                if ($provider::class === $adapter) {
                    return [$provider];
                }
            }
        }

        throw new RateUnavailableException('The selected rate provider is not available in this application build.');
    }

    private function ttl(RateSource $source): int
    {
        return (int) config("currency.{$source->value}.rate_ttl_seconds");
    }

    private function maxStaleAge(RateSource $source): int
    {
        return (int) config("currency.{$source->value}.max_stale_age_seconds");
    }
}
