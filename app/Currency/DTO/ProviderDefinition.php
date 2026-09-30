<?php

namespace App\Currency\DTO;

use App\Currency\Enums\ProviderCapability;

final readonly class ProviderDefinition
{
    /**
     * @param  array<string, class-string>  $adapters
     */
    public function __construct(
        public string $id,
        public string $name,
        public array $adapters,
    ) {}

    /** @return list<ProviderCapability> */
    public function capabilities(): array
    {
        return array_map(ProviderCapability::from(...), array_keys($this->adapters));
    }

    public function supports(ProviderCapability $capability): bool
    {
        return isset($this->adapters[$capability->value]);
    }

    /** @return class-string|null */
    public function adapterFor(ProviderCapability $capability): ?string
    {
        return $this->adapters[$capability->value] ?? null;
    }
}
