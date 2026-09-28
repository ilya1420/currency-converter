<?php

namespace App\Currency\Services;

use App\Currency\Enums\Currency;
use App\Currency\Enums\CurrencyType;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class AmountFormatter
{
    public function format(string $amount, Currency $currency): string
    {
        $scale = $currency->type === CurrencyType::FIAT ? 2 : 8;
        $formatted = BigDecimal::of($amount)->toScale($scale, RoundingMode::HalfUp)->__toString();

        if ($currency->type === CurrencyType::CRYPTO) {
            $formatted = rtrim(rtrim($formatted, '0'), '.');
        }

        [$whole, $fraction] = array_pad(explode('.', $formatted, 2), 2, null);
        $whole = preg_replace('/(?<!^)(?=(\d{3})+$)/', ' ', $whole) ?? $whole;

        return $fraction === null ? $whole : "{$whole}.{$fraction}";
    }
}
