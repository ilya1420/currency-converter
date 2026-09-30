<?php

namespace App\Currency\Providers;

use App\Currency\Contracts\DailyChangeProviderInterface;
use App\Currency\Contracts\MarketDataProviderInterface;
use App\Currency\Enums\Currency;
use App\Currency\Enums\CurrencyType;
use App\Currency\Exceptions\ProviderException;
use App\Currency\Exceptions\UnsupportedCurrencyPairException;
use App\Currency\Services\ExternalApiClientFactory;

final class KrakenMarketDataProvider implements DailyChangeProviderInterface, MarketDataProviderInterface
{
    public function __construct(private KrakenAssetMapper $mapper, private ?ExternalApiClientFactory $clients = null) {}

    public function source(): string
    {
        return 'Kraken';
    }

    /** @return array{candles: list<array{time: int, open: string, high: string, low: string, close: string}>, ticker: array{last: string, open: string, high: string, low: string, volume: string, trades: int}, depth: array{bids: list<array>, asks: list<array>}} */
    public function supports(Currency $currency): bool
    {
        return $currency->type === CurrencyType::CRYPTO;
    }

    public function chart(Currency $currency, int $interval): array
    {
        if ($currency->type !== CurrencyType::CRYPTO) {
            throw new UnsupportedCurrencyPairException('Market charts are available for crypto assets only.');
        }

        $pair = $this->mapper->usdPair($currency);
        $params = ['pair' => $pair, 'assetVersion' => 1];
        $ohlc = $this->first($this->request('OHLC', $params + ['interval' => $interval]));
        $ticker = $this->first($this->request('Ticker', $params));
        $depth = $this->first($this->request('Depth', $params + ['count' => 10]));
        $candles = array_values(array_filter($ohlc, static fn (mixed $candle): bool => is_array($candle)
            && isset($candle[0], $candle[1], $candle[2], $candle[3], $candle[4])
            && is_numeric($candle[0]) && is_numeric($candle[1]) && is_numeric($candle[2]) && is_numeric($candle[3]) && is_numeric($candle[4])));
        usort($candles, static fn (array $left, array $right): int => (int) $left[0] <=> (int) $right[0]);

        array_pop($candles); // Kraken always includes the unfinished current candle.
        $candles = array_slice($candles, -60); // The client renders the same 60-candle window it labels.

        return [
            'candles' => array_map(static fn (array $candle): array => [
                'time' => (int) $candle[0], 'open' => (string) $candle[1], 'high' => (string) $candle[2],
                'low' => (string) $candle[3], 'close' => (string) $candle[4],
            ], $candles),
            'ticker' => [
                'last' => (string) ($ticker['c'][0] ?? '0'), 'open' => (string) ($ticker['o'] ?? '0'),
                'high' => (string) ($ticker['h'][1] ?? '0'), 'low' => (string) ($ticker['l'][1] ?? '0'),
                'volume' => (string) ($ticker['v'][1] ?? '0'), 'trades' => (int) ($ticker['t'][1] ?? 0),
            ],
            'depth' => ['bids' => $depth['bids'] ?? [], 'asks' => $depth['asks'] ?? []],
        ];
    }

    public function dailyChange(Currency $currency): ?float
    {
        return $this->dailyChanges([$currency])[$currency->code] ?? null;
    }

    /** @param list<Currency> $currencies @return array<string, ?float> */
    public function dailyChanges(array $currencies): array
    {
        $pairs = [];
        $changes = [];
        foreach ($currencies as $currency) {
            if ($currency->type !== CurrencyType::CRYPTO) {
                throw new UnsupportedCurrencyPairException('Daily change is available for crypto assets only.');
            }

            try {
                $pairs[$currency->code] = $this->mapper->usdPair($currency);
            } catch (UnsupportedCurrencyPairException) {
                $changes[$currency->code] = null;
            }
        }

        if ($pairs === []) {
            return $changes;
        }

        $result = $this->request('Ticker', [
            'pair' => implode(',', $pairs),
            'assetVersion' => 1,
        ]);
        $tickers = [];
        foreach ($result as $pair => $ticker) {
            if (is_array($ticker)) {
                $tickers[$this->normalizedPair((string) $pair)] = $ticker;
            }
        }

        foreach ($pairs as $code => $pair) {
            $ticker = $tickers[$this->normalizedPair($pair)] ?? null;
            $last = (float) ($ticker['c'][0] ?? 0);
            $open = (float) ($ticker['o'] ?? 0);
            $changes[$code] = $last > 0 && $open > 0 ? (($last / $open) - 1) * 100 : null;
        }

        return $changes;
    }

    /** @return array<string, mixed> */
    private function request(string $endpoint, array $params): array
    {
        $response = ($this->clients ??= app(ExternalApiClientFactory::class))->for('kraken')->get($endpoint, $params);

        if ($response->failed() || $response->json('error') !== []) {
            throw new ProviderException('Kraken market data is unavailable.');
        }

        $result = $response->json('result');
        if (! is_array($result)) {
            throw new ProviderException('Kraken returned invalid market data.');
        }

        return $result;
    }

    private function first(array $result): array
    {
        $value = reset($result);

        if (! is_array($value)) {
            throw new ProviderException('Kraken returned invalid market data.');
        }

        return $value;
    }

    private function normalizedPair(string $pair): string
    {
        return str_replace('XBT', 'BTC', preg_replace('/[^A-Z0-9]/', '', strtoupper($pair)) ?? '');
    }
}
