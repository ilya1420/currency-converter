<?php

namespace Tests\Unit\Currency;

use App\Currency\DTO\ConversionResult;
use App\Currency\DTO\ExchangeRate;
use App\Currency\Enums\Currency;
use App\Currency\Enums\CurrencyType;
use App\Currency\Enums\RateSource;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CurrencyDomainTest extends TestCase
{
    #[DataProvider('currencies')]
    public function test_currency_has_the_expected_type(Currency $currency, CurrencyType $type): void
    {
        $this->assertSame($type, $currency->type());
    }

    public static function currencies(): array
    {
        return [
            'Belarusian ruble' => [Currency::BYN, CurrencyType::FIAT],
            'US dollar' => [Currency::USD, CurrencyType::FIAT],
            'Euro' => [Currency::EUR, CurrencyType::FIAT],
            'Polish zloty' => [Currency::PLN, CurrencyType::FIAT],
            'British pound' => [Currency::GBP, CurrencyType::FIAT],
            'Chinese yuan' => [Currency::CNY, CurrencyType::FIAT],
            'Russian ruble' => [Currency::RUB, CurrencyType::FIAT],
            'Ukrainian hryvnia' => [Currency::UAH, CurrencyType::FIAT],
            'Bitcoin' => [Currency::BTC, CurrencyType::CRYPTO],
            'Ether' => [Currency::ETH, CurrencyType::CRYPTO],
            'Tether' => [Currency::USDT, CurrencyType::CRYPTO],
            'Solana' => [Currency::SOL, CurrencyType::CRYPTO],
            'XRP' => [Currency::XRP, CurrencyType::CRYPTO],
        ];
    }

    public function test_exchange_rate_preserves_typed_metadata(): void
    {
        $fetchedAt = new DateTimeImmutable('2026-09-24T10:00:00+00:00');
        $publishedAt = new DateTimeImmutable('2026-09-24T00:00:00+00:00');

        $rate = new ExchangeRate(
            Currency::USD,
            Currency::BYN,
            '3.12345678',
            RateSource::NBRB,
            $fetchedAt,
            $publishedAt,
        );

        $this->assertSame('3.12345678', $rate->rate);
        $this->assertSame($fetchedAt, $rate->fetchedAt);
        $this->assertSame($publishedAt, $rate->publishedAt);
    }

    public function test_conversion_result_keeps_the_rates_used_by_a_route(): void
    {
        $updatedAt = new DateTimeImmutable('2026-09-24T10:00:00+00:00');
        $rate = new ExchangeRate(
            Currency::BTC,
            Currency::USD,
            '112000.12345678',
            RateSource::KRAKEN,
            $updatedAt,
        );

        $result = new ConversionResult(
            Currency::BTC,
            Currency::USD,
            '0.5',
            '56000.06172839',
            $updatedAt,
            false,
            [$rate],
        );

        $this->assertSame([$rate], $result->ratesUsed);
        $this->assertFalse($result->isStale);
    }
}
