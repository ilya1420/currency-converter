<?php

namespace Tests\Unit\Currency;

use App\Currency\Contracts\CurrencyCatalogProviderInterface;
use App\Currency\Enums\CurrencyType;
use App\Currency\Services\CurrencyCache;
use App\Currency\Services\CurrencyCatalog;
use App\Currency\Services\ProviderSelectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CurrencyCatalogTest extends TestCase
{
    use RefreshDatabase;

    public function test_byn_is_available_when_remote_catalogs_are_empty(): void
    {
        Cache::forget('currency-catalog:v5');

        $catalog = new CurrencyCatalog([new class implements CurrencyCatalogProviderInterface
        {
            public function currencies(): array
            {
                return [];
            }
        }], app(CurrencyCache::class), app(ProviderSelectionService::class));

        $currency = $catalog->resolve('BYN', 'fiat');
        $usd = $catalog->resolve('USD', 'fiat');

        $this->assertSame('BYN', $currency->code);
        $this->assertSame(CurrencyType::FIAT, $currency->type);
        $this->assertSame('USD', $usd->code);
    }
}
