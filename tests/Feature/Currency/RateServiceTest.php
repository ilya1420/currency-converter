<?php

namespace Tests\Feature\Currency;

use App\Currency\Contracts\RateProviderInterface;
use App\Currency\DTO\ExchangeRate;
use App\Currency\Enums\Currency;
use App\Currency\Enums\RateSource;
use App\Currency\Exceptions\ProviderException;
use App\Currency\Exceptions\RateUnavailableException;
use App\Currency\Repositories\ExchangeRateRepository;
use App\Currency\Services\RateService;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RateServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_cache_does_not_call_provider(): void
    {
        $repository = new ExchangeRateRepository;
        $repository->save($this->rate('3.12', new DateTimeImmutable));
        $provider = $this->provider();

        $rate = (new RateService($repository, [$provider]))->getRate(Currency::fiat('USD'), Currency::fiat('BYN'));

        $this->assertSame(0, $provider->calls);
        $this->assertFalse($rate->isStale);
    }

    public function test_expired_cache_is_refreshed_by_provider(): void
    {
        $repository = new ExchangeRateRepository;
        $repository->save($this->rate('3.12', (new DateTimeImmutable)->modify('-7 hours')));
        $provider = $this->provider('3.15');

        $rate = (new RateService($repository, [$provider]))->getRate(Currency::fiat('USD'), Currency::fiat('BYN'));

        $this->assertSame(1, $provider->calls);
        $this->assertSame('3.15', $rate->rate);
    }

    public function test_force_refresh_bypasses_a_fresh_cache(): void
    {
        $repository = new ExchangeRateRepository;
        $repository->save($this->rate('3.12', new DateTimeImmutable));
        $provider = $this->provider('3.15');

        $rate = (new RateService($repository, [$provider]))->getRate(Currency::fiat('USD'), Currency::fiat('BYN'), true);

        $this->assertSame(1, $provider->calls);
        $this->assertSame('3.15', $rate->rate);
    }

    public function test_provider_failure_uses_recent_stale_cache(): void
    {
        $repository = new ExchangeRateRepository;
        $repository->save($this->rate('3.12', (new DateTimeImmutable)->modify('-7 hours')));
        $provider = $this->provider(exception: new ProviderException('offline'));

        $rate = (new RateService($repository, [$provider]))->getRate(Currency::fiat('USD'), Currency::fiat('BYN'));

        $this->assertTrue($rate->isStale);
    }

    public function test_provider_failure_without_cache_throws(): void
    {
        $this->expectException(RateUnavailableException::class);
        (new RateService(new ExchangeRateRepository, [$this->provider(exception: new ProviderException('offline'))]))->getRate(Currency::fiat('USD'), Currency::fiat('BYN'));
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
