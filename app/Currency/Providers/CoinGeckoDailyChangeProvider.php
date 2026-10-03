<?php

namespace App\Currency\Providers;

use App\Currency\Contracts\DailyChangeProviderInterface;
use App\Currency\Enums\Currency;
use App\Currency\Enums\CurrencyType;
use App\Currency\Exceptions\ProviderResponseException;
use App\Currency\Services\ExternalApiClientFactory;

final class CoinGeckoDailyChangeProvider implements DailyChangeProviderInterface
{
    public function __construct(private ExternalApiClientFactory $clients) {}

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

        $response = $this->clients->get('coingecko', 'simple/price', [
            'ids' => implode(',', array_values($assets)),
            'vs_currencies' => 'usd',
            'include_24hr_change' => 'true',
        ]);
        $payload = $response->json();
        if (! is_array($payload)) {
            throw new ProviderResponseException('CoinGecko daily changes are unavailable.');
        }

        $changes = [];
        foreach ($assets as $code => $id) {
            if (isset($payload[$id]) && ! is_array($payload[$id])) {
                throw new ProviderResponseException('CoinGecko returned invalid daily changes.');
            }
            $change = $payload[$id]['usd_24h_change'] ?? null;
            if ($change !== null && (! is_numeric($change) || ! is_finite((float) $change))) {
                throw new ProviderResponseException('CoinGecko returned invalid daily changes.');
            }
            $changes[$code] = $change === null ? null : (float) $change;
        }

        return $changes;
    }
}
