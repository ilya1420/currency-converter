<?php

namespace App\Currency\DTO;

use App\Currency\Enums\Currency;
use DateTimeImmutable;

final readonly class ConversionResult
{
    /**
     * @param  list<ExchangeRate>  $ratesUsed
     */
    public function __construct(
        public Currency $from,
        public Currency $to,
        public string $sourceAmount,
        public string $targetAmount,
        public DateTimeImmutable $rateUpdatedAt,
        public bool $isStale,
        public array $ratesUsed = [],
        public bool $isFallback = false,
    ) {}
}
