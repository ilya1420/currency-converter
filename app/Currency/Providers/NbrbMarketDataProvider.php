<?php

namespace App\Currency\Providers;

use App\Currency\Contracts\DailyChangeProviderInterface;
use App\Currency\Contracts\MarketDataProviderInterface;
use App\Currency\Enums\Currency;
use App\Currency\Enums\CurrencyType;
use App\Currency\Exceptions\ProviderException;
use App\Currency\Exceptions\UnsupportedCurrencyPairException;
use App\Currency\Services\ExternalApiClientFactory;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use DateTimeZone;

final class NbrbMarketDataProvider implements DailyChangeProviderInterface, MarketDataProviderInterface
{
    public function __construct(private ExternalApiClientFactory $clients) {}

    public function source(): string
    {
        return 'NBRB';
    }

    /** @return array{candles: list<array{time: int, open: string, high: string, low: string, close: string}>, source: string} */
    public function supports(Currency $currency): bool
    {
        return $currency->code !== 'BYN' && $currency->type === CurrencyType::FIAT;
    }

    public function chart(Currency $currency, int $days): array
    {
        if ($currency->code === 'BYN' || $currency->type !== CurrencyType::FIAT) {
            throw new UnsupportedCurrencyPairException('NBRB history is unavailable for this currency.');
        }

        $client = $this->clients->for('nbrb');
        $catalog = $client->get('rates', ['periodicity' => 0]);
        $record = collect($catalog->json())->first(fn ($item) => is_array($item) && ($item['Cur_Abbreviation'] ?? null) === $currency->code);
        if ($catalog->failed() || ! is_array($record)) {
            throw new ProviderException('NBRB market data is unavailable.');
        }

        $end = new DateTimeImmutable('today');
        $history = $client->get('rates/dynamics/'.$record['Cur_ID'], [
            'startdate' => $end->modify("-{$days} days")->format('Y-m-d'), 'enddate' => $end->format('Y-m-d'),
        ]);
        if ($history->failed() || ! is_array($history->json())) {
            throw new ProviderException('NBRB market data is unavailable.');
        }

        $scale = (string) ($record['Cur_Scale'] ?? 1);

        $candles = array_map(static function (array $item) use ($scale): array {
            $rate = BigDecimal::of((string) $item['Cur_OfficialRate'])->dividedBy($scale, 12, RoundingMode::HalfUp)->__toString();
            $time = (new DateTimeImmutable((string) $item['Date'], new DateTimeZone('Europe/Minsk')))->setTime(12, 0)->getTimestamp();

            return ['time' => $time, 'open' => $rate, 'high' => $rate, 'low' => $rate, 'close' => $rate];
        }, $history->json());
        usort($candles, static fn (array $left, array $right): int => $left['time'] <=> $right['time']);

        // The dynamics endpoint can end at the previous publication when the
        // current official rate was updated separately. Keep every period
        // aligned to the same latest NBRB observation.
        if (isset($record['Date'], $record['Cur_OfficialRate'])) {
            $currentDate = (new DateTimeImmutable((string) $record['Date'], new DateTimeZone('Europe/Minsk')))->setTime(12, 0);
            $currentRate = BigDecimal::of((string) $record['Cur_OfficialRate'])
                ->dividedBy($scale, 12, RoundingMode::HalfUp)->__toString();
            $currentCandle = [
                'time' => $currentDate->getTimestamp(),
                'open' => $currentRate,
                'high' => $currentRate,
                'low' => $currentRate,
                'close' => $currentRate,
            ];
            if ($candles === [] || $candles[array_key_last($candles)]['time'] < $currentCandle['time']) {
                $candles[] = $currentCandle;
            } elseif ($candles[array_key_last($candles)]['time'] === $currentCandle['time']) {
                $candles[array_key_last($candles)] = $currentCandle;
            }
        }

        return ['candles' => $candles, 'source' => 'NBRB'];
    }

    public function dailyChange(Currency $currency): ?float
    {
        $candles = $this->chart($currency, 7)['candles'];
        if (count($candles) < 2) {
            return null;
        }

        $previous = (float) $candles[array_key_last($candles) - 1]['close'];
        $latest = (float) $candles[array_key_last($candles)]['close'];

        return $previous > 0 ? (($latest / $previous) - 1) * 100 : null;
    }

    /** @param list<Currency> $currencies @return array<string, ?float> */
    public function dailyChanges(array $currencies): array
    {
        $changes = [];
        foreach ($currencies as $currency) {
            $changes[$currency->code] = $this->dailyChange($currency);
        }

        return $changes;
    }
}
