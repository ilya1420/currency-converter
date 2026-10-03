<?php

namespace Tests\Feature\Currency;

use App\Currency\Enums\ProviderCapability;
use App\Currency\Services\DailyChangeService;
use App\Currency\Services\ProviderSelectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class DailyChangeServiceTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('providerFailures')]
    public function test_it_preserves_failures_and_cools_down_across_requests(int $status, string $code, int $seconds): void
    {
        $this->travelTo('2026-10-03 12:00:00');
        $this->primeCatalog();
        Http::fake(['https://api.kraken.com/0/public/Ticker*' => Http::sequence()
            ->push([], $status, ['Retry-After' => (string) $seconds])
            ->push(['error' => [], 'result' => ['XBTUSD' => ['c' => ['110'], 'o' => '100']]])]);

        $first = app(DailyChangeService::class)->snapshot(['BTC']);
        $this->travel($seconds - 1)->seconds();
        $this->app->forgetInstance(DailyChangeService::class);
        $second = app(DailyChangeService::class)->snapshot(['ETH']);

        $this->assertNull($first['changes']['BTC']);
        $this->assertSame('error', $first['statuses']['BTC']['status']);
        $this->assertSame($code, $first['statuses']['BTC']['code']);
        $this->assertSame('kraken', $first['statuses']['BTC']['provider']);
        $this->assertSame($seconds, $first['statuses']['BTC']['retryAfter']);
        $this->assertSame($code, $second['statuses']['ETH']['code']);
        $this->assertSame(1, $second['statuses']['ETH']['retryAfter']);

        $this->travel(1)->seconds();
        $this->app->forgetInstance(DailyChangeService::class);
        $recovered = app(DailyChangeService::class)->snapshot(['BTC']);

        $this->assertSame(10.0, round($recovered['changes']['BTC'], 1));
        $this->assertSame('available', $recovered['statuses']['BTC']['status']);
        Http::assertSentCount(2);
    }

    /** @return array<string, array{int, string, int}> */
    public static function providerFailures(): array
    {
        return ['rate limit' => [429, 'provider_rate_limited', 45], 'server error' => [503, 'provider_unavailable', 30]];
    }

    public function test_it_returns_200_with_a_separate_timeout_status(): void
    {
        $this->primeCatalog();
        Http::fake(['https://api.kraken.com/0/public/Ticker*' => Http::failedConnection()]);

        $this->getJson('/daily-changes?currencies[]=BTC&currencies[]=BYN')
            ->assertOk()
            ->assertJsonPath('changes.BTC', null)
            ->assertJsonPath('statuses.BTC.status', 'error')
            ->assertJsonPath('statuses.BTC.code', 'provider_timeout')
            ->assertJsonPath('statuses.BTC.provider', 'kraken')
            ->assertJsonPath('statuses.BYN.status', 'unavailable')
            ->assertJsonPath('statuses.BYN.code', 'unsupported_asset');

        Http::assertSentCount(1);
    }

    public function test_it_distinguishes_missing_data_and_caches_it_briefly(): void
    {
        $this->freezeTime();
        $this->primeCatalog();
        app(ProviderSelectionService::class)->select(ProviderCapability::CRYPTO_DAILY_CHANGES, 'kraken');
        Http::fake(['https://api.kraken.com/0/public/Ticker*' => Http::sequence()
            ->push(['error' => [], 'result' => []])
            ->push(['error' => [], 'result' => ['XBTUSD' => ['c' => ['100'], 'o' => '100']]])]);

        $missing = app(DailyChangeService::class)->snapshot(['BTC']);
        $this->app->forgetInstance(DailyChangeService::class);
        app(DailyChangeService::class)->snapshot(['BTC']);
        $this->travel(60)->seconds();
        $this->app->forgetInstance(DailyChangeService::class);
        $recovered = app(DailyChangeService::class)->snapshot(['BTC']);

        $this->assertSame('unavailable', $missing['statuses']['BTC']['status']);
        $this->assertSame('no_data', $missing['statuses']['BTC']['code']);
        $this->assertSame(0.0, $recovered['changes']['BTC']);
        $this->assertSame('available', $recovered['statuses']['BTC']['status']);
        Http::assertSentCount(2);
    }

    public function test_a_provider_switch_does_not_reuse_another_providers_cooldown(): void
    {
        $this->primeCatalog();
        $selections = app(ProviderSelectionService::class);
        $selections->select(ProviderCapability::CRYPTO_DAILY_CHANGES, 'kraken');
        Http::fake([
            'https://api.kraken.com/0/public/Ticker*' => Http::response([], 429, ['Retry-After' => '120']),
            'https://api.coingecko.com/api/v3/simple/price*' => Http::response(['bitcoin' => ['usd_24h_change' => 3.5]]),
        ]);
        app(DailyChangeService::class)->snapshot(['BTC']);
        $selections->select(ProviderCapability::CRYPTO_DAILY_CHANGES, 'coingecko');
        $this->app->forgetInstance(DailyChangeService::class);

        $result = app(DailyChangeService::class)->snapshot(['BTC']);

        $this->assertSame(3.5, $result['changes']['BTC']);
        $this->assertSame('coingecko', $result['statuses']['BTC']['provider']);
        Http::assertSentCount(2);
    }

    public function test_daily_change_cooldown_does_not_block_conversion_rates(): void
    {
        $this->primeCatalog();
        Http::fake(['https://api.kraken.com/0/public/Ticker*' => Http::sequence()
            ->push([], 429, ['Retry-After' => '120'])
            ->push(['error' => [], 'result' => ['XBTUSD' => ['c' => ['64000']]]])]);
        app(DailyChangeService::class)->snapshot(['BTC']);

        $this->postJson('/conversion', ['amount' => '1', 'from' => 'BTC', 'fromType' => 'crypto', 'to' => 'USD'])
            ->assertOk()
            ->assertJsonPath('targetAmount', '64000.000000000000000000');

        Http::assertSentCount(2);
    }

    public function test_it_keeps_successful_cached_values_during_another_assets_provider_cooldown(): void
    {
        $this->primeCatalog();
        Http::fake(['https://api.kraken.com/0/public/Ticker*' => Http::sequence()
            ->push(['error' => [], 'result' => ['XBTUSD' => ['c' => ['100'], 'o' => '100']]])
            ->push([], 429, ['Retry-After' => '120'])]);
        app(DailyChangeService::class)->snapshot(['BTC']);
        app(DailyChangeService::class)->snapshot(['ETH']);

        $result = app(DailyChangeService::class)->snapshot(['BTC', 'ETH']);

        $this->assertSame(0.0, $result['changes']['BTC']);
        $this->assertSame('available', $result['statuses']['BTC']['status']);
        $this->assertSame('error', $result['statuses']['ETH']['status']);
        Http::assertSentCount(2);
    }

    #[DataProvider('malformedDailyPrices')]
    public function test_it_reports_invalid_daily_prices_as_errors_without_failing_json(mixed $price): void
    {
        $this->primeCatalog();
        Http::fake(['https://api.kraken.com/0/public/Ticker*' => Http::response([
            'error' => [], 'result' => ['XBTUSD' => ['c' => [$price], 'o' => '100']],
        ])]);

        $this->getJson('/daily-changes?currencies[]=BTC')
            ->assertOk()
            ->assertJsonPath('changes.BTC', null)
            ->assertJsonPath('statuses.BTC.status', 'error')
            ->assertJsonPath('statuses.BTC.code', 'provider_unavailable');

        Http::assertSentCount(1);
    }

    /** @return array<string, array{mixed}> */
    public static function malformedDailyPrices(): array
    {
        return ['array' => [[1]], 'boolean' => [true], 'garbage' => ['bad'], 'zero' => [0], 'overflow' => ['1e99999999999999999999']];
    }

    public function test_it_reports_a_malformed_ticker_as_a_failure_instead_of_missing_data(): void
    {
        $this->primeCatalog();
        Http::fake(['https://api.kraken.com/0/public/Ticker*' => Http::response([
            'error' => [], 'result' => ['XBTUSD' => 'invalid'],
        ])]);

        $this->getJson('/daily-changes?currencies[]=BTC')
            ->assertOk()
            ->assertJsonPath('changes.BTC', null)
            ->assertJsonPath('statuses.BTC.status', 'error')
            ->assertJsonPath('statuses.BTC.code', 'provider_unavailable');

        Http::assertSentCount(1);
    }

    public function test_coingecko_missing_values_do_not_use_a_long_provider_payload_cache(): void
    {
        $this->freezeTime();
        $this->primeCatalog();
        app(ProviderSelectionService::class)->select(ProviderCapability::CRYPTO_DAILY_CHANGES, 'coingecko');
        Http::fake(['https://api.coingecko.com/api/v3/simple/price*' => Http::sequence()
            ->push(['bitcoin' => ['usd_24h_change' => null]])
            ->push(['bitcoin' => ['usd_24h_change' => -1.25]])]);
        $missing = app(DailyChangeService::class)->snapshot(['BTC']);
        $this->travel(60)->seconds();

        $fresh = app(DailyChangeService::class)->snapshot(['BTC']);

        $this->assertSame('unavailable', $missing['statuses']['BTC']['status']);
        $this->assertSame(-1.25, $fresh['changes']['BTC']);
        Http::assertSentCount(2);
    }

    private function primeCatalog(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.nbrb.by/exrates/rates*' => Http::response([]),
            'https://api.kraken.com/0/public/AssetPairs*' => Http::response(['error' => [], 'result' => [
                'XBTUSD' => ['base' => 'XXBT', 'quote' => 'ZUSD', 'altname' => 'XBTUSD'],
                'ETHUSD' => ['base' => 'XETH', 'quote' => 'ZUSD', 'altname' => 'ETHUSD'],
            ]]),
            'https://api.coingecko.com/api/v3/coins/markets*' => Http::response([
                ['id' => 'bitcoin', 'symbol' => 'btc'], ['id' => 'ethereum', 'symbol' => 'eth'],
            ]),
        ]);
        $this->getJson('/currencies')->assertOk();
    }
}
