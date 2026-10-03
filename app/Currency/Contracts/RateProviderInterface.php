<?php

namespace App\Currency\Contracts;

use App\Currency\DTO\ExchangeRate;
use App\Currency\Enums\Currency;
use App\Currency\Enums\RateSource;

interface RateProviderInterface
{
    public function source(): RateSource;

    public function supports(Currency $from, Currency $to): bool;

    public function getRate(Currency $from, Currency $to, bool $forceRefresh = false): ExchangeRate;
}
