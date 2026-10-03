<?php

namespace Tests\Feature\Currency;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class MarketChartEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_chart_rate_limit_preserves_retry_after_and_safe_error_code(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.kraken.com/0/public/AssetPairs*' => Http::response([
                'error' => [], 'result' => ['XBTUSD' => ['base' => 'XXBT', 'quote' => 'ZUSD', 'altname' => 'XBTUSD']],
            ]),
            'https://api.nbrb.by/exrates/rates*' => Http::response([]),
            'https://api.coingecko.com/api/v3/coins/markets*' => Http::response([]),
            'https://api.kraken.com/0/public/OHLC*' => Http::response([], 429, ['Retry-After' => '17']),
        ]);

        $this->getJson('/market/BTC?type=crypto&interval=60')->assertTooManyRequests()
            ->assertHeader('Retry-After', '17')->assertJsonPath('code', 'provider_rate_limited');
    }

    public function test_provider_connection_failure_returns_a_safe_503_response(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.nbrb.by/exrates/rates?periodicity=0' => Http::response((string) file_get_contents(base_path('tests/Fixtures/nbrb/nbrb-usd.json'))),
            'https://api.kraken.com/0/public/AssetPairs*' => Http::response([
                'error' => [],
                'result' => ['XBTUSD' => ['base' => 'XXBT', 'quote' => 'ZUSD', 'altname' => 'XBTUSD']],
            ]),
            'https://api.coingecko.com/api/v3/coins/markets*' => Http::response([]),
        ]);
        $this->getJson('/currencies')->assertOk();

        Http::fake([
            'https://api.kraken.com/0/public/OHLC*' => Http::failedConnection(),
        ]);

        $this->getJson('/market/BTC?type=crypto&interval=60')
            ->assertServiceUnavailable()
            ->assertJsonPath('code', 'provider_timeout')
            ->assertJson(['message' => 'Провайдер не ответил вовремя. Попробуйте позже.'])
            ->assertJsonMissing(['message' => 'upstream detail must not be exposed']);
    }
}
