<?php

namespace App\Currency\Services;

use App\Currency\Contracts\CurrencyCatalogProviderInterface;
use App\Currency\Enums\Currency;
use App\Currency\Enums\CurrencyType;
use App\Currency\Enums\ProviderCapability;
use App\Currency\Exceptions\ProviderException;
use App\Currency\Exceptions\UnsupportedCurrencyPairException;

final class CurrencyCatalog
{
    private const CORE_FIAT_CODES = ['BYN', 'USD', 'EUR', 'PLN', 'GBP', 'CNY', 'RUB', 'UAH'];

    /** @var array{currencies: list<Currency>, cryptoProvider: string, isComplete: bool, isFallback: bool, message: ?string}|null */
    private ?array $resolvedSnapshot = null;

    /** @param iterable<CurrencyCatalogProviderInterface> $providers */
    public function __construct(
        private iterable $providers,
        private ?CurrencyCache $cache,
        private ProviderSelectionService $selections,
    ) {}

    /** @return list<Currency> */
    public function all(): array
    {
        return $this->snapshot()['currencies'];
    }

    /** @return array{currencies: list<Currency>, cryptoProvider: string, isComplete: bool, isFallback: bool, message: ?string} */
    public function snapshot(): array
    {
        if ($this->resolvedSnapshot !== null) {
            return $this->resolvedSnapshot;
        }
        $selected = $this->selections->configured(ProviderCapability::CATALOG);
        $cryptoProvider = $this->selections->selected(ProviderCapability::CRYPTO_RATES);
        $cryptoCatalogAdapter = $cryptoProvider->adapterFor(ProviderCapability::CATALOG);
        $cacheKey = 'currency-catalog:v10:'.$this->selections->cacheIdentity(ProviderCapability::CATALOG).':crypto-'.$cryptoProvider->id;

        try {
            $currencies = $this->cache()->remember($cacheKey, now()->addMinutes(30), function () use ($selected, $cryptoCatalogAdapter, $cacheKey): array {
                // BYN is the application's base currency, not a remote catalog entry.
                // Keep the core fiat set available while a remote catalog is unavailable.
                $currencies = [];
                foreach (self::CORE_FIAT_CODES as $code) {
                    $currencies["fiat:{$code}"] = Currency::fiat($code);
                }
                $providers = $this->orderedProviders($selected?->adapterFor(ProviderCapability::CATALOG));
                if ($cryptoCatalogAdapter !== null) {
                    $cryptoSource = array_values(array_filter($providers, static fn (CurrencyCatalogProviderInterface $provider): bool => $provider::class === $cryptoCatalogAdapter));
                    if ($cryptoSource !== []) {
                        $providers = [...$cryptoSource, ...array_values(array_filter($providers, static fn (CurrencyCatalogProviderInterface $provider): bool => $provider::class !== $cryptoCatalogAdapter))];
                    }
                }

                foreach ($providers as $provider) {
                    $isSelected = $selected !== null && $provider::class === $selected->adapterFor(ProviderCapability::CATALOG);
                    $isCryptoSource = $cryptoCatalogAdapter !== null && $provider::class === $cryptoCatalogAdapter;
                    if (! $isSelected && ! $isCryptoSource && ! $this->selections->isAvailableForAutomaticSelection($provider::class)) {
                        continue;
                    }

                    try {
                        foreach ($provider->currencies() as $definition) {
                            $key = "{$definition->type->value}:{$definition->code}";
                            if ($definition->type === CurrencyType::CRYPTO && ! $isCryptoSource) {
                                if (! isset($currencies[$key])) {
                                    continue;
                                }
                            }
                            if ($isCryptoSource && $definition->type !== CurrencyType::CRYPTO) {
                                continue;
                            }

                            if ($selected !== null && ! $isSelected && ! $isCryptoSource && ! isset($currencies[$key])) {
                                continue;
                            }
                            $current = $currencies[$key] ?? null;
                            $group = $definition->type === CurrencyType::CRYPTO
                                ? ($isCryptoSource ? $definition->group : $current?->group)
                                : ($definition->group ?? $current?->group);
                            $currencies[$key] = new Currency($definition->code, $definition->type,
                                $current?->providerSymbol ?? $definition->providerSymbol,
                                $definition->name ?? $current?->name,
                                $definition->coinGeckoId ?? $current?->coinGeckoId,
                                $group,
                            );
                        }
                    } catch (ProviderException $exception) {
                        if ($isSelected || $isCryptoSource) {
                            throw $exception;
                        }

                        continue;
                    }
                }

                $result = array_values($currencies);
                $this->cache()->put($cacheKey.':last-success', $result, now()->addDays(7));

                return $result;
            });

            return $this->resolvedSnapshot = ['currencies' => $currencies, 'cryptoProvider' => $cryptoProvider->id, 'isComplete' => true, 'isFallback' => false, 'message' => null];
        } catch (ProviderException) {
            $saved = $this->cache()->get($cacheKey.':last-success');

            return $this->resolvedSnapshot = [
                'currencies' => $saved ?? array_map(Currency::fiat(...), self::CORE_FIAT_CODES),
                'cryptoProvider' => $cryptoProvider->id,
                'isComplete' => false,
                'isFallback' => $saved !== null,
                'message' => $saved !== null ? 'Каталог источника временно недоступен. Используется сохранённый список валют.' : 'Каталог выбранного источника временно недоступен.',
            ];
        }
    }

    /** @return list<CurrencyCatalogProviderInterface> */
    private function orderedProviders(?string $selectedAdapter): array
    {
        $providers = is_array($this->providers) ? $this->providers : iterator_to_array($this->providers, false);
        if ($selectedAdapter === null) {
            return array_values($providers);
        }

        $selected = array_values(array_filter($providers, static fn (CurrencyCatalogProviderInterface $provider): bool => $provider::class === $selectedAdapter));
        if ($selected === []) {
            throw new ProviderException('The selected catalog provider is not available in this application build.');
        }

        return [...$selected, ...array_values(array_filter($providers, static fn (CurrencyCatalogProviderInterface $provider): bool => $provider::class !== $selectedAdapter))];
    }

    private function cache(): CurrencyCache
    {
        return $this->cache ??= app(CurrencyCache::class);
    }

    public function resolve(string $code, ?string $type = null): Currency
    {
        $code = strtoupper($code);
        if ($code === 'BYN' && ($type === null || $type === CurrencyType::FIAT->value)) {
            return Currency::fiat('BYN');
        }

        foreach ($this->all() as $currency) {
            if ($currency->code === $code && ($type === null || $currency->type->value === $type)) {
                return $currency;
            }
        }
        if (! $this->snapshot()['isComplete']) {
            throw new ProviderException('The asset cannot be resolved while its catalog is unavailable.');
        }
        throw new UnsupportedCurrencyPairException("Unknown currency {$code}.");
    }
}
