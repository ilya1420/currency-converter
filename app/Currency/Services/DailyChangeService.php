<?php

namespace App\Currency\Services;

use App\Currency\Contracts\DailyChangeProviderInterface;
use App\Currency\Enums\Currency;
use App\Currency\Enums\CurrencyType;
use App\Currency\Enums\ProviderCapability;
use App\Currency\Exceptions\ProviderException;
use App\Currency\Exceptions\ProviderResponseException;
use App\Currency\Exceptions\ProviderTimeoutException;
use App\Currency\Exceptions\RateUnavailableException;
use App\Currency\Exceptions\UnsupportedCurrencyPairException;
use App\Http\Presenters\ConversionFailurePresenter;
use Illuminate\Http\Client\ConnectionException;

final class DailyChangeService
{
    /** @var list<DailyChangeProviderInterface>|null */
    private ?array $resolvedProviders = null;

    public function __construct(
        private CurrencyCatalog $catalog,
        private CurrencyCache $cache,
        /** @var iterable<DailyChangeProviderInterface> */
        private iterable $providers,
        private ProviderSelectionService $selections,
    ) {}

    /** @param list<string> $codes @return array<string, ?float> */
    public function forCurrencies(array $codes): array
    {
        return $this->snapshot($codes)['changes'];
    }

    /**
     * @param  list<string>  $codes
     * @return array{changes: array<string, ?float>, statuses: array<string, array{status: string, code: ?string, provider: ?string, message: ?string, retryAfter: ?int}>}
     */
    public function snapshot(array $codes): array
    {
        $results = [];
        $pending = [];
        $selectionIds = [];

        foreach (array_unique($codes) as $code) {
            $currency = null;
            try {
                $currency = $this->catalog->resolve($code);
                if ($currency->code === 'BYN') {
                    $results[$code] = $this->unavailable('unsupported_asset');

                    continue;
                }

                $capability = $this->capabilityFor($currency);
                $selectionIds[$code] = $this->selections->cacheIdentity($capability);
                $key = $this->cacheKey($currency, $selectionIds[$code]);
                if (($cached = $this->cache->get($key)) !== null) {
                    $results[$code] = $cached;

                    continue;
                }
                $pending[$code] = $currency;
            } catch (UnsupportedCurrencyPairException) {
                $results[$code] = $this->unavailable('unsupported_asset');
            } catch (ProviderException $exception) {
                $providerId = $currency === null ? null : $this->selections->configured($this->capabilityFor($currency))?->id;
                $results[$code] = $this->failure($exception, $providerId);
            }
        }

        foreach ([CurrencyType::FIAT, CurrencyType::CRYPTO] as $type) {
            $capability = $this->capabilityForType($type);
            $configured = $this->selections->configured($capability);
            $currencies = array_filter($pending, static fn (Currency $currency): bool => $currency->type === $type);
            if ($currencies === []) {
                continue;
            }
            try {
                $providers = $this->selections->candidates($capability, $this->providerList());
            } catch (ProviderException $exception) {
                foreach ($currencies as $currency) {
                    $results[$currency->code] = $this->failure($exception, $configured?->id);
                    unset($pending[$currency->code]);
                }

                continue;
            }

            foreach ($providers as $provider) {
                $supported = array_values(array_filter($pending, static fn (Currency $currency): bool => $currency->type === $type && $provider->supports($currency)));
                if ($supported === []) {
                    continue;
                }
                $providerId = $this->selections->definitionForAdapter($provider::class)?->id;
                $identity = $this->selections->cacheIdentity($capability);
                $failureKey = 'daily-change:failure:v1:'.$capability->value.':'.$identity.':'.($providerId ?? $provider::class);
                $failure = $this->cache->get($failureKey);
                if ($failure !== null) {
                    $failure['status']['retryAfter'] = max(0, $failure['retryAt'] - now()->getTimestamp());
                } else {
                    try {
                        $fresh = $provider->dailyChanges($supported);
                        foreach ($fresh as $change) {
                            if ($change !== null && ((! is_int($change) && ! is_float($change)) || ! is_finite($change))) {
                                throw new ProviderResponseException('Invalid daily change.');
                            }
                        }
                    } catch (ConnectionException|ProviderException $exception) {
                        $failure = $this->failure($exception, $providerId);
                        $seconds = max(1, (int) config('currency.daily_changes.failure_cooldown_seconds', 30), $failure['status']['retryAfter'] ?? 0);
                        $failure['status']['retryAfter'] = $seconds;
                        $failure['retryAt'] = now()->getTimestamp() + $seconds;
                        $this->cache->put($failureKey, $failure, $seconds);
                    } catch (UnsupportedCurrencyPairException) {
                        $fresh = [];
                    }
                }

                if ($failure !== null) {
                    unset($failure['retryAt']);
                    foreach ($supported as $currency) {
                        $results[$currency->code] = $failure;
                        unset($pending[$currency->code]);
                    }

                    continue;
                }

                foreach ($supported as $currency) {
                    $change = $fresh[$currency->code] ?? null;
                    $result = $change === null ? $this->unavailable('no_data', $providerId) : [
                        'change' => (float) $change,
                        'status' => ['status' => 'available', 'code' => null, 'provider' => $providerId, 'message' => null, 'retryAfter' => null],
                    ];
                    $results[$currency->code] = $result;
                    if ($change !== null || $configured !== null) {
                        $this->cache->put($this->cacheKey($currency, $identity), $result,
                            $change !== null ? $this->ttl($currency) : (int) config('currency.daily_changes.missing_data_ttl_seconds', 60));
                        unset($pending[$currency->code]);
                    }
                }
            }
        }

        foreach ($pending as $code => $currency) {
            $result = $results[$code] ?? $this->unavailable('unsupported_asset');
            $results[$code] = $result;
            $this->cache->put($this->cacheKey($currency, $selectionIds[$code]), $result, (int) config('currency.daily_changes.missing_data_ttl_seconds', 60));
        }

        return [
            'changes' => array_map(static fn (array $result): ?float => $result['change'], $results),
            'statuses' => array_map(static fn (array $result): array => $result['status'], $results),
        ];
    }

