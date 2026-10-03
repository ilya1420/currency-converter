<?php

namespace App\Currency\DTO;

use App\Currency\Enums\Currency;
use App\Currency\Enums\RateSource;
use App\Currency\Exceptions\ProviderResponseException;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use DateTimeImmutable;

final readonly class ExchangeRate
{
    public function __construct(
        public Currency $from,
        public Currency $to,
        public string $rate,
        public RateSource $source,
        public DateTimeImmutable $fetchedAt,
        public ?DateTimeImmutable $publishedAt = null,
        public bool $isStale = false,
        public ?string $fallbackReason = null,
    ) {
        try {
            $positive = BigDecimal::of($rate)->isPositive();
        } catch (MathException $exception) {
            throw new ProviderResponseException('Provider returned an invalid exchange rate.', previous: $exception);
        }

        if (! $positive) {
            throw new ProviderResponseException('Provider returned a non-positive exchange rate.');
        }
    }
}
