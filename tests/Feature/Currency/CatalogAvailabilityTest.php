<?php

namespace Tests\Feature\Currency;

use App\Currency\Enums\ProviderCapability;
use App\Currency\Services\CurrencyCatalog;
use App\Currency\Services\ProviderSelectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CatalogAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private ?string $unavailableProvider = null;

    public function test_saved_catalog_expires_after_seven_days(): void
    {
        $this->freezeTime();
        $this->fakeHealthyCatalog();
        $this->getJson('/currencies')->assertOk();
        $this->travel(8)->days();
        app()->forgetInstance(CurrencyCatalog::class);
        $this->unavailableProvider = 'kraken';

        $this->getJson('/currencies')->assertServiceUnavailable()
            ->assertJsonPath('isFallback', false)
            ->assertJsonMissing(['code' => 'BTC', 'type' => 'crypto']);
    }

    public function test_unavailable_base_crypto_catalog_returns_safe_batch_error_not_500(): void
    {
        $this->fakeHealthyCatalog();
        $this->unavailableProvider = 'kraken';

        $this->postJson('/conversions', ['from' => 'BTC', 'fromType' => 'crypto', 'targets' => ['USD']])
            ->assertServiceUnavailable()->assertJsonPath('code', 'provider_unavailable');
    }

    public function test_failed_crypto_catalog_is_not_cached_as_a_successful_fiat_only_list(): void
    {
        $this->fakeHealthyCatalog();
        $this->unavailableProvider = 'kraken';
        $this->getJson('/currencies')->assertServiceUnavailable()
            ->assertJsonPath('isComplete', false)->assertJsonPath('isFallback', false);

        app()->forgetInstance(CurrencyCatalog::class);
        $this->unavailableProvider = null;
        $this->getJson('/currencies')->assertOk()->assertJsonPath('isComplete', true)
            ->assertJsonFragment(['code' => 'BTC', 'type' => 'crypto']);
    }

    public function test_catalog_outage_keeps_last_successful_list_of_the_same_source(): void
    {
        $this->freezeTime();
        $this->fakeHealthyCatalog();
        $this->getJson('/currencies')->assertOk()->assertJsonPath('isComplete', true);
        $this->travel(31)->minutes();
        app()->forgetInstance(CurrencyCatalog::class);
        $this->unavailableProvider = 'kraken';

        $this->getJson('/currencies')->assertOk()
            ->assertJsonPath('isComplete', false)->assertJsonPath('isFallback', true)
            ->assertJsonPath('cryptoProvider', 'kraken')
            ->assertJsonFragment(['code' => 'BTC', 'type' => 'crypto']);
    }

    public function test_switching_crypto_source_does_not_reuse_another_providers_saved_catalog(): void
    {
        $this->fakeHealthyCatalog();
        $this->getJson('/currencies')->assertOk();
        $this->travel(7)->hours();
        app(ProviderSelectionService::class)->select(ProviderCapability::CRYPTO_RATES, 'coingecko');
        app()->forgetInstance(CurrencyCatalog::class);
        $this->unavailableProvider = 'coingecko';

        $this->getJson('/currencies')->assertServiceUnavailable()
            ->assertJsonPath('cryptoProvider', 'coingecko')->assertJsonPath('isFallback', false)
            ->assertJsonMissing(['code' => 'BTC', 'type' => 'crypto']);
    }

    private function fakeHealthyCatalog(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.kraken.com/0/public/AssetPairs*' => fn () => $this->unavailableProvider === 'kraken' ? Http::response([], 503) : Http::response([
                'error' => [], 'result' => ['XBTUSD' => ['base' => 'XXBT', 'quote' => 'ZUSD', 'altname' => 'XBTUSD']],
            ]),
            'https://api.nbrb.by/exrates/rates*' => Http::response([]),
            'https://api.coingecko.com/api/v3/coins/markets*' => fn () => $this->unavailableProvider === 'coingecko' ? Http::response([], 503) : Http::response([]),
        ]);
    }
}