    /** @return array{change: null, status: array{status: string, code: string, provider: ?string, message: string, retryAfter: null}} */
    private function unavailable(string $code, ?string $provider = null): array
    {
        return ['change' => null, 'status' => [
            'status' => 'unavailable', 'code' => $code, 'provider' => $provider,
            'message' => 'Дневное изменение для этой валюты недоступно.', 'retryAfter' => null,
        ]];
    }

    /** @return array{change: null, status: array{status: string, code: string, provider: ?string, message: string, retryAfter: ?int}} */
    private function failure(ProviderException|ConnectionException $exception, ?string $provider): array
    {
        if ($exception instanceof ConnectionException) {
            $exception = new ProviderTimeoutException('Daily change request timed out.', $exception);
        }
        $failure = (new ConversionFailurePresenter)->present(new RateUnavailableException('Daily change is unavailable.', $provider, previous: $exception));

        return ['change' => null, 'status' => [
            'status' => 'error', 'code' => $failure['code'], 'provider' => $provider,
            'message' => $failure['message'], 'retryAfter' => $failure['retryAfter'],
        ]];
    }

    private function cacheKey(Currency $currency, string $selectionId): string
    {
        return "daily-change:v5:{$selectionId}:{$currency->type->value}:{$currency->code}";
    }

    private function capabilityFor(Currency $currency): ProviderCapability
    {
        return $this->capabilityForType($currency->type);
    }

    private function capabilityForType(CurrencyType $type): ProviderCapability
    {
        return $type === CurrencyType::CRYPTO ? ProviderCapability::CRYPTO_DAILY_CHANGES : ProviderCapability::FIAT_DAILY_CHANGES;
    }

    /** @return list<DailyChangeProviderInterface> */
    private function providerList(): array
    {
        return $this->resolvedProviders ??= is_array($this->providers)
            ? array_values($this->providers)
            : iterator_to_array($this->providers, false);
    }

    private function ttl(Currency $currency): \DateTimeInterface
    {
        return $currency->type->value === 'crypto' ? now()->addMinutes(15) : now()->addDay();
    }
}
