<?php

namespace Tests\Unit\Currency;

use App\Currency\Services\DecimalCalculator;
use Tests\TestCase;

class DecimalCalculatorTest extends TestCase
{
    private DecimalCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calculator = new DecimalCalculator;
    }

    public function test_it_does_not_introduce_float_artifacts(): void
    {
        $result = $this->calculator->multiply('0.1', '3');

        $this->assertSame('0.300000000000000000', $result);
        $this->assertStringNotContainsString('00000000000000004', $result);
    }

    public function test_it_converts_fiat_through_a_base_currency(): void
    {
        $result = $this->calculator->convertThroughBase('100', '3.2', '3.5');

        $this->assertSame('91.428571428571428571', $result);
    }

    public function test_it_preserves_a_small_crypto_amount(): void
    {
        $result = $this->calculator->multiply('0.00000001', '112345.67891234');

        $this->assertSame('0.001123456789123400', $result);
    }

    public function test_it_handles_large_decimal_amounts(): void
    {
        $result = $this->calculator->multiply('100000000000000000000', '3.5');

        $this->assertSame('350000000000000000000.000000000000000000', $result);
    }

    public function test_it_divides_with_the_internal_scale(): void
    {
        $result = $this->calculator->divide('1', '3');

        $this->assertSame('0.333333333333333333', $result);
    }
}
