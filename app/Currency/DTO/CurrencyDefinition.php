<?php

namespace App\Currency\DTO;

use App\Currency\Enums\CurrencyType;

final readonly class CurrencyDefinition
{
    public function __construct(
        public string $code,
        public CurrencyType $type,
        public ?string $providerSymbol = null,
        public ?string $name = null,
        public ?string $coinGeckoId = null,
        public ?string $group = null,
    ) {}
}
