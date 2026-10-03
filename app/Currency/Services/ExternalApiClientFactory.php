<?php

namespace App\Currency\Services;

use App\Currency\Exceptions\ProviderRateLimitException;
use App\Currency\Exceptions\ProviderResponseException;
use App\Currency\Exceptions\ProviderTimeoutException;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;
use ValueError;

final class ExternalApiClientFactory
{
    public function __construct(private ProviderCredentialService $credentials) {}

    /** @param array<string, mixed> $query */
    public function get(string $provider, string $path, array $query = []): Response
    {
        try {
            $response = $this->for($provider)->get($path, $query);
        } catch (ConnectionException $exception) {
            throw new ProviderTimeoutException("{$provider} request timed out.", $exception);
        }

        if ($response->status() === 429) {
            throw new ProviderRateLimitException("{$provider} rate limit reached.", $this->retryAfter($response->header('Retry-After')));
        }
        if (! $response->successful()) {
            throw new ProviderResponseException("{$provider} returned HTTP {$response->status()}.");
        }

        return $response;
    }

    private function retryAfter(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }
        $seconds = filter_var($value, FILTER_VALIDATE_INT, ['options' => [
            'min_range' => 0, 'max_range' => (new DateTimeImmutable('9999-12-31T23:59:59Z'))->getTimestamp() - now()->getTimestamp(),
        ]]);
        if ($seconds !== false) {
            return $seconds;
        }

        try {
            $date = DateTimeImmutable::createFromFormat('!D, d M Y H:i:s \G\M\T', $value, new DateTimeZone('UTC'));
        } catch (ValueError) {
            return null;
        }

        return $date !== false && DateTimeImmutable::getLastErrors() === false
            ? max(0, $date->getTimestamp() - now()->getTimestamp()) : null;
    }

    public function for(string $provider): PendingRequest
    {
        $retryAttempts = (int) config("currency.{$provider}.http.retry_attempts", 1);
        $request = Http::baseUrl((string) config("currency.{$provider}.base_url"))
            ->acceptJson()
            ->connectTimeout((int) config('currency.http.connect_timeout'))
            ->timeout((int) config('currency.http.timeout'));

        if ($provider === 'coingecko' && ($apiKey = $this->credentials->apiKey($provider)) !== null) {
            $request = $request->withHeader('x-cg-demo-api-key', $apiKey);
        }

        if ($retryAttempts > 1) {
            $request = $request->retry(
                $retryAttempts,
                (int) config('currency.http.retry_delay_ms'),
                static fn (Throwable $exception): bool => $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && $exception->response->serverError()),
                throw: false,
            );
        }

        return $request;
    }
}
