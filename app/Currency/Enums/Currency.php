<?php

namespace App\Currency\Enums;

final readonly class Currency
{
    public function __construct(
        public string $code,
        public CurrencyType $type,
        public ?string $providerSymbol = null,
        public ?string $name = null,
        public ?string $coinGeckoId = null,
        public ?string $group = null,
    ) {}

    public static function fiat(string $code): self
    {
        return new self(strtoupper($code), CurrencyType::FIAT);
    }

    public static function crypto(string $code, ?string $providerSymbol = null): self
    {
        return new self(strtoupper($code), CurrencyType::CRYPTO, $providerSymbol);
    }

    public function equals(self $other): bool
    {
        return $this->code === $other->code && $this->type === $other->type;
    }
}
