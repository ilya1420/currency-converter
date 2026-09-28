<?php

namespace App\Currency\Services;

use App\Currency\Contracts\RateProviderInterface;
use App\Currency\DTO\ExchangeRate;
use App\Currency\Enums\Currency;
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

    /** @param iterable<RateProviderInterface> $providers */
    public function __construct(private ExchangeRateRepository $rates, private iterable $providers) {}

    public function getRate(Currency $from, Currency $to, bool $forceRefresh = false): ExchangeRate
    {
        $key = "{$from->type->value}:{$from->code}:{$to->type->value}:{$to->code}";
        if (! $forceRefresh && isset($this->resolvedRates[$key])) {
            return $this->resolvedRates[$key];
        }

        $staleRates = [];
        foreach ($this->providers as $provider) {
            if (! $provider->supports($from, $to)) {
                continue;
            }
            $source = $provider->source();
            $freshAfter = (new DateTimeImmutable)->sub(new DateInterval('PT'.$this->ttl($source).'S'));
            if (! $forceRefresh && ($fresh = $this->rates->findFresh($source, $from, $to, $freshAfter))) {
                return $this->resolvedRates[$key] = $fresh;
            }
            try {
                return $this->resolvedRates[$key] = $this->rates->save($provider->getRate($from, $to));
            } catch (ProviderException $exception) {
                $cached = $this->rates->findLatest($source, $from, $to);
                if ($cached && $cached->fetchedAt >= (new DateTimeImmutable)->sub(new DateInterval('PT'.$this->maxStaleAge($source).'S'))) {
                    $staleRates[] = $cached;
                }
            }
        }
        if ($staleRates !== []) {
            $cached = $staleRates[0];

            return $this->resolvedRates[$key] = new ExchangeRate($cached->from, $cached->to, $cached->rate, $cached->source, $cached->fetchedAt, $cached->publishedAt, true);
        }
        throw new RateUnavailableException("No rate is available for {$from->code}/{$to->code}.");
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
