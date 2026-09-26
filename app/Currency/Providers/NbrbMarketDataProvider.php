<?php

namespace App\Currency\Providers;

use App\Currency\Enums\Currency;
use App\Currency\Enums\CurrencyType;
use App\Currency\Exceptions\ProviderException;
use App\Currency\Exceptions\UnsupportedCurrencyPairException;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\Http;

final class NbrbMarketDataProvider
{
    /** @return array{candles: list<array{time: int, open: string, high: string, low: string, close: string}>, source: string} */
    public function history(Currency $currency, int $days): array
    {
        if ($currency === Currency::BYN || $currency->type() !== CurrencyType::FIAT) {
            throw new UnsupportedCurrencyPairException('NBRB history is unavailable for this currency.');
        }

        $client = Http::baseUrl(config('currency.nbrb.base_url'))->acceptJson()->connectTimeout(3)->timeout(5);
        $catalog = $client->get('rates', ['periodicity' => 0]);
        $record = collect($catalog->json())->first(fn ($item) => is_array($item) && ($item['Cur_Abbreviation'] ?? null) === $currency->value);
        if ($catalog->failed() || ! is_array($record)) throw new ProviderException('NBRB market data is unavailable.');

        $end = new DateTimeImmutable('today');
        $history = $client->get('rates/dynamics/'.$record['Cur_ID'], [
            'startdate' => $end->modify("-{$days} days")->format('Y-m-d'), 'enddate' => $end->format('Y-m-d'),
        ]);
        if ($history->failed() || ! is_array($history->json())) throw new ProviderException('NBRB market data is unavailable.');

        $scale = (string) ($record['Cur_Scale'] ?? 1);
        return ['candles' => array_map(static function (array $item) use ($scale): array {
            $rate = BigDecimal::of((string) $item['Cur_OfficialRate'])->dividedBy($scale, 12, RoundingMode::HalfUp)->__toString();
            $time = (new DateTimeImmutable((string) $item['Date'], new DateTimeZone('Europe/Minsk')))->setTime(12, 0)->getTimestamp();
            return ['time' => $time, 'open' => $rate, 'high' => $rate, 'low' => $rate, 'close' => $rate];
        }, $history->json()), 'source' => 'NBRB'];
    }
}
