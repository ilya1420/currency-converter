<?php

namespace App\Currency\Providers;

use App\Currency\Contracts\CurrencyCatalogProviderInterface;
use App\Currency\DTO\CurrencyDefinition;
use App\Currency\Enums\CurrencyType;
use App\Currency\Exceptions\ProviderException;
use App\Currency\Services\ExternalApiClientFactory;
use Illuminate\Http\Client\ConnectionException;

final class KrakenCurrencyCatalogProvider implements CurrencyCatalogProviderInterface
{
    public function __construct(private ExternalApiClientFactory $clients) {}

    public function currencies(): array
    {
        try {
            $response = $this->clients->for('kraken')->get('AssetPairs', ['assetVersion' => 1]);
        } catch (ConnectionException $exception) {
            throw new ProviderException('Kraken currency catalog is unavailable.', previous: $exception);
        }

        $pairs = $response->json('result');
        if ($response->failed() || ! is_array($pairs)) {
            throw new ProviderException('Kraken currency catalog is unavailable.');
        }

        $currencies = [];
        $popularLimit = max(1, (int) config('currency.catalog.popular_limit', 25));
        foreach ($pairs as $pair) {
            $base = is_array($pair) ? $pair['base'] ?? null : null;
            $quote = is_array($pair) ? $pair['quote'] ?? null : null;
            if (is_string($base) && in_array($quote, ['USD', 'ZUSD'], true)) {
                $code = strtoupper(match ($base) {
                    'XXBT', 'XBT' => 'BTC',
                    default => str_starts_with($base, 'X') && strlen($base) === 4 ? substr($base, 1) : $base,
                });
                if (preg_match('/^[A-Z0-9]{2,10}$/', $code)) {
                    $pairName = $pair['altname'] ?? null;
                    if (is_string($pairName) && ! isset($currencies[$code])) {
                        $rank = count($currencies);
                        $group = $rank < $popularLimit ? 'popular' : 'other';
                        $currencies[$code] = new CurrencyDefinition($code, CurrencyType::CRYPTO, $pairName, null, null, $group);
                    }
                }
            }
        }

        return array_values($currencies);
    }
}
