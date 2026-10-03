<?php

namespace App\Currency\Providers;

use App\Currency\Contracts\CurrencyCatalogProviderInterface;
use App\Currency\DTO\CurrencyDefinition;
use App\Currency\Enums\CurrencyType;
use App\Currency\Exceptions\ProviderResponseException;
use App\Currency\Services\ExternalApiClientFactory;

final class NbrbCurrencyCatalogProvider implements CurrencyCatalogProviderInterface
{
    public function __construct(private ExternalApiClientFactory $clients) {}

    public function currencies(): array
    {
        $response = $this->clients->get('nbrb', 'rates', ['periodicity' => 0]);

        if (! is_array($response->json())) {
            throw new ProviderResponseException('NBRB currency catalog is unavailable.');
        }

        $currencies = [new CurrencyDefinition('BYN', CurrencyType::FIAT)];
        foreach ($response->json() as $record) {
            $code = is_array($record) ? $record['Cur_Abbreviation'] ?? null : null;
            if (is_string($code) && preg_match('/^[A-Z]{3}$/', $code)) {
                $name = $record['Cur_Name'] ?? null;
                $currencies[] = new CurrencyDefinition($code, CurrencyType::FIAT, null, is_string($name) ? $name : null);
            }
        }

        return $currencies;
    }
}
