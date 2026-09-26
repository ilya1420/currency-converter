<?php

namespace App\Currency\Services;

use App\Currency\DTO\ConversionResult;
use App\Currency\DTO\ExchangeRate;
use App\Currency\Enums\Currency;
use App\Currency\Enums\CurrencyType;
use App\Currency\Enums\RateSource;
use DateTimeImmutable;

final class ConversionService
{
    public function __construct(private RateService $rates, private DecimalCalculator $calculator) {}

    public function convert(string $amount, Currency $from, Currency $to, bool $forceRefresh = false): ConversionResult
    {
        if ($from === $to) {
            return new ConversionResult($from, $to, $amount, $amount, new DateTimeImmutable, false);
        }

        [$targetAmount, $ratesUsed] = match ([$from->type(), $to->type()]) {
            [CurrencyType::FIAT, CurrencyType::FIAT] => $this->fiatToFiat($amount, $from, $to, $forceRefresh),
            [CurrencyType::CRYPTO, CurrencyType::FIAT] => $this->cryptoToFiat($amount, $from, $to, $forceRefresh),
            [CurrencyType::FIAT, CurrencyType::CRYPTO] => $this->fiatToCrypto($amount, $from, $to, $forceRefresh),
            [CurrencyType::CRYPTO, CurrencyType::CRYPTO] => $this->cryptoToCrypto($amount, $from, $to, $forceRefresh),
        };

        return new ConversionResult(
            $from,
            $to,
            $amount,
            $targetAmount,
            min(array_map(static fn (ExchangeRate $rate): DateTimeImmutable => $rate->fetchedAt, $ratesUsed)),
            (bool) array_filter($ratesUsed, static fn (ExchangeRate $rate): bool => $rate->isStale),
            $ratesUsed,
        );
    }

    /** @return array{string, list<ExchangeRate>} */
    private function fiatToFiat(string $amount, Currency $from, Currency $to, bool $forceRefresh): array
    {
        $sourceRate = $this->fiatToByn($from, $forceRefresh);
        $targetRate = $this->fiatToByn($to, $forceRefresh);

        return [$this->calculator->convertThroughBase($amount, $sourceRate->rate, $targetRate->rate), [$sourceRate, $targetRate]];
    }

    /** @return array{string, list<ExchangeRate>} */
    private function cryptoToFiat(string $amount, Currency $from, Currency $to, bool $forceRefresh): array
    {
        $cryptoRate = $this->rates->getRate($from, Currency::USD, $forceRefresh);
        if ($to === Currency::USD) {
            return [$this->calculator->multiply($amount, $cryptoRate->rate), [$cryptoRate]];
        }
        $usdByn = $this->fiatToByn(Currency::USD, $forceRefresh);
        $targetByn = $this->fiatToByn($to, $forceRefresh);

        return [$this->calculator->convertThroughBase($this->calculator->multiply($amount, $cryptoRate->rate), $usdByn->rate, $targetByn->rate), [$cryptoRate, $usdByn, $targetByn]];
    }

    /** @return array{string, list<ExchangeRate>} */
    private function fiatToCrypto(string $amount, Currency $from, Currency $to, bool $forceRefresh): array
    {
        $sourceByn = $this->fiatToByn($from, $forceRefresh);
        $usdByn = $this->fiatToByn(Currency::USD, $forceRefresh);
        $cryptoRate = $this->rates->getRate($to, Currency::USD, $forceRefresh);
        $usd = $this->calculator->convertThroughBase($amount, $sourceByn->rate, $usdByn->rate);

        return [$this->calculator->divide($usd, $cryptoRate->rate), [$sourceByn, $usdByn, $cryptoRate]];
    }

    /** @return array{string, list<ExchangeRate>} */
    private function cryptoToCrypto(string $amount, Currency $from, Currency $to, bool $forceRefresh): array
    {
        $sourceRate = $this->rates->getRate($from, Currency::USD, $forceRefresh);
        $targetRate = $this->rates->getRate($to, Currency::USD, $forceRefresh);

        return [$this->calculator->convertThroughBase($amount, $sourceRate->rate, $targetRate->rate), [$sourceRate, $targetRate]];
    }

    private function fiatToByn(Currency $currency, bool $forceRefresh): ExchangeRate
    {
        return $currency === Currency::BYN
            ? new ExchangeRate(Currency::BYN, Currency::BYN, '1', RateSource::NBRB, new DateTimeImmutable)
            : $this->rates->getRate($currency, Currency::BYN, $forceRefresh);
    }
}
