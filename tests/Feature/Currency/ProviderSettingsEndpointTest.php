<?php

namespace Tests\Feature\Currency;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProviderSettingsEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_endpoint_lists_available_rate_providers_and_defaults(): void
    {
        $this->getJson('/provider-settings')
            ->assertOk()
            ->assertJsonPath('capabilities.fiat_rates.selected', 'nbrb')
            ->assertJsonPath('capabilities.crypto_rates.selected', 'kraken')
            ->assertJsonPath('capabilities.crypto_rates.providers.1.id', 'coingecko');
    }

    public function test_it_saves_a_valid_rate_provider_selection(): void
    {
        $this->patchJson('/provider-settings/crypto_rates', ['provider_id' => 'coingecko'])
            ->assertOk()
            ->assertJsonPath('selected', 'coingecko');

        $this->assertDatabaseHas('provider_selections', [
            'capability' => 'crypto_rates',
            'provider_id' => 'coingecko',
        ]);
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
        $this->patchJson('/provider-settings/crypto_rates', ['provider_id' => 'coingecko'])->assertOk();

        $this->deleteJson('/provider-settings/crypto_rates')
            ->assertOk()
            ->assertJsonPath('selected', 'kraken');

        $this->assertDatabaseMissing('provider_selections', ['capability' => 'crypto_rates']);
    }

    public function test_it_does_not_expose_unimplemented_capability_settings(): void
    {
        $this->patchJson('/provider-settings/market', ['provider_id' => 'kraken'])->assertNotFound();
    }
}
