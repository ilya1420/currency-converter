<?php

namespace App\Currency\Providers;

use App\Currency\Contracts\DailyChangeProviderInterface;
use App\Currency\Enums\Currency;
use App\Currency\Enums\CurrencyType;
use App\Currency\Exceptions\ProviderException;
use App\Currency\Exceptions\ProviderRateLimitException;
use App\Currency\Exceptions\ProviderResponseException;
use App\Currency\Services\CurrencyCache;
use Illuminate\Http\Client\ConnectionException;
use App\Currency\Services\ExternalApiClientFactory;

final class CoinGeckoDailyChangeProvider implements DailyChangeProviderInterface
{
    public function __construct(private CurrencyCache $cache, private ExternalApiClientFactory $clients) {}

    public function supports(Currency $currency): bool
    {
        return $currency->type === CurrencyType::CRYPTO && $currency->coinGeckoId !== null;
    }

    /** @param list<Currency> $currencies @return array<string, ?float> */
    public function dailyChanges(array $currencies): array
    {
        $assets = [];
        foreach ($currencies as $currency) {
            if ($this->supports($currency)) {
                $assets[$currency->code] = $currency->coinGeckoId;
            }
        }

        if ($assets === []) {
            return [];
        }

        $ids = array_values($assets);
        sort($ids);
        try {
            $cached = $this->cache->remember(
                'coingecko:daily-change:v1:'.sha1(implode(',', $ids)),
                now()->addMinutes(15),
                function () use ($ids): array {
                    $response = $this->clients->for('coingecko')->get('simple/price', [
                        'ids' => implode(',', $ids),
                        'vs_currencies' => 'usd',
                        'include_24hr_change' => 'true',
                    ]);
                    if ($response->status() === 429) {
                        throw new ProviderRateLimitException('CoinGecko rate limit reached.', is_numeric($response->header('Retry-After')) ? (int) $response->header('Retry-After') : null);
                    }
                    if ($response->failed() || ! is_array($response->json())) {
                        throw new ProviderResponseException('CoinGecko daily changes are unavailable.');
                    }

                    return $response->json();
                },
            );
        } catch (ConnectionException $exception) {
            throw new ProviderException('CoinGecko daily changes are unavailable.', previous: $exception);
        }

        $payload = $cached;
        if (! is_array($payload)) {
            throw new ProviderException('CoinGecko daily changes are unavailable.');
        }

        $changes = [];
        foreach ($assets as $code => $id) {
            $change = $payload[$id]['usd_24h_change'] ?? null;
            $changes[$code] = is_numeric($change) ? (float) $change : null;
        }

        return $changes;
    }

}
