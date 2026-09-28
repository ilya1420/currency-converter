<?php

namespace Tests\Unit\Currency;

use App\Currency\Contracts\CurrencyCatalogProviderInterface;
use App\Currency\Enums\CurrencyType;
use App\Currency\Services\CurrencyCatalog;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CurrencyCatalogTest extends TestCase
{
    public function test_byn_is_available_when_remote_catalogs_are_empty(): void
    {
        Cache::forget('currency-catalog:v3');

        $catalog = new CurrencyCatalog([new class implements CurrencyCatalogProviderInterface
        {
            public function currencies(): array
            {
                return [];
            }
        }]);

        $currency = $catalog->resolve('BYN', 'fiat');

        $this->assertSame('BYN', $currency->code);
        $this->assertSame(CurrencyType::FIAT, $currency->type);
    }
}
