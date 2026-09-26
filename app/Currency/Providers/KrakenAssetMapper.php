<?php

namespace App\Currency\Providers;

use App\Currency\Enums\Currency;
use App\Currency\Exceptions\UnsupportedCurrencyPairException;

final class KrakenAssetMapper
{
    /** @var array<string, string> */
    private const USD_PAIRS = [
        'BTC' => 'XBTUSD',
        'ETH' => 'ETHUSD',
        'USDT' => 'USDTUSD',
        'SOL' => 'SOLUSD',
        'XRP' => 'XRPUSD',
    ];

    public function usdPair(Currency $currency): string
    {
        return self::USD_PAIRS[$currency->value]
            ?? throw new UnsupportedCurrencyPairException("Kraken USD pair is unavailable for {$currency->value}.");
    }
}
