<?php

namespace Tests\Unit\Currency;

use App\Currency\Enums\Currency;
use App\Currency\Services\AmountFormatter;
use Tests\TestCase;

class AmountFormatterTest extends TestCase
{
    public function test_it_formats_fiat_with_two_decimal_places(): void
    {
        $this->assertSame('1 234.50', (new AmountFormatter)->format('1234.5', Currency::fiat('USD')));
    }

    public function test_it_keeps_small_crypto_amounts_visible(): void
    {
        $this->assertSame('0.00001234', (new AmountFormatter)->format('0.00001234', Currency::crypto('BTC')));
    }

    public function test_it_removes_insignificant_crypto_zeroes(): void
    {
        $this->assertSame('1.25', (new AmountFormatter)->format('1.25000000', Currency::crypto('ETH')));
    }
}
