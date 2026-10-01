<?php

namespace App\Http\Controllers;

use App\Currency\DTO\ProviderDefinition;
use App\Currency\Enums\ProviderCapability;
use App\Currency\Services\ProviderCredentialService;
use App\Currency\Services\ProviderRegistry;
use App\Currency\Services\ProviderSelectionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class ProviderSettingsController
{
    public function index(ProviderRegistry $registry, ProviderSelectionService $selections, ProviderCredentialService $credentials): JsonResponse
    {
        return response()->json([
            'providers' => array_map(fn (ProviderDefinition $provider): array => $this->provider($provider, $credentials), $registry->all()),
            'capabilities' => collect(ProviderCapability::cases())
                ->mapWithKeys(function (ProviderCapability $capability) use ($registry, $selections, $credentials): array {
                    $providers = $registry->forCapability($capability);
                    if ($providers === []) {
                        return [];
                    }

                    return [$capability->value => [
                        'selected' => $selections->configured($capability)?->id,
                        'default' => $providers[0]->id,
                        'providers' => array_map(fn (ProviderDefinition $provider): array => $this->provider($provider, $credentials), $providers),
                    ]];
                }),
            'provider_settings' => [
                'coingecko' => ['configured' => $credentials->isConfigured('coingecko')],
            ],
        ]);
    }

    public function saveCoinGeckoKey(Request $request, ProviderCredentialService $credentials): JsonResponse
    {
        $data = $request->validate(['api_key' => ['required', 'string', 'max:512']]);

        if (! $credentials->verifyApiKey('coingecko', $data['api_key'])) {
            return response()->json(['message' => 'Ключ CoinGecko не прошёл проверку. Проверьте ключ и доступность API.'], 422);
        }

        $credentials->saveApiKey('coingecko', $data['api_key']);

        return response()->json(['configured' => true, 'message' => 'Ключ CoinGecko проверен и сохранён на этом устройстве.']);
    }

    public function update(string $capability, Request $request, ProviderSelectionService $selections, ProviderCredentialService $credentials): JsonResponse
    {
        $capability = $this->providerCapability($capability);
        $data = $request->validate([
            'provider_id' => ['required', 'string', 'max:64'],
        ]);

        try {
            $provider = $selections->select($capability, $data['provider_id']);
        } catch (InvalidArgumentException) {
            if ((bool) config("currency.providers.registry.{$data['provider_id']}.requires_api_key") && ! $credentials->isConfigured($data['provider_id'])) {
                return response()->json(['message' => 'Сначала добавьте и проверьте ключ этого провайдера.'], 422);
            }

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

    /** @return array{id: string, name: string, capabilities: list<string>, requires_api_key: bool, configured: bool} */
    private function provider(ProviderDefinition $provider, ProviderCredentialService $credentials): array
    {
        $requiresApiKey = (bool) config("currency.providers.registry.{$provider->id}.requires_api_key");

        return [
            'id' => $provider->id,
            'name' => $provider->name,
            'capabilities' => array_map(static fn (ProviderCapability $capability): string => $capability->value, $provider->capabilities()),
            'requires_api_key' => $requiresApiKey,
            'configured' => ! $requiresApiKey || $credentials->isConfigured($provider->id),
        ];
    }
}
