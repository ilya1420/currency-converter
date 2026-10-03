<?php

namespace Tests\Feature\Currency;

use App\Currency\Contracts\RateProviderInterface;
use App\Currency\DTO\ExchangeRate;
use App\Currency\Enums\Currency;
use App\Currency\Enums\CurrencyType;
use App\Currency\Enums\ProviderCapability;
use App\Currency\Enums\RateSource;
use App\Currency\Exceptions\ProviderException;
use App\Currency\Exceptions\RateUnavailableException;
use App\Currency\Repositories\ExchangeRateRepository;
use App\Currency\Services\ProviderSelectionService;
use App\Currency\Services\RateService;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RateServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_forced_refresh_fetches_each_pair_only_once_per_service(): void
    {
        $provider = $this->provider();
        $service = $this->service(new ExchangeRateRepository, [$provider]);
        $first = $service->getRate(Currency::fiat('USD'), Currency::fiat('BYN'), true);
        $second = $service->getRate(Currency::fiat('USD'), Currency::fiat('BYN'), true);
        $this->assertSame(1, $provider->calls);
        $this->assertSame($first, $second);
    }

    public function test_current_official_date_avoids_refresh_even_after_ttl(): void
    {
        $this->freezeTime();
        $repository = new ExchangeRateRepository;
        $repository->save(new ExchangeRate(Currency::fiat('USD'), Currency::fiat('BYN'), '3.12', RateSource::NBRB, DateTimeImmutable::createFromInterface(now()->subHours(10)), DateTimeImmutable::createFromInterface(now('Europe/Minsk')->startOfDay())));
        $provider = $this->provider();
        $rate = $this->service($repository, [$provider])->getRate(Currency::fiat('USD'), Currency::fiat('BYN'), true);
        $this->assertSame(0, $provider->calls);
        $this->assertFalse($rate->isStale);
        $this->assertNull($rate->fallbackReason);
    }

    public function test_previous_official_date_is_refreshed_even_when_recently_fetched(): void
    {
        $this->freezeTime();
        $repository = new ExchangeRateRepository;
        $repository->save(new ExchangeRate(Currency::fiat('USD'), Currency::fiat('BYN'), '3.12', RateSource::NBRB, DateTimeImmutable::createFromInterface(now()), DateTimeImmutable::createFromInterface(now('Europe/Minsk')->subDay())));
        $provider = $this->provider(exception: new ProviderException('failure'));
        $rate = $this->service($repository, [$provider])->getRate(Currency::fiat('USD'), Currency::fiat('BYN'));
        $this->assertSame(1, $provider->calls);
        $this->assertTrue($rate->isStale);
        $this->assertSame('provider_unavailable', $rate->fallbackReason);
    }

    public function test_fresh_cache_does_not_call_provider(): void
    {
        $repository = new ExchangeRateRepository;
        $repository->save($this->rate('3.12', new DateTimeImmutable));
        $provider = $this->provider();

        $rate = $this->service($repository, [$provider])->getRate(Currency::fiat('USD'), Currency::fiat('BYN'));

        $this->assertSame(0, $provider->calls);
        $this->assertFalse($rate->isStale);
    }

    public function test_expired_cache_is_refreshed_by_provider(): void
    {
        $repository = new ExchangeRateRepository;
        $repository->save($this->rate('3.12', (new DateTimeImmutable)->modify('-7 hours')));
        $provider = $this->provider('3.15');

        $rate = $this->service($repository, [$provider])->getRate(Currency::fiat('USD'), Currency::fiat('BYN'));

        $this->assertSame(1, $provider->calls);
        $this->assertSame('3.15', $rate->rate);
    }

    public function test_force_refresh_bypasses_a_fresh_cache(): void
    {
        $repository = new ExchangeRateRepository;
        $repository->save($this->rate('3.12', new DateTimeImmutable));
        $provider = $this->provider('3.15');

        $rate = $this->service($repository, [$provider])->getRate(Currency::fiat('USD'), Currency::fiat('BYN'), true);

        $this->assertSame(1, $provider->calls);
        $this->assertSame('3.15', $rate->rate);
    }

    public function test_provider_failure_uses_recent_stale_cache(): void
    {
        $repository = new ExchangeRateRepository;
        $repository->save($this->rate('3.12', (new DateTimeImmutable)->modify('-7 hours')));
        $provider = $this->provider(exception: new ProviderException('offline'));
        $nextProvider = $this->provider('3.20');

        $rate = $this->service($repository, [$provider, $nextProvider])->getRate(Currency::fiat('USD'), Currency::fiat('BYN'));

        $this->assertTrue($rate->isStale);
        $this->assertSame(0, $nextProvider->calls);
    }

    public function test_provider_failure_without_cache_throws(): void
    {
        $this->expectException(RateUnavailableException::class);
        $this->service(new ExchangeRateRepository, [$this->provider(exception: new ProviderException('offline'))])->getRate(Currency::fiat('USD'), Currency::fiat('BYN'));
    }

    public function test_provider_failure_without_stale_cache_does_not_call_another_provider(): void
    {
        $failedProvider = $this->provider(exception: new ProviderException('offline'));
        $nextProvider = $this->provider('3.20');

        try {
            $this->service(new ExchangeRateRepository, [$failedProvider, $nextProvider])->getRate(Currency::fiat('USD'), Currency::fiat('BYN'));
            $this->fail('A provider failure without cached data must make the rate unavailable.');
        } catch (RateUnavailableException) {
            $this->assertSame(1, $failedProvider->calls);
            $this->assertSame(0, $nextProvider->calls);
        }
    }

    public function test_explicit_crypto_provider_selection_is_used(): void
    {
        config(['currency.coingecko.api_key' => 'test-demo-key']);
        Http::preventStrayRequests();
        Http::fake(['https://api.coingecko.com/api/v3/simple/price*' => Http::response(['bitcoin-selection-test' => ['usd' => 68000]], 200)]);
        app(ProviderSelectionService::class)->select(ProviderCapability::CRYPTO_RATES, 'coingecko');

        $rate = app(RateService::class)->getRate(
            new Currency('BTC', CurrencyType::CRYPTO, 'XBTUSD', null, 'bitcoin-selection-test'),
            Currency::fiat('USD'),
        );

        $this->assertSame(RateSource::COINGECKO, $rate->source);
        $this->assertSame('68000', $rate->rate);
        Http::assertSentCount(1);
    }

    public function test_automatic_crypto_provider_selection_skips_unsupported_pairs(): void
    {
        config(['currency.coingecko.api_key' => 'test-demo-key']);
        Http::preventStrayRequests();
        Http::fake(['https://api.coingecko.com/api/v3/simple/price*' => Http::response(['test-coin-auto' => ['usd' => 12.5]], 200)]);

        $rate = app(RateService::class)->getRate(
            new Currency('TST', CurrencyType::CRYPTO, null, null, 'test-coin-auto'),
            Currency::fiat('USD'),
        );

        $this->assertSame(RateSource::COINGECKO, $rate->source);
        $this->assertSame('12.5', $rate->rate);
        Http::assertSentCount(1);
    }

    public function test_automatic_selection_can_use_coingecko_without_an_api_key(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.coingecko.com/api/v3/simple/price*' => Http::response(['test-coin-no-key' => ['usd' => 17.5]], 200)]);

        $rate = app(RateService::class)->getRate(
            new Currency('TST', CurrencyType::CRYPTO, null, null, 'test-coin-no-key'),
            Currency::fiat('USD'),
        );

        $this->assertSame('17.5', $rate->rate);
        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.coingecko.com/api/v3/simple/price?ids=test-coin-no-key&vs_currencies=usd'
            && ! $request->hasHeader('x-cg-demo-api-key'));
    }

    public function test_selected_provider_failure_does_not_fall_back_to_another_provider(): void
    {
        Http::preventStrayRequests();
        Http::fake(['https://api.kraken.com/0/public/Ticker?pair=XBTUSD' => Http::response([], 503)]);
        app(ProviderSelectionService::class)->select(ProviderCapability::CRYPTO_RATES, 'kraken');

        try {
            app(RateService::class)->getRate(Currency::crypto('BTC', 'XBTUSD'), Currency::fiat('USD'));
            $this->fail('A selected provider failure must not fall back to another provider.');
        } catch (RateUnavailableException) {
            Http::assertSentCount(1);
        }
    }

    /** @param iterable<RateProviderInterface> $providers */
    private function service(ExchangeRateRepository $repository, iterable $providers): RateService
    {
        return new RateService($repository, $providers, app(ProviderSelectionService::class));
    }

    private function rate(string $value, DateTimeImmutable $fetchedAt): ExchangeRate
    {
        return new ExchangeRate(Currency::fiat('USD'), Currency::fiat('BYN'), $value, RateSource::NBRB, $fetchedAt);
    }

    private function provider(string $rate = '3.15', ?ProviderException $exception = null): RateProviderInterface
    {
        return new class($rate, $exception) implements RateProviderInterface
        {
            public int $calls = 0;

            public function __construct(private string $rate, private ?ProviderException $exception) {}

            public function source(): RateSource
            {
                return RateSource::NBRB;
            }

            public function supports(Currency $from, Currency $to): bool
            {
                return $from->code === 'USD' && $to->code === 'BYN';
            }

            public function getRate(Currency $from, Currency $to): ExchangeRate
            {
                $this->calls++;
                if ($this->exception) {
                    throw $this->exception;
                }

                return new ExchangeRate($from, $to, $this->rate, RateSource::NBRB, new DateTimeImmutable);
            }
        };
    }
}
