<?php

namespace App\Currency\Enums;

enum ProviderCapability: string
{
    case CATALOG = 'catalog';
    case FIAT_RATES = 'fiat_rates';
    case CRYPTO_RATES = 'crypto_rates';
    case DAILY_CHANGES = 'daily_changes';
    case MARKET_DATA = 'market';
}
