<?php

namespace App\Http\Controllers;

use App\Currency\Exceptions\ProviderException;
use App\Currency\Exceptions\ProviderRateLimitException;
use App\Currency\Exceptions\RateUnavailableException;
use App\Currency\Exceptions\UnsupportedCurrencyPairException;
use App\Currency\Services\ConversionService;
use App\Currency\Services\CurrencyCatalog;
use App\Http\Presenters\ConversionPresenter;
use App\Http\Requests\ConvertCurrencyRequest;
use Illuminate\Http\JsonResponse;

final class ConversionController
{
    public function __invoke(ConvertCurrencyRequest $request, ConversionService $converter, CurrencyCatalog $catalog, ConversionPresenter $presenter): JsonResponse
    {
        $data = $request->validated();
        $amount = str_replace(',', '.', $data['amount']);
        try {
            $result = $converter->convert($amount, $catalog->resolve($data['from'], $data['fromType'] ?? null), $catalog->resolve($data['to'], $data['toType'] ?? null), (bool) ($data['refresh'] ?? false));
        } catch (ProviderRateLimitException $exception) {
            return response()->json(['message' => 'Провайдер временно ограничил частоту запросов.'], 429, $exception->retryAfter ? ['Retry-After' => $exception->retryAfter] : []);
        } catch (RateUnavailableException|ProviderException) {
            return response()->json(['message' => 'No connection and no saved rate is available for this conversion.'], 503);
        } catch (UnsupportedCurrencyPairException) {
            return response()->json(['message' => 'This currency pair is not supported yet.'], 422);
        }

        return response()->json($presenter->detail($result));
    }
}
