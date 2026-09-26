<?php

namespace App\Currency\Services;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class DecimalCalculator
{
    public const INTERNAL_SCALE = 18;

    public function multiply(string $amount, string $rate): string
    {
        return BigDecimal::of($amount)
            ->multipliedBy($rate)
            ->toScale(self::INTERNAL_SCALE, RoundingMode::HalfUp)
            ->__toString();
    }

    public function divide(string $amount, string $divisor): string
    {
        return BigDecimal::of($amount)
            ->dividedBy($divisor, self::INTERNAL_SCALE, RoundingMode::HalfUp)
            ->__toString();
    }

    public function convertThroughBase(
        string $amount,
        string $sourceToBaseRate,
        string $targetToBaseRate,
    ): string {
        return BigDecimal::of($amount)
            ->multipliedBy($sourceToBaseRate)
            ->dividedBy($targetToBaseRate, self::INTERNAL_SCALE, RoundingMode::HalfUp)
            ->__toString();
    }
}
