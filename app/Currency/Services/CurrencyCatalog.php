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
    /** @param iterable<CurrencyCatalogProviderInterface> $providers */
    public function __construct(
        private iterable $providers,
        private ?CurrencyCache $cache,
        private ProviderSelectionService $selections,
    ) {}

    /** @return list<Currency> */
    public function all(): array
    {
        $selected = $this->selections->configured(ProviderCapability::CATALOG);
        $cryptoProvider = $this->selections->selected(ProviderCapability::CRYPTO_RATES);
        $cryptoCatalogAdapter = $cryptoProvider->adapterFor(ProviderCapability::CATALOG);
        $cacheKey = 'currency-catalog:v9:'.($selected?->id ?? 'automatic').':crypto-'.$cryptoProvider->id;

        return $this->cache()->remember($cacheKey, now()->addMinutes(30), function () use ($selected, $cryptoCatalogAdapter): array {
            // BYN is the application's base currency, not a remote catalog entry.
            // Keep the core fiat set available while a remote catalog is unavailable.
            $currencies = [];
            foreach (['BYN', 'USD', 'EUR', 'PLN', 'GBP', 'CNY', 'RUB', 'UAH'] as $code) {
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
                } catch (ProviderException) {
                    if ($isSelected) {
                        throw new ProviderException("Selected catalog provider [{$selected->id}] is unavailable.");
                    }

                    continue;
                }
            }

            return array_values($currencies);
        });
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
        throw new UnsupportedCurrencyPairException("Unknown currency {$code}.");
    }
}
