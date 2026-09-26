<?php

namespace App\Currency\Enums;

enum Currency: string
{
    case BYN = 'BYN';
    case USD = 'USD';
    case EUR = 'EUR';
    case PLN = 'PLN';
    case GBP = 'GBP';
    case CNY = 'CNY';
    case RUB = 'RUB';
    case UAH = 'UAH';

    case BTC = 'BTC';
    case ETH = 'ETH';
    case USDT = 'USDT';
    case SOL = 'SOL';
    case XRP = 'XRP';

    public function type(): CurrencyType
    {
        return match ($this) {
            self::BYN,
            self::USD,
            self::EUR,
            self::PLN,
            self::GBP,
            self::CNY,
            self::RUB,
            self::UAH => CurrencyType::FIAT,
            self::BTC,
            self::ETH,
            self::USDT,
            self::SOL,
            self::XRP => CurrencyType::CRYPTO,
        };
    }
}
