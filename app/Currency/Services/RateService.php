<?php

namespace App\Currency\Services;

use App\Currency\Contracts\RateProviderInterface;
use App\Currency\DTO\ExchangeRate;
use App\Currency\Enums\Currency;
use App\Currency\Enums\RateSource;
use App\Currency\Exceptions\ProviderException;
use App\Currency\Exceptions\RateUnavailableException;
use App\Currency\Exceptions\UnsupportedCurrencyPairException;
use App\Currency\Repositories\ExchangeRateRepository;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Support\Facades\Log;

final class RateService
{
    /** @param iterable<RateProviderInterface> $providers */
    public function __construct(private ExchangeRateRepository $rates, private iterable $providers) {}

    public function getRate(Currency $from, Currency $to, bool $forceRefresh = false): ExchangeRate
    {
        $provider = $this->providerFor($from, $to);
        $source = $provider->source();
        $freshAfter = (new DateTimeImmutable)->sub(new DateInterval('PT'.$this->ttl($source).'S'));

        if (! $forceRefresh && ($fresh = $this->rates->findFresh($source, $from, $to, $freshAfter))) {
            return $fresh;
        }

        try {
            return $this->rates->save($provider->getRate($from, $to));
        } catch (ProviderException $exception) {
            $cached = $this->rates->findLatest($source, $from, $to);
            if ($cached && $cached->fetchedAt >= (new DateTimeImmutable)->sub(new DateInterval('PT'.$this->maxStaleAge($source).'S'))) {
                Log::warning('Using stale exchange rate cache.', ['provider' => $source->value, 'pair' => "{$from->value}/{$to->value}"]);

                return new ExchangeRate($cached->from, $cached->to, $cached->rate, $cached->source, $cached->fetchedAt, $cached->publishedAt, true);
            }
            throw new RateUnavailableException("No rate is available for {$from->value}/{$to->value}.", previous: $exception);
        }
    }

    private function providerFor(Currency $from, Currency $to): RateProviderInterface
    {
        foreach ($this->providers as $provider) {
            if ($provider->supports($from, $to)) {
                return $provider;
            }
        }
        throw new UnsupportedCurrencyPairException("No provider supports {$from->value}/{$to->value}.");
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
