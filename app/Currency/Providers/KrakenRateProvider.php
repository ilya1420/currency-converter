<?php

namespace App\Currency\Providers;

use App\Currency\Contracts\RateProviderInterface;
use App\Currency\DTO\ExchangeRate;
use App\Currency\Enums\Currency;
use App\Currency\Enums\CurrencyType;
use App\Currency\Enums\RateSource;
use App\Currency\Exceptions\ProviderResponseException;
use App\Currency\Exceptions\UnsupportedCurrencyPairException;
use App\Currency\Services\ExternalApiClientFactory;
use DateTimeImmutable;

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

    public function getRate(Currency $from, Currency $to, bool $forceRefresh = false): ExchangeRate
    {
        if (! $this->supports($from, $to)) {
            throw new UnsupportedCurrencyPairException("Kraken does not support {$from->code}/{$to->code}.");
        }

        $response = ($this->clients ??= app(ExternalApiClientFactory::class))->get('kraken', 'Ticker', ['pair' => $this->mapper->usdPair($from)]);

        if ($response->json('error') !== []) {
            throw new ProviderResponseException('Kraken returned an error response.');
        }

        $result = $response->json('result');
        if (! is_array($result) || count($result) !== 1 || ! is_array($ticker = reset($result))
            || ! is_array($ticker['c'] ?? null) || ! array_key_exists(0, $ticker['c'])) {
            throw new ProviderResponseException('Kraken returned an invalid ticker.');
        }

        $price = ExchangeRate::positiveDecimal($ticker['c'][0])->__toString();

        return new ExchangeRate($from, $to, $price, RateSource::KRAKEN, new DateTimeImmutable);
    }
}
