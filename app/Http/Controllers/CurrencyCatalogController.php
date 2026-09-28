<?php

namespace App\Http\Controllers;

use App\Currency\Exceptions\ProviderException;
use App\Currency\Services\CurrencyCatalog;
use Illuminate\Http\JsonResponse;

final class CurrencyCatalogController
{
    private const CURRENCY_COUNTRIES = [
        'AED' => 'ae', 'AMD' => 'am', 'AUD' => 'au', 'BGN' => 'bg', 'BRL' => 'br', 'BYN' => 'by',
        'CAD' => 'ca', 'CHF' => 'ch', 'CNY' => 'cn', 'CZK' => 'cz', 'DKK' => 'dk', 'EUR' => 'eu',
        'GBP' => 'gb', 'GEL' => 'ge', 'HKD' => 'hk', 'HUF' => 'hu', 'IDR' => 'id', 'INR' => 'in',
        'IRR' => 'ir', 'ISK' => 'is', 'JPY' => 'jp', 'KGS' => 'kg', 'KRW' => 'kr', 'KWD' => 'kw',
        'MDL' => 'md', 'MXN' => 'mx', 'NOK' => 'no', 'NZD' => 'nz', 'PLN' => 'pl', 'RON' => 'ro',
        'RUB' => 'ru', 'SAR' => 'sa', 'SEK' => 'se', 'SGD' => 'sg', 'TRY' => 'tr', 'UAH' => 'ua',
        'USD' => 'us', 'VND' => 'vn', 'ZAR' => 'za',
    ];

    public function __invoke(CurrencyCatalog $catalog): JsonResponse
    {
        $currencies = [];

        try {
            foreach ($catalog->all() as $currency) {
                $currencies["{$currency->type->value}:{$currency->code}"] = [
                    'code' => $currency->code,
                    'type' => $currency->type->value,
                    'providerSymbol' => $currency->providerSymbol,
                    'name' => $currency->name,
                    'group' => $currency->group,
                    'icon' => $currency->type->value === 'crypto' ? $this->localCryptoIcon($currency->code) : null,
                    'flag' => $currency->type->value === 'fiat' ? $this->localFiatFlag($currency->code) : null,
                ];
            }
        } catch (ProviderException) {
            return response()->json(['message' => 'Currency catalog is temporarily unavailable.'], 503);
        }

        return response()->json(['currencies' => array_values($currencies)]);
    }

    private function localCryptoIcon(string $code): ?string
    {
        $filename = strtolower($code);

        if (! preg_match('/^[a-z0-9$]+$/', $filename) || ! is_file(public_path("images/currencies/{$filename}.svg"))) {
            return null;
        }

        return "/images/currencies/{$filename}.svg";
    }

    private function localFiatFlag(string $code): ?string
    {
        $country = self::CURRENCY_COUNTRIES[$code] ?? null;

        if ($country === null || ! is_file(public_path("images/flags/{$country}.svg"))) {
            return null;
        }

        return "/images/flags/{$country}.svg";
    }
}
