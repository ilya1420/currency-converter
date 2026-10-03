<?php

namespace Tests\Feature\Currency;

use App\Currency\Enums\ProviderCapability;
use App\Currency\Exceptions\ProviderException;
use App\Currency\Providers\CoinGeckoRateProvider;
use App\Currency\Providers\KrakenRateProvider;
use App\Currency\Services\ProviderSelectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ProviderSelectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_runtime_candidates_follow_priority_instead_of_injected_order(): void
    {
        $kraken = app(KrakenRateProvider::class);
        $coingecko = app(CoinGeckoRateProvider::class);
        $selection = app(ProviderSelectionService::class);

        $this->assertSame([$kraken, $coingecko], $selection->candidates(ProviderCapability::CRYPTO_RATES, [$coingecko, $kraken]));
        $oldIdentity = $selection->cacheIdentity(ProviderCapability::CRYPTO_RATES);
        config(['currency.providers.registry.kraken.requires_api_key' => true]);
        $this->assertSame([$coingecko], $selection->candidates(ProviderCapability::CRYPTO_RATES, [$coingecko, $kraken]));
        $this->assertNotSame($oldIdentity, $selection->cacheIdentity(ProviderCapability::CRYPTO_RATES));
    }

    public function test_explicit_selection_with_a_lost_required_key_does_not_switch_provider(): void
    {
        $selection = app(ProviderSelectionService::class);
        $selection->select(ProviderCapability::CRYPTO_RATES, 'kraken');
        config(['currency.providers.registry.kraken.requires_api_key' => true]);

        $this->expectException(ProviderException::class);
        $selection->candidates(ProviderCapability::CRYPTO_RATES, [app(CoinGeckoRateProvider::class)]);
    }

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
