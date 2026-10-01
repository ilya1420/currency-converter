<?php

namespace Tests\Feature\Currency;

use App\Currency\Enums\ProviderCapability;
use App\Currency\Services\ProviderSelectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ProviderSelectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_uses_the_highest_priority_provider_until_user_selection_is_saved(): void
    {
        $selections = app(ProviderSelectionService::class);

        $this->assertSame('kraken', $selections->selected(ProviderCapability::CRYPTO_RATES)->id);
        $this->assertDatabaseMissing('provider_selections', ['capability' => 'crypto_rates']);
    }

    public function test_it_persists_an_override_for_only_the_selected_capability(): void
    {
        config(['currency.coingecko.api_key' => 'test-demo-key']);
        $selections = app(ProviderSelectionService::class);
        $selections->select(ProviderCapability::CRYPTO_RATES, 'coingecko');

        $this->assertSame('coingecko', app(ProviderSelectionService::class)->selected(ProviderCapability::CRYPTO_RATES)->id);
        $this->assertSame('nbrb', $selections->selected(ProviderCapability::FIAT_RATES)->id);
        $this->assertDatabaseHas('provider_selections', [
            'capability' => 'crypto_rates',
            'provider_id' => 'coingecko',
        ]);
        $this->assertDatabaseMissing('provider_selections', ['capability' => 'fiat_rates']);
    }

    public function test_it_rejects_a_provider_that_does_not_support_the_requested_capability(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(ProviderSelectionService::class)->select(ProviderCapability::CRYPTO_RATES, 'nbrb');
    }

    public function test_reset_removes_the_override_and_restores_the_default_provider(): void
    {
        config(['currency.coingecko.api_key' => 'test-demo-key']);
        $selections = app(ProviderSelectionService::class);
        $selections->select(ProviderCapability::CRYPTO_RATES, 'coingecko');

        $this->assertSame('kraken', $selections->reset(ProviderCapability::CRYPTO_RATES)->id);
        $this->assertDatabaseMissing('provider_selections', ['capability' => 'crypto_rates']);
    }
}
