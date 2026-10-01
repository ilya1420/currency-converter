<?php

namespace App\Http\Controllers;

use App\Currency\Exceptions\ProviderException;
use App\Currency\Exceptions\RateUnavailableException;
use App\Currency\Exceptions\UnsupportedCurrencyPairException;
use App\Currency\Services\ConversionService;
use App\Currency\Services\CurrencyCatalog;
use App\Http\Presenters\ConversionPresenter;
use App\Http\Requests\BatchConvertCurrenciesRequest;
use Illuminate\Http\JsonResponse;

final class BatchConversionController
{
    public function __invoke(BatchConvertCurrenciesRequest $request, ConversionService $converter, CurrencyCatalog $catalog, ConversionPresenter $presenter): JsonResponse
    {
        $data = $request->validated();

        $from = $catalog->resolve($data['from'], $data['fromType'] ?? null);
        $refresh = (bool) ($data['refresh'] ?? false);
        $conversions = [];

        foreach (array_unique($data['targets']) as $target) {
            try {
                $result = $converter->convert('1', $from, $catalog->resolve($target), $refresh);
                $conversions[$target] = $presenter->factor($result);
            } catch (RateUnavailableException|ProviderException|UnsupportedCurrencyPairException) {
                $conversions[$target] = ['error' => 'Нет курса'];
            }
        }

        return response()->json(['conversions' => $conversions]);
    }
}
