<?php

namespace App\Http\Controllers;

use App\Currency\DTO\ProviderDefinition;
use App\Currency\Enums\ProviderCapability;
use App\Currency\Services\ProviderRegistry;
use App\Currency\Services\ProviderSelectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class ProviderSettingsController
{
    public function index(ProviderRegistry $registry, ProviderSelectionService $selections): JsonResponse
    {
        return response()->json([
            'capabilities' => collect(ProviderCapability::cases())
                ->mapWithKeys(fn (ProviderCapability $capability): array => [
                    $capability->value => [
                        'selected' => $selections->configured($capability)?->id,
                        'default' => $registry->forCapability($capability)[0]->id,
                        'providers' => array_map($this->provider(...), $registry->forCapability($capability)),
                    ],
                ]),
        ]);
    }

    public function update(string $capability, Request $request, ProviderSelectionService $selections): JsonResponse
    {
        $capability = $this->providerCapability($capability);
        $data = $request->validate([
            'provider_id' => ['required', 'string', 'max:64'],
        ]);

        try {
            $provider = $selections->select($capability, $data['provider_id']);
        } catch (InvalidArgumentException) {
            return response()->json(['message' => 'Этот провайдер не поддерживает выбранный тип курсов.'], 422);
        }

        return response()->json(['selected' => $provider->id]);
    }

    public function destroy(string $capability, ProviderSelectionService $selections): JsonResponse
    {
        $default = $selections->reset($this->providerCapability($capability));

        return response()->json(['selected' => null, 'default' => $default->id]);
    }

    private function providerCapability(string $value): ProviderCapability
    {
        $capability = ProviderCapability::tryFrom($value);
        abort_unless($capability !== null, 404);

        return $capability;
    }

    /** @return array{id: string, name: string} */
    private function provider(ProviderDefinition $provider): array
    {
        return ['id' => $provider->id, 'name' => $provider->name];
    }
}
