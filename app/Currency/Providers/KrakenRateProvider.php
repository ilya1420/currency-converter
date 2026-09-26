<?php

namespace App\Currency\Providers;

use App\Currency\Contracts\RateProviderInterface;
use App\Currency\DTO\ExchangeRate;
use App\Currency\Enums\Currency;
use App\Currency\Enums\CurrencyType;
use App\Currency\Enums\RateSource;
use App\Currency\Exceptions\ProviderException;
use App\Currency\Exceptions\UnsupportedCurrencyPairException;
use DateTimeImmutable;
use Illuminate\Support\Facades\Http;

final class KrakenRateProvider implements RateProviderInterface
{
    public function __construct(private KrakenAssetMapper $mapper) {}

    public function supports(Currency $from, Currency $to): bool
    {
        if ($from->type() !== CurrencyType::CRYPTO || $to !== Currency::USD) {
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
            throw new UnsupportedCurrencyPairException("Kraken does not support {$from->value}/{$to->value}.");
        }

        $response = Http::baseUrl('https://api.kraken.com/0/public')
            ->acceptJson()->connectTimeout(3)->timeout(5)
            ->get('Ticker', ['pair' => $this->mapper->usdPair($from)]);

        if ($response->failed() || $response->json('error') !== []) {
            throw new ProviderException('Kraken is unavailable.');
        }

        $result = $response->json('result');
        if (! is_array($result) || count($result) !== 1 || ! is_array($ticker = reset($result)) || ! isset($ticker['c'][0])) {
            throw new ProviderException('Kraken returned an invalid ticker.');
        }

        return new ExchangeRate($from, $to, (string) $ticker['c'][0], RateSource::KRAKEN, new DateTimeImmutable);
    }
}
