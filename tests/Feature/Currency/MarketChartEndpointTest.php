<?php

namespace Tests\Feature\Currency;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class MarketChartEndpointTest extends TestCase
{
    use RefreshDatabase;

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
            ->assertJson(['message' => 'Market data is temporarily unavailable.'])
            ->assertJsonMissing(['message' => 'upstream detail must not be exposed']);
    }
}
