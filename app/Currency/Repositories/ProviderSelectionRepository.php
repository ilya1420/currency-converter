<?php

namespace App\Currency\Repositories;

use App\Currency\Enums\ProviderCapability;
use App\Models\ProviderSelection;

final class ProviderSelectionRepository
{
    public function find(ProviderCapability $capability): ?string
    {
        $providerId = ProviderSelection::query()
            ->where('capability', $capability->value)
            ->value('provider_id');

        return is_string($providerId) ? $providerId : null;
    }

    public function save(ProviderCapability $capability, string $providerId): void
    {
        ProviderSelection::query()->updateOrCreate(
            ['capability' => $capability->value],
            ['provider_id' => $providerId],
        );
    }

    public function forget(ProviderCapability $capability): void
    {
        ProviderSelection::query()->where('capability', $capability->value)->delete();
    }
}
