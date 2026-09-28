<?php

namespace App\Currency\Providers;

use App\Currency\Contracts\CurrencyCatalogProviderInterface;
use App\Currency\DTO\CurrencyDefinition;
use App\Currency\Enums\CurrencyType;
use App\Currency\Exceptions\ProviderException;
use App\Currency\Exceptions\ProviderRateLimitException;
use App\Currency\Exceptions\ProviderResponseException;
use App\Currency\Services\CurrencyCache;
use App\Currency\Services\ExternalApiClientFactory;
use Illuminate\Http\Client\ConnectionException;

/** Supplies names, stable/meme grouping and CoinGecko IDs for the liquid asset universe. */
final class CoinGeckoCurrencyCatalogProvider implements CurrencyCatalogProviderInterface
{
    public function __construct(private CurrencyCache $cache, private ExternalApiClientFactory $clients) {}

    public function currencies(): array
    {
        return $this->cache->remember('coingecko-currency-metadata:v1', now()->addHours(6), fn (): array => $this->fetchCurrencies());
    }

    /** @return list<CurrencyDefinition> */
    private function fetchCurrencies(): array
    {
        try {
            $market = $this->request('coins/markets', ['vs_currency' => 'usd', 'order' => 'market_cap_desc', 'per_page' => 250, 'page' => 1]);
            $stable = $this->idsForCategory('stablecoins');
            $meme = $this->idsForCategory('meme-token');
        } catch (ConnectionException $exception) {
            throw new ProviderException('CoinGecko currency catalog is unavailable.', previous: $exception);
        }

        if (! is_array($market)) {
            throw new ProviderException('CoinGecko currency catalog is unavailable.');
        }

        $currencies = [];
        foreach ($market as $asset) {
            $id = is_array($asset) ? $asset['id'] ?? null : null;
            $symbol = is_array($asset) ? $asset['symbol'] ?? null : null;
            $name = is_array($asset) ? $asset['name'] ?? null : null;
            if (! is_string($id) || ! is_string($symbol) || ! preg_match('/^[A-Z0-9]{2,10}$/', $code = strtoupper($symbol))) {
                continue;
            }

            // The UI addresses currencies by ticker. CoinGecko can return several
            // assets with the same ticker; keep the first one because the feed is
            // ordered by market cap and therefore starts with the most liquid asset.
            if (isset($currencies[$code])) {
                continue;
            }

            $group = in_array($id, $stable, true) ? 'stable' : (in_array($id, $meme, true) ? 'meme' : 'alt');
            $currencies[$code] = new CurrencyDefinition($code, CurrencyType::CRYPTO, null, is_string($name) ? $name : null, $id, $group);
        }

        return array_values($currencies);
    }

    /** @return list<string> */
    private function idsForCategory(string $category): array
    {
        $assets = $this->request('coins/markets', ['vs_currency' => 'usd', 'category' => $category, 'per_page' => 250, 'page' => 1]);
        if (! is_array($assets)) {
            return [];
        }

        return array_values(array_filter(array_map(static fn (mixed $asset): mixed => is_array($asset) ? ($asset['id'] ?? null) : null, $assets), 'is_string'));
    }

    /** @return array<mixed> */
    private function request(string $path, array $query): array
    {
        $response = $this->clients->for('coingecko')->get($path, $query);
        if ($response->status() === 429) {
            throw new ProviderRateLimitException('CoinGecko rate limit reached.', is_numeric($response->header('Retry-After')) ? (int) $response->header('Retry-After') : null);
        }
        if ($response->failed() || ! is_array($response->json())) {
            throw new ProviderResponseException('CoinGecko currency catalog is unavailable.');
        }

        return $response->json();
    }

}
