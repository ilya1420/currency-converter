<?php

namespace App\Currency\Services;

use App\Models\ProviderCredential;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

class ProviderCredentialService
{
    public function apiKey(string $provider): ?string
    {
        $storedKey = ProviderCredential::query()
            ->where('provider_id', $provider)
            ->first()
            ?->settings['api_key'] ?? null;

        if (is_string($storedKey) && $storedKey !== '') {
            return $storedKey;
        }

        $environmentKey = config("currency.{$provider}.api_key");

        return is_string($environmentKey) && $environmentKey !== '' ? $environmentKey : null;
    }

    public function isConfigured(string $provider): bool
    {
        return $this->apiKey($provider) !== null;
    }

    public function verifyApiKey(string $provider, string $apiKey): bool
    {
        try {
            return Http::baseUrl((string) config("currency.{$provider}.base_url"))
                ->acceptJson()
                ->connectTimeout((int) config('currency.http.connect_timeout'))
                ->timeout((int) config('currency.http.timeout'))
                ->withHeader('x-cg-demo-api-key', $apiKey)
                ->get('/ping')
                ->successful();
        } catch (ConnectionException) {
            return false;
        }
    }

    public function saveApiKey(string $provider, string $apiKey): void
    {
        ProviderCredential::query()->updateOrCreate(
            ['provider_id' => $provider],
            ['settings' => ['api_key' => $apiKey]],
        );
    }
}
