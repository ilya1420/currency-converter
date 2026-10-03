<?php

namespace Tests\Feature\Currency;

use App\Currency\Services\ProviderRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderSettingsEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_default_skips_a_provider_with_a_missing_required_key(): void
    {
        config(['currency.providers.registry.kraken.requires_api_key' => true]);

        $this->getJson('/provider-settings')
            ->assertOk()
            ->assertJsonPath('capabilities.crypto_rates.default', 'coingecko')
            ->assertJsonPath('capabilities.crypto_market_data.default', null);
    }

    public function test_settings_endpoint_lists_available_rate_providers_and_defaults(): void
    {
        $this->getJson('/provider-settings')
            ->assertOk()
            ->assertJsonPath('capabilities.fiat_rates.selected', null)
            ->assertJsonPath('capabilities.fiat_rates.default', 'nbrb')
            ->assertJsonPath('providers.0.id', 'nbrb')
            ->assertJsonPath('providers.0.capabilities.0', 'catalog')
            ->assertJsonPath('providers.0.capabilities.1', 'fiat_rates')
            ->assertJsonPath('providers.0.capabilities.2', 'fiat_daily_changes')
            ->assertJsonPath('providers.0.capabilities.3', 'fiat_market_data')
            ->assertJsonPath('capabilities.crypto_rates.selected', null)
            ->assertJsonPath('capabilities.crypto_rates.default', 'kraken')
            ->assertJsonPath('capabilities.crypto_rates.providers.1.id', 'coingecko')
            ->assertJsonPath('providers.1.capabilities.1', 'crypto_rates')
            ->assertJsonPath('providers.1.capabilities.3', 'crypto_market_data')
            ->assertJsonPath('providers.2.capabilities.2', 'crypto_daily_changes')
            ->assertJsonPath('capabilities.fiat_market_data.default', 'nbrb')
            ->assertJsonPath('capabilities.crypto_market_data.default', 'kraken');
    }

    public function test_it_saves_a_valid_rate_provider_selection(): void
    {
        config(['currency.coingecko.api_key' => 'valid-demo-key']);

        $this->patchJson('/provider-settings/crypto_rates', ['provider_id' => 'coingecko'])
            ->assertOk()
            ->assertJsonPath('selected', 'coingecko');

        $this->assertDatabaseHas('provider_selections', [
            'capability' => 'crypto_rates',
            'provider_id' => 'coingecko',
        ]);
    }

    public function test_settings_endpoint_omits_capabilities_without_compatible_providers(): void
    {
        app()->instance(ProviderRegistry::class, new ProviderRegistry([
            'nbrb' => config('currency.providers.registry.nbrb'),
        ]));

        $this->getJson('/provider-settings')
            ->assertOk()
            ->assertJsonMissingPath('capabilities.crypto_rates')
            ->assertJsonMissingPath('capabilities.crypto_market_data');
    }

    public function test_it_rejects_provider_that_does_not_support_capability(): void
    {
        $this->patchJson('/provider-settings/crypto_rates', ['provider_id' => 'nbrb'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Этот провайдер не поддерживает выбранный тип курсов.');

        $this->assertDatabaseMissing('provider_selections', ['capability' => 'crypto_rates']);
    }

    public function test_it_resets_selection_to_default(): void
    {
        config(['currency.coingecko.api_key' => 'valid-demo-key']);

        $this->patchJson('/provider-settings/crypto_rates', ['provider_id' => 'coingecko'])->assertOk();

        $this->deleteJson('/provider-settings/crypto_rates')
            ->assertOk()
            ->assertJsonPath('selected', null)
            ->assertJsonPath('default', 'kraken');

        $this->assertDatabaseMissing('provider_selections', ['capability' => 'crypto_rates']);
    }

    public function test_it_rejects_unknown_capability_settings(): void
    {
        $this->patchJson('/provider-settings/market', ['provider_id' => 'kraken'])->assertNotFound();
    }
}
