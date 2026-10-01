<?php

namespace App\Http\Controllers;

use App\Currency\Exceptions\ProviderException;
use App\Currency\Exceptions\RateUnavailableException;
use App\Currency\Exceptions\UnsupportedCurrencyPairException;
use App\Currency\Services\ConversionService;
use App\Currency\Services\CurrencyCatalog;
use App\Http\Presenters\ConversionFailurePresenter;
use App\Http\Presenters\ConversionPresenter;
use App\Http\Requests\ConvertCurrencyRequest;
use Illuminate\Http\JsonResponse;

final class ConversionController
{
    public function __invoke(ConvertCurrencyRequest $request, ConversionService $converter, CurrencyCatalog $catalog, ConversionPresenter $presenter, ConversionFailurePresenter $failurePresenter): JsonResponse
    {
        $data = $request->validated();
        $amount = str_replace(',', '.', $data['amount']);
        try {
            $result = $converter->convert($amount, $catalog->resolve($data['from'], $data['fromType'] ?? null), $catalog->resolve($data['to'], $data['toType'] ?? null), (bool) ($data['refresh'] ?? false));
        } catch (RateUnavailableException|ProviderException|UnsupportedCurrencyPairException $exception) {
            $failure = $failurePresenter->present($exception);
            $headers = $failure['retryAfter'] !== null ? ['Retry-After' => (string) $failure['retryAfter']] : [];

            return response()->json($failure, $failure['status'], $headers);
        }

        return response()->json($presenter->detail($result));
    }
}
