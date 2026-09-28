<?php

namespace App\Currency\Contracts;

use App\Currency\Enums\Currency;

interface DailyChangeProviderInterface
{
    public function supports(Currency $currency): bool;

    /** @param list<Currency> $currencies @return array<string, ?float> */
    public function dailyChanges(array $currencies): array;
}
