<?php

namespace App\Http\Controllers;

use App\Currency\Enums\Currency;
use App\Currency\Exceptions\RateUnavailableException;
use App\Currency\Exceptions\UnsupportedCurrencyPairException;
use App\Currency\Services\AmountFormatter;
use App\Currency\Services\ConversionService;
use App\Currency\Services\DecimalCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class ConversionController
{
    public function __invoke(Request $request, ConversionService $converter, DecimalCalculator $calculator, AmountFormatter $formatter): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'regex:/^\d+(?:[.,]\d+)?$/'],
            'from' => ['required', Rule::enum(Currency::class)],
            'to' => ['required', Rule::enum(Currency::class)],
            'refresh' => ['sometimes', 'boolean'],
        ]);
        $amount = str_replace(',', '.', $data['amount']);
        try {
            $result = $converter->convert($amount, Currency::from($data['from']), Currency::from($data['to']), (bool) ($data['refresh'] ?? false));
        } catch (RateUnavailableException) {
            return response()->json(['message' => 'No connection and no saved rate is available for this conversion.'], 503);
        } catch (UnsupportedCurrencyPairException) {
            return response()->json(['message' => 'This currency pair is not supported yet.'], 422);
        }

        $factor = $amount === '0' ? '0' : $calculator->divide($result->targetAmount, $amount);
        $sources = array_values(array_unique(array_map(static fn ($rate): string => $rate->source->value, $result->ratesUsed)));

        return response()->json([
            'sourceAmount' => $result->sourceAmount,
            'targetAmount' => $result->targetAmount,
            'targetDisplay' => $formatter->format($result->targetAmount, $result->to),
            'factor' => $factor,
            'factorDisplay' => $formatter->format($factor, $result->to),
            'sources' => $sources,
            'isStale' => $result->isStale,
            'updatedAt' => $result->rateUpdatedAt->format(DATE_ATOM),
        ]);
    }
}
