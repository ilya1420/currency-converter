<?php

namespace App\Currency\Contracts;

use App\Currency\Enums\Currency;

interface MarketDataProviderInterface
{
    public function source(): string;

    public function supports(Currency $currency): bool;

    /** @return array<string, mixed> */
    public function chart(Currency $currency, int $interval): array;
}
