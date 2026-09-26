<?php

namespace App\Currency\Contracts;

use App\Currency\DTO\ExchangeRate;
use App\Currency\Enums\Currency;

interface RateProviderInterface
{
    public function supports(Currency $from, Currency $to): bool;

    public function getRate(Currency $from, Currency $to): ExchangeRate;
}
