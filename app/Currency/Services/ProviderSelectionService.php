<?php

namespace App\Currency\Services;

use App\Currency\DTO\ProviderDefinition;
use App\Currency\Enums\ProviderCapability;
use App\Currency\Exceptions\ProviderException;
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
        $provider = $this->configured($capability) ?? $this->defaultFor($capability);
        if (! $this->isAvailableForAutomaticSelection($provider->adapterFor($capability))) {
            throw new ProviderException("Selected provider [{$provider->id}] requires an API key.");
        }

        return $provider;
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

    public function automaticDefault(ProviderCapability $capability): ?ProviderDefinition
    {
        foreach ($this->registry->forCapability($capability) as $provider) {
            if ($this->isAvailableForAutomaticSelection($provider->adapterFor($capability))) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * @template T of object
     *
     * @param  iterable<T>  $adapters
     * @return list<T>
     */
    public function candidates(ProviderCapability $capability, iterable $adapters): array
    {
        $available = is_array($adapters) ? array_values($adapters) : iterator_to_array($adapters, false);
        $configured = $this->configured($capability);
        if ($configured !== null) {
            if (! $this->isAvailableForAutomaticSelection($configured->adapterFor($capability))) {
                throw new ProviderException("Selected provider [{$configured->id}] requires an API key.");
            }

            $selected = array_values(array_filter($available, static fn (object $adapter): bool => $adapter::class === $configured->adapterFor($capability)));
            if ($selected === []) {
                throw new ProviderException('The selected provider adapter is not available in this application build.');
            }

            return $selected;
        }

        $priority = [];
        foreach ($this->registry->forCapability($capability) as $position => $provider) {
            $priority[$provider->adapterFor($capability)] = $position;
        }
        $available = array_values(array_filter($available, fn (object $adapter): bool => $this->isAvailableForAutomaticSelection($adapter::class)));
        usort($available, static fn (object $left, object $right): int => ($priority[$left::class] ?? PHP_INT_MAX) <=> ($priority[$right::class] ?? PHP_INT_MAX));

        return $available;
    }

    public function cacheIdentity(ProviderCapability $capability): string
    {
        $configured = $this->configured($capability);
        if ($configured !== null) {
            if (! $this->isAvailableForAutomaticSelection($configured->adapterFor($capability))) {
                throw new ProviderException("Selected provider [{$configured->id}] requires an API key.");
            }

            return $configured->id;
        }

        $available = array_filter($this->registry->forCapability($capability), fn (ProviderDefinition $provider): bool => $this->isAvailableForAutomaticSelection($provider->adapterFor($capability)));

        return 'automatic:'.implode(',', array_map(static fn (ProviderDefinition $provider): string => $provider->id, $available));
    }

    private function defaultFor(ProviderCapability $capability): ProviderDefinition
    {
        return $this->automaticDefault($capability)
            ?? throw new ProviderException("No available provider is registered for [{$capability->value}].");
    }
}
