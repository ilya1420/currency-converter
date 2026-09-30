<?php

namespace App\Currency\Services;

use App\Currency\Contracts\CurrencyCatalogProviderInterface;
use App\Currency\Enums\Currency;
use App\Currency\Enums\CurrencyType;
use App\Currency\Exceptions\ProviderException;
use App\Currency\Exceptions\UnsupportedCurrencyPairException;

final class CurrencyCatalog
{
    /** @param iterable<CurrencyCatalogProviderInterface> $providers */
    public function __construct(private iterable $providers, private ?CurrencyCache $cache = null) {}

    /** @return list<Currency> */
    public function all(): array
    {
        return $this->cache()->remember('currency-catalog:v5', now()->addMinutes(30), function (): array {
            // BYN is the application's base currency, not a remote catalog entry.
            // Keep the core fiat set available while a remote catalog is unavailable.
            $currencies = [];
            foreach (['BYN', 'USD', 'EUR', 'PLN', 'GBP', 'CNY', 'RUB', 'UAH'] as $code) {
                $currencies["fiat:{$code}"] = Currency::fiat($code);
            }
            foreach ($this->providers as $provider) {
                try {
                    foreach ($provider->currencies() as $definition) {
                        $key = "{$definition->type->value}:{$definition->code}";
                        $current = $currencies[$key] ?? null;
                        $currencies[$key] = new Currency($definition->code, $definition->type,
                            $current?->providerSymbol ?? $definition->providerSymbol,
                            $definition->name ?? $current?->name,
                            $definition->coinGeckoId ?? $current?->coinGeckoId,
                            $definition->group ?? $current?->group,
                        );
                    }
                } catch (ProviderException) {
                    continue;
                }
            }

            return array_values($currencies);
        });
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
