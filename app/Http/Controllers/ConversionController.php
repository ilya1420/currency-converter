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

class ConversionController extends Controller
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

        return response()->json([
            'sourceAmount' => $result->sourceAmount,
            'targetAmount' => $result->targetAmount,
            'targetDisplay' => $formatter->format($result->targetAmount, $result->to),
            'factor' => $amount === '0' ? '0' : $calculator->divide($result->targetAmount, $amount),
            'isStale' => $result->isStale,
            'updatedAt' => $result->rateUpdatedAt->format(DATE_ATOM),
        ]);
    }
}
