<?php

namespace App\Http\Controllers;

use App\Currency\Exceptions\ProviderException;
use App\Currency\Exceptions\UnsupportedCurrencyPairException;
use App\Currency\Services\CurrencyCatalog;
use App\Currency\Services\MarketChartService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class MarketChartController
{
    public function __invoke(Request $request, string $currency, MarketChartService $market, CurrencyCatalog $catalog): JsonResponse
    {
        try {
            $asset = $catalog->resolve($currency, $request->string('type')->toString() ?: null);
            $interval = (int) $request->integer('interval', $asset->type->value === 'fiat' ? 30 : 60);
            if ($asset->type->value === 'fiat') {
                if (! in_array($interval, [7, 30, 365], true)) {
                    return response()->json(['message' => 'Unsupported chart interval.'], 422);
                }

                return response()->json($market->chart($asset, $interval));
            }
            if (! in_array($interval, [1, 5, 15, 30, 60, 240, 1440], true)) {
                return response()->json(['message' => 'Unsupported chart interval.'], 422);
            }

            return response()->json($market->chart($asset, $interval));
        } catch (UnsupportedCurrencyPairException) {
            return response()->json(['message' => 'Charts are unavailable for this currency.'], 422);
        } catch (ProviderException) {
            return response()->json(['message' => 'Market data is temporarily unavailable.'], 503);
        }
    }
}
