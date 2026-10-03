<?php

namespace App\Currency\Providers;

use App\Currency\Contracts\DailyChangeProviderInterface;
use App\Currency\Contracts\MarketDataProviderInterface;
use App\Currency\DTO\ExchangeRate;
use App\Currency\Enums\Currency;
use App\Currency\Enums\CurrencyType;
use App\Currency\Exceptions\ProviderResponseException;
use App\Currency\Exceptions\UnsupportedCurrencyPairException;
use App\Currency\Services\ExternalApiClientFactory;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use ValueError;

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

        $catalog = $this->clients->get('nbrb', 'rates', ['periodicity' => 0]);
        if (! is_array($catalog->json())) {
            throw new ProviderResponseException('NBRB returned invalid market data.');
        }
        $record = collect($catalog->json())->first(fn ($item) => is_array($item) && ($item['Cur_Abbreviation'] ?? null) === $currency->code);
        if (! is_array($record) || ! is_int($record['Cur_ID'] ?? null) || $record['Cur_ID'] <= 0) {
            throw new ProviderResponseException('NBRB market data is unavailable.');
        }

        $end = DateTimeImmutable::createFromInterface(now('Europe/Minsk'))->setTime(0, 0);
        $history = $this->clients->get('nbrb', 'rates/dynamics/'.$record['Cur_ID'], [
            'startdate' => $end->modify("-{$days} days")->format('Y-m-d'), 'enddate' => $end->format('Y-m-d'),
        ]);
        if (! is_array($history->json())) {
            throw new ProviderResponseException('NBRB market data is unavailable.');
        }

        $scale = ExchangeRate::positiveDecimal($record['Cur_Scale'] ?? null);
        $candles = array_map(fn (mixed $item): array => $this->candle($item, $scale), $history->json());
        usort($candles, static fn (array $left, array $right): int => $left['time'] <=> $right['time']);

        // The dynamics endpoint can end at the previous publication when the
        // current official rate was updated separately. Keep every period
        // aligned to the same latest NBRB observation.
        if (isset($record['Date'], $record['Cur_OfficialRate'])) {
            $currentCandle = $this->candle($record, $scale);
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

        $previous = ExchangeRate::positiveDecimal($candles[array_key_last($candles) - 1]['close']);
        $latest = ExchangeRate::positiveDecimal($candles[array_key_last($candles)]['close']);
        $change = $latest->dividedBy($previous, 18, RoundingMode::HalfUp)->minus(1)->multipliedBy(100)->toFloat();
        if (! is_finite($change)) {
            throw new ProviderResponseException('NBRB returned invalid daily changes.');
        }

        return $change;
    }

    /** @return array{time: int, open: string, high: string, low: string, close: string} */
    private function candle(mixed $record, BigDecimal $scale): array
    {
        if (! is_array($record) || ! is_string($record['Date'] ?? null)
            || ! preg_match('/^\d{4}-\d{2}-\d{2}(?:T.*)?$/D', $record['Date'])) {
            throw new ProviderResponseException('NBRB returned invalid market data.');
        }
        try {
            $date = new DateTimeImmutable($record['Date'], new DateTimeZone('Europe/Minsk'));
        } catch (Exception|ValueError $exception) {
            throw new ProviderResponseException('NBRB returned invalid market data.', previous: $exception);
        }
        if (DateTimeImmutable::getLastErrors() !== false) {
            throw new ProviderResponseException('NBRB returned invalid market data.');
        }
        $rate = ExchangeRate::positiveDecimal($record['Cur_OfficialRate'] ?? null)->dividedBy($scale, 12, RoundingMode::HalfUp)->__toString();
        ExchangeRate::positiveDecimal($rate);

        return ['time' => $date->setTimezone(new DateTimeZone('Europe/Minsk'))->setTime(12, 0)->getTimestamp(),
            'open' => $rate, 'high' => $rate, 'low' => $rate, 'close' => $rate];
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
