<?php

namespace App\Currency\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Throwable;

final class ExternalApiClientFactory
{
    public function __construct(private ProviderCredentialService $credentials) {}

    public function for(string $provider): PendingRequest
    {
        $request = Http::baseUrl((string) config("currency.{$provider}.base_url"))
            ->acceptJson()
            ->connectTimeout((int) config('currency.http.connect_timeout'))
            ->timeout((int) config('currency.http.timeout'));

        if ($provider === 'coingecko' && ($apiKey = $this->credentials->apiKey($provider)) !== null) {
            $request = $request->withHeader('x-cg-demo-api-key', $apiKey);
        }

        if ($provider === 'nbrb') {
            $request = $request->retry(
                (int) config('currency.http.retry_times'),
                (int) config('currency.http.retry_delay_ms'),
                static fn (Throwable $exception): bool => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response->serverError()),
                throw: false,
            );
        }

        return $request;
    }
}
