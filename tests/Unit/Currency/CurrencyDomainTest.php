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
        $this->assertSame($type, $currency->type);
    }

    public static function currencies(): array
    {
        return [
            'Belarusian ruble' => [Currency::fiat('BYN'), CurrencyType::FIAT], 'US dollar' => [Currency::fiat('USD'), CurrencyType::FIAT], 'Euro' => [Currency::fiat('EUR'), CurrencyType::FIAT], 'Polish zloty' => [Currency::fiat('PLN'), CurrencyType::FIAT], 'British pound' => [Currency::fiat('GBP'), CurrencyType::FIAT], 'Chinese yuan' => [Currency::fiat('CNY'), CurrencyType::FIAT], 'Russian ruble' => [Currency::fiat('RUB'), CurrencyType::FIAT], 'Ukrainian hryvnia' => [Currency::fiat('UAH'), CurrencyType::FIAT],
            'Bitcoin' => [Currency::crypto('BTC'), CurrencyType::CRYPTO], 'Ether' => [Currency::crypto('ETH'), CurrencyType::CRYPTO], 'Tether' => [Currency::crypto('USDT'), CurrencyType::CRYPTO], 'Solana' => [Currency::crypto('SOL'), CurrencyType::CRYPTO], 'XRP' => [Currency::crypto('XRP'), CurrencyType::CRYPTO],
        ];
    }

    public function test_exchange_rate_preserves_typed_metadata(): void
    {
        $fetchedAt = new DateTimeImmutable('2026-09-24T10:00:00+00:00');
        $publishedAt = new DateTimeImmutable('2026-09-24T00:00:00+00:00');

        $rate = new ExchangeRate(
            Currency::fiat('USD'), Currency::fiat('BYN'),
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
            Currency::crypto('BTC'), Currency::fiat('USD'),
            '112000.12345678',
            RateSource::KRAKEN,
            $updatedAt,
        );

        $result = new ConversionResult(
            Currency::crypto('BTC'), Currency::fiat('USD'),
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
