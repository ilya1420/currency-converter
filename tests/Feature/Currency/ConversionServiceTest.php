<?php

namespace Tests\Feature\Currency;

use App\Currency\Contracts\RateProviderInterface;
use App\Currency\DTO\ExchangeRate;
use App\Currency\Enums\Currency;
use App\Currency\Enums\RateSource;
use App\Currency\Repositories\ExchangeRateRepository;
use App\Currency\Services\ConversionService;
use App\Currency\Services\DecimalCalculator;
use App\Currency\Services\RateService;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversionServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_converts_fiat_through_byn(): void
    {
        $this->assertSame('75.000000000000000000', $this->service()->convert('100', Currency::USD, Currency::EUR)->targetAmount);
    }

    public function test_it_converts_crypto_to_usd_and_fiat(): void
    {
        $service = $this->service();
        $this->assertSame('200.000000000000000000', $service->convert('2', Currency::BTC, Currency::USD)->targetAmount);
        $this->assertSame('150.000000000000000000', $service->convert('2', Currency::BTC, Currency::EUR)->targetAmount);
    }

    public function test_it_converts_fiat_to_crypto_and_crypto_to_crypto(): void
    {
        $service = $this->service();
        $this->assertSame('1.333333333333333333', $service->convert('100', Currency::EUR, Currency::BTC)->targetAmount);
        $this->assertSame('4.000000000000000000', $service->convert('2', Currency::BTC, Currency::ETH)->targetAmount);
    }

    public function test_same_currency_keeps_the_original_amount_without_rates(): void
    {
        $result = $this->service()->convert('0.00000001', Currency::BTC, Currency::BTC);
        $this->assertSame('0.00000001', $result->targetAmount);
        $this->assertSame([], $result->ratesUsed);
    }

    private function service(): ConversionService
    {
        $provider = new class implements RateProviderInterface
        {
            private array $rates = ['USD/BYN' => '3', 'EUR/BYN' => '4', 'BTC/USD' => '100', 'ETH/USD' => '50'];

            public function source(): RateSource
            {
                return RateSource::NBRB;
            }

            public function supports(Currency $from, Currency $to): bool
            {
                return isset($this->rates["{$from->value}/{$to->value}"]);
            }

            public function getRate(Currency $from, Currency $to): ExchangeRate
            {
                return new ExchangeRate($from, $to, $this->rates["{$from->value}/{$to->value}"], RateSource::NBRB, new DateTimeImmutable);
            }
        };

        return new ConversionService(new RateService(new ExchangeRateRepository, [$provider]), new DecimalCalculator);
    }
}
