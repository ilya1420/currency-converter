<?php

namespace App\Http\Controllers;

use App\Currency\Enums\Currency;
use App\Currency\Exceptions\ProviderException;
use App\Currency\Exceptions\UnsupportedCurrencyPairException;
use App\Currency\Providers\KrakenMarketDataProvider;
use App\Currency\Providers\NbrbMarketDataProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MarketChartController
{
    public function __invoke(Request $request, string $currency, KrakenMarketDataProvider $market, NbrbMarketDataProvider $nbrb): JsonResponse
    {
        try {
            $asset = Currency::from($currency);
            $interval = (int) $request->integer('interval', $asset->type()->value === 'fiat' ? 30 : 60);
            if ($asset->type()->value === 'fiat') {
                if (! in_array($interval, [7, 30, 365], true)) return response()->json(['message' => 'Unsupported chart interval.'], 422);
                return response()->json($nbrb->history($asset, $interval));
            }
            if (! in_array($interval, [1, 5, 15, 30, 60, 240, 1440], true)) return response()->json(['message' => 'Unsupported chart interval.'], 422);
            return response()->json($market->snapshot($asset, $interval) + ['source' => 'Kraken']);
        } catch (ValueError|UnsupportedCurrencyPairException) {
            return response()->json(['message' => 'Charts are unavailable for this currency.'], 422);
        } catch (ProviderException) {
            return response()->json(['message' => 'Market data is temporarily unavailable.'], 503);
        }
    }
}
