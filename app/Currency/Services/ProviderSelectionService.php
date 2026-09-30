<?php

namespace App\Currency\Services;

use App\Currency\DTO\ProviderDefinition;
use App\Currency\Enums\ProviderCapability;
use App\Currency\Repositories\ProviderSelectionRepository;
use InvalidArgumentException;
use LogicException;

final class ProviderSelectionService
{
    public function __construct(
        private ProviderRegistry $registry,
        private ProviderSelectionRepository $selections,
    ) {}

    public function selected(ProviderCapability $capability): ProviderDefinition
    {
        return $this->configured($capability) ?? $this->defaultFor($capability);
    }

    public function configured(ProviderCapability $capability): ?ProviderDefinition
    {
        $providerId = $this->selections->find($capability);
        if ($providerId === null) {
            return null;
        }

        $provider = $this->registry->find($providerId);
        if ($provider === null || ! $provider->supports($capability)) {
            throw new LogicException("Stored provider [{$providerId}] is not registered for [{$capability->value}].");
        }

        return $provider;
    }

    public function select(ProviderCapability $capability, string $providerId): ProviderDefinition
    {
        $provider = $this->registry->find($providerId);
        if ($provider === null || ! $provider->supports($capability)) {
            throw new InvalidArgumentException("Provider [{$providerId}] does not support [{$capability->value}].");
        }

        $this->selections->save($capability, $providerId);

        return $provider;
    }

    public function reset(ProviderCapability $capability): ProviderDefinition
    {
        $this->selections->forget($capability);

        return $this->defaultFor($capability);
    }

    private function defaultFor(ProviderCapability $capability): ProviderDefinition
    {
        return $this->registry->forCapability($capability)[0]
            ?? throw new LogicException("No provider is registered for [{$capability->value}].");
    }
}
