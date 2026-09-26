<?php

namespace App\Currency\Providers;

use App\Currency\Enums\Currency;
use App\Currency\Enums\CurrencyType;
use App\Currency\Exceptions\ProviderException;
use App\Currency\Exceptions\UnsupportedCurrencyPairException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

final class KrakenMarketDataProvider
{
    public function __construct(private KrakenAssetMapper $mapper) {}

    /** @return array{candles: list<array{time: int, open: string, high: string, low: string, close: string}>, ticker: array{last: string, high: string, low: string, volume: string, trades: int}, depth: array{bids: list<array>, asks: list<array>}} */
    public function snapshot(Currency $currency, int $interval): array
    {
        if ($currency->type() !== CurrencyType::CRYPTO) {
            throw new UnsupportedCurrencyPairException('Market charts are available for crypto assets only.');
        }

        return Cache::remember(
            "kraken-market:{$currency->value}:{$interval}",
            now()->addSeconds(config('currency.kraken.market_data_ttl_seconds')),
            fn (): array => $this->fetchSnapshot($currency, $interval),
        );
    }

    /** @return array{candles: list<array{time: int, open: string, high: string, low: string, close: string}>, ticker: array{last: string, high: string, low: string, volume: string, trades: int}, depth: array{bids: list<array>, asks: list<array>}} */
    private function fetchSnapshot(Currency $currency, int $interval): array
    {
        $pair = $this->mapper->usdPair($currency);
        $params = ['pair' => $pair, 'assetVersion' => 1];
        $ohlc = $this->singlePairResult($this->request('OHLC', $params + ['interval' => $interval]));
        $ticker = $this->singlePairResult($this->request('Ticker', $params));
        $depth = $this->singlePairResult($this->request('Depth', $params + ['count' => 10]));
        $candles = array_values(array_filter($ohlc, 'is_array'));

        array_pop($candles); // Kraken always includes the unfinished current candle.
        $candles = array_slice($candles, -60); // The client renders the same 60-candle window it labels.

        return [
            'candles' => array_map(static fn (array $candle): array => [
                'time' => (int) $candle[0], 'open' => (string) $candle[1], 'high' => (string) $candle[2],
                'low' => (string) $candle[3], 'close' => (string) $candle[4],
            ], $candles),
            'ticker' => [
                'last' => (string) ($ticker['c'][0] ?? '0'),
                'high' => (string) ($ticker['h'][1] ?? '0'), 'low' => (string) ($ticker['l'][1] ?? '0'),
                'volume' => (string) ($ticker['v'][1] ?? '0'), 'trades' => (int) ($ticker['t'][1] ?? 0),
            ],
            'depth' => ['bids' => $depth['bids'] ?? [], 'asks' => $depth['asks'] ?? []],
        ];
    }

    /** @return array<string, mixed> */
    private function request(string $endpoint, array $params): array
    {
        $response = Http::baseUrl('https://api.kraken.com/0/public')
            ->acceptJson()->connectTimeout(3)->timeout(5)->get($endpoint, $params);

        if ($response->failed() || $response->json('error') !== []) {
            throw new ProviderException('Kraken market data is unavailable.');
        }

        $result = $response->json('result');
        if (! is_array($result)) {
            throw new ProviderException('Kraken returned invalid market data.');
        }

        return $result;
    }

    private function singlePairResult(array $result): array
    {
        if (count($result) !== 1) {
            throw new ProviderException('Kraken returned unexpected market data.');
        }

        $value = array_values($result)[0];

        if (! is_array($value)) {
            throw new ProviderException('Kraken returned invalid market data.');
        }

        return $value;
    }
}
