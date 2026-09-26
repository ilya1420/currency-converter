<?php

namespace App\Currency\DTO;

use App\Currency\Enums\Currency;
use App\Currency\Enums\RateSource;
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
    ) {}
}
