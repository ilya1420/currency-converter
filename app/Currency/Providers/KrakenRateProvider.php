<?php

namespace App\Currency\Providers;

use App\Currency\Contracts\RateProviderInterface;
use App\Currency\DTO\ExchangeRate;
use App\Currency\Enums\Currency;
use App\Currency\Enums\CurrencyType;
use App\Currency\Enums\RateSource;
use App\Currency\Exceptions\ProviderRateLimitException;
use App\Currency\Exceptions\ProviderResponseException;
use App\Currency\Exceptions\ProviderTimeoutException;
use App\Currency\Exceptions\UnsupportedCurrencyPairException;
use App\Currency\Services\ExternalApiClientFactory;
use DateTimeImmutable;
use Illuminate\Http\Client\ConnectionException;

final class KrakenRateProvider implements RateProviderInterface
{
    public function __construct(private KrakenAssetMapper $mapper, private ?ExternalApiClientFactory $clients = null) {}

    public function source(): RateSource
    {
        return RateSource::KRAKEN;
    }

    public function supports(Currency $from, Currency $to): bool
    {
        if ($from->type !== CurrencyType::CRYPTO || $to->type !== CurrencyType::FIAT || $to->code !== 'USD') {
            return false;
        }

        try {
            $this->mapper->usdPair($from);

            return true;
        } catch (UnsupportedCurrencyPairException) {
            return false;
        }
    }

    public function getRate(Currency $from, Currency $to): ExchangeRate
    {
        if (! $this->supports($from, $to)) {
            throw new UnsupportedCurrencyPairException("Kraken does not support {$from->code}/{$to->code}.");
        }

        try {
            $response = ($this->clients ??= app(ExternalApiClientFactory::class))->for('kraken')->get('Ticker', ['pair' => $this->mapper->usdPair($from)]);
        } catch (ConnectionException $exception) {
            throw new ProviderTimeoutException('Kraken request timed out.', $exception);
        }

        if ($response->status() === 429) {
            throw new ProviderRateLimitException('Kraken rate limit reached.', $this->retryAfter($response->header('Retry-After')));
        }
        if ($response->failed() || $response->json('error') !== []) {
            throw new ProviderResponseException('Kraken returned an error response.');
        }

        $result = $response->json('result');
        if (! is_array($result) || count($result) !== 1 || ! is_array($ticker = reset($result)) || ! isset($ticker['c'][0])) {
            throw new ProviderResponseException('Kraken returned an invalid ticker.');
        }

        return new ExchangeRate($from, $to, (string) $ticker['c'][0], RateSource::KRAKEN, new DateTimeImmutable);
    }

    private function retryAfter(?string $value): ?int
    {
        return is_numeric($value) ? max(0, (int) $value) : null;
    }
}
