<?php

namespace App\Currency\DTO;

use App\Currency\Enums\Currency;
use App\Currency\Enums\RateSource;
use App\Currency\Exceptions\ProviderResponseException;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\NumberSyntax;
use DateTimeImmutable;

final readonly class ExchangeRate
{
    private const MAX_DIGITS = 1024;

    public function __construct(
        public Currency $from,
        public Currency $to,
        public string $rate,
        public RateSource $source,
        public DateTimeImmutable $fetchedAt,
        public ?DateTimeImmutable $rateDate = null,
        public bool $isStale = false,
        public ?string $fallbackReason = null,
        public bool $isFallback = false,
    ) {
        self::positiveDecimal($rate);
    }

    public static function positiveDecimal(mixed $value): BigDecimal
    {
        if ((! is_string($value) && ! is_int($value) && ! is_float($value))
            || (is_float($value) && ! is_finite($value))) {
            throw new ProviderResponseException('Provider returned an invalid exchange rate.');
        }

        try {
            $decimal = BigDecimal::parse((string) $value, NumberSyntax::SCIENTIFIC, self::MAX_DIGITS);
        } catch (MathException $exception) {
            throw new ProviderResponseException('Provider returned an invalid exchange rate.', previous: $exception);
        }

        if (! $decimal->isPositive()) {
            throw new ProviderResponseException('Provider returned a non-positive exchange rate.');
        }

        return $decimal;
    }
}
