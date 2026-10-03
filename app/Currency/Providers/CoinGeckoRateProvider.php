<?php

namespace App\Currency\Providers;

use App\Currency\Contracts\RateProviderInterface;
use App\Currency\DTO\ExchangeRate;
use App\Currency\Enums\Currency;
use App\Currency\Enums\CurrencyType;
use App\Currency\Enums\RateSource;
use App\Currency\Exceptions\ProviderResponseException;
use App\Currency\Exceptions\UnsupportedCurrencyPairException;
use App\Currency\Services\CurrencyCache;
use App\Currency\Services\ExternalApiClientFactory;
use DateTimeImmutable;

final class CoinGeckoRateProvider implements RateProviderInterface
{
    public function __construct(private CurrencyCache $cache, private ExternalApiClientFactory $clients) {}

    public function source(): RateSource
    {
        return RateSource::COINGECKO;
    }

    public function supports(Currency $from, Currency $to): bool
    {
        return $from->type === CurrencyType::CRYPTO && $from->coinGeckoId !== null && $to->type === CurrencyType::FIAT && $to->code === 'USD';
    }

    public function getRate(Currency $from, Currency $to, bool $forceRefresh = false): ExchangeRate
    {
        if (! $this->supports($from, $to)) {
            throw new UnsupportedCurrencyPairException("CoinGecko does not support {$from->code}/{$to->code}.");
        }
        $coinGeckoId = $from->coinGeckoId;
        $cacheKey = "coingecko:price:v1:{$coinGeckoId}";
        if ($forceRefresh) {
            $this->cache->forget($cacheKey);
        }
        $cached = $this->cache->remember(
            $cacheKey,
            now()->addSeconds((int) config('currency.coingecko.rate_ttl_seconds')),
            function () use ($coinGeckoId): array {
                $response = $this->clients->get('coingecko', 'simple/price', ['ids' => $coinGeckoId, 'vs_currencies' => 'usd']);
                if (! is_array($response->json())) {
                    throw new ProviderResponseException('CoinGecko returned an invalid price.');
                }

                $payload = $response->json();
                $this->price($payload, $coinGeckoId);

                return ['payload' => $payload, 'fetchedAt' => now()->toIso8601String()];
            },
        );
        try {
            $price = $this->price($cached['payload'] ?? null, $coinGeckoId);
        } catch (ProviderResponseException $exception) {
            $this->cache->forget($cacheKey);

            throw $exception;
        }

        return new ExchangeRate($from, $to, $price, RateSource::COINGECKO, new DateTimeImmutable($cached['fetchedAt'] ?? 'now'));
    }

    private function price(mixed $payload, string $coinGeckoId): string
    {
        if (! is_array($payload) || ! is_array($payload[$coinGeckoId] ?? null)) {
            throw new ProviderResponseException('CoinGecko returned an invalid price.');
        }

        return ExchangeRate::positiveDecimal($payload[$coinGeckoId]['usd'] ?? null)->__toString();
    }
}
