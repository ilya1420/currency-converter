<?php

namespace Tests\Feature;

use App\Currency\Services\ExternalApiClientFactory;
use App\Currency\Services\ProviderCredentialService;
use App\Models\ProviderCredential;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProviderCredentialSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
    }

    public function test_it_verifies_and_encrypts_a_coingecko_key_without_returning_it(): void
    {
        Http::fake(['api.coingecko.com/*' => Http::response(['gecko_says' => '(V3) To the Moon!'])]);

        $this->putJson('/provider-settings/coingecko', ['api_key' => 'demo-secret'])
            ->assertOk()
            ->assertJsonPath('configured', true)
            ->assertJsonMissing(['api_key' => 'demo-secret']);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.coingecko.com/api/v3/ping'
            && $request->hasHeader('x-cg-demo-api-key', 'demo-secret'));

        $credential = ProviderCredential::query()->where('provider_id', 'coingecko')->firstOrFail();
        $this->assertNotSame('demo-secret', $credential->getRawOriginal('settings'));
        $this->assertSame('demo-secret', $credential->settings['api_key']);

        $this->getJson('/provider-settings')
            ->assertOk()
            ->assertJsonPath('provider_settings.coingecko.configured', true)
            ->assertJsonMissing(['api_key' => 'demo-secret']);
    }

    public function test_it_does_not_save_an_invalid_coingecko_key(): void
    {
        Http::fake(['api.coingecko.com/*' => Http::response(['error' => 'Invalid API key'], 401)]);
        app(ProviderCredentialService::class)
            ->saveApiKey('coingecko', 'previous-valid-key');

        $this->putJson('/provider-settings/coingecko', ['api_key' => 'invalid-secret'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Ключ CoinGecko не прошёл проверку. Проверьте ключ и доступность API.');

        $this->assertSame(
            'previous-valid-key',
            ProviderCredential::query()->where('provider_id', 'coingecko')->firstOrFail()->settings['api_key'],
        );
    }

    public function test_it_does_not_allow_selecting_coingecko_before_configuring_a_key(): void
    {
        $this->patchJson('/provider-settings/crypto_rates', ['provider_id' => 'coingecko'])
            ->assertUnprocessable()
            ->assertJsonPath('message', 'Сначала добавьте и проверьте ключ этого провайдера.');

        $this->assertDatabaseMissing('provider_selections', ['capability' => 'crypto_rates']);
    }

    public function test_the_configured_key_is_used_for_regular_coingecko_requests(): void
    {
        Http::fake(['api.coingecko.com/*' => Http::response(['prices' => []])]);

        $this->app->make(ProviderCredentialService::class)
            ->saveApiKey('coingecko', 'stored-secret');

        $this->app->make(ExternalApiClientFactory::class)->for('coingecko')->get('/simple/price');

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.coingecko.com/api/v3/simple/price'
            && $request->hasHeader('x-cg-demo-api-key', 'stored-secret'));
    }

    public function test_crypto_daily_changes_default_to_keyless_kraken(): void
    {
        $this->getJson('/provider-settings')
            ->assertOk()
            ->assertJsonPath('capabilities.crypto_daily_changes.default', 'kraken');
    }
}
