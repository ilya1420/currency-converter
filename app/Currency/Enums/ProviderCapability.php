<?php

namespace App\Currency\Enums;

enum ProviderCapability: string
{
    case CATALOG = 'catalog';
    case FIAT_RATES = 'fiat_rates';
    case CRYPTO_RATES = 'crypto_rates';
    case FIAT_DAILY_CHANGES = 'fiat_daily_changes';
    case CRYPTO_DAILY_CHANGES = 'crypto_daily_changes';
    case FIAT_MARKET_DATA = 'fiat_market_data';
    case CRYPTO_MARKET_DATA = 'crypto_market_data';
}
