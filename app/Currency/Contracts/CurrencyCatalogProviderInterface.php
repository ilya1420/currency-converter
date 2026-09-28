<?php

namespace App\Currency\Contracts;

use App\Currency\DTO\CurrencyDefinition;

interface CurrencyCatalogProviderInterface
{
    /** @return list<CurrencyDefinition> */
    public function currencies(): array;
}
