<?php

namespace App\Currency\Services;

use App\Currency\DTO\ProviderDefinition;
use App\Currency\Enums\ProviderCapability;

final class ProviderRegistry
{
    /** @var array<string, ProviderDefinition> */
    private array $providers = [];

    /**
     * @param  array<string, array{name: string, adapters: array<string, class-string>}>  $definitions
     * @param  array<string, list<string>>  $priorities
     */
    public function __construct(array $definitions, private array $priorities = [])
    {
        foreach ($definitions as $id => $definition) {
            $this->providers[$id] = new ProviderDefinition($id, $definition['name'], $definition['adapters']);
        }
    }

    /** @return list<ProviderDefinition> */
    public function all(): array
    {
        return array_values($this->providers);
    }

    public function find(string $id): ?ProviderDefinition
    {
        return $this->providers[$id] ?? null;
    }

    /** @return list<ProviderDefinition> */
    public function forCapability(ProviderCapability $capability): array
    {
        $providers = array_values(array_filter(
            $this->providers,
            static fn (ProviderDefinition $provider): bool => $provider->supports($capability),
        ));
        $priority = array_flip($this->priorities[$capability->value] ?? []);

        usort($providers, static function (ProviderDefinition $first, ProviderDefinition $second) use ($priority): int {
            return ($priority[$first->id] ?? PHP_INT_MAX) <=> ($priority[$second->id] ?? PHP_INT_MAX);
        });

        return $providers;
    }
}
