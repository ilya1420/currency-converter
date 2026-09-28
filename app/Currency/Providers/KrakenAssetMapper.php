<?php

namespace App\Currency\Providers;

use App\Currency\Enums\Currency;
use App\Currency\Exceptions\UnsupportedCurrencyPairException;

final class KrakenAssetMapper
{
    public function usdPair(Currency $currency): string
    {
        return $currency->providerSymbol
            ?? throw new UnsupportedCurrencyPairException("Kraken USD pair is unavailable for {$currency->code}.");
    }
}
