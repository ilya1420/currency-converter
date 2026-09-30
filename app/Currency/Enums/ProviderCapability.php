<?php

namespace App\Currency\Enums;

enum ProviderCapability: string
{
    case CATALOG = 'catalog';
    case RATES = 'rates';
    case DAILY_CHANGES = 'daily_changes';
    case MARKET_DATA = 'market';
}
