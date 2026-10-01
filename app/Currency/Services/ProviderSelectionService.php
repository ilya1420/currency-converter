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
        private ProviderCredentialService $credentials,
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

        if ((bool) config("currency.providers.registry.{$providerId}.requires_api_key") && ! $this->credentials->isConfigured($providerId)) {
            throw new InvalidArgumentException("Provider [{$providerId}] requires a valid API key.");
        }

        $this->selections->save($capability, $providerId);

        return $provider;
    }

    public function reset(ProviderCapability $capability): ProviderDefinition
    {
        $this->selections->forget($capability);

        return $this->defaultFor($capability);
    }

    public function isAvailableForAutomaticSelection(string $adapter): bool
    {
        foreach ($this->registry->all() as $provider) {
            if (! in_array($adapter, $provider->adapters, true)) {
                continue;
            }

            return ! (bool) config("currency.providers.registry.{$provider->id}.requires_api_key")
                || $this->credentials->isConfigured($provider->id);
        }

        return true;
    }

    private function defaultFor(ProviderCapability $capability): ProviderDefinition
    {
        return collect($this->registry->forCapability($capability))
            ->first(fn (ProviderDefinition $provider): bool => $this->isAvailableForAutomaticSelection($provider->adapterFor($capability)))
            ?? throw new LogicException("No provider is registered for [{$capability->value}].");
    }
}
