import assert from 'node:assert/strict';
import test from 'node:test';

import { formatAmount, fractionToDecimal, multiply, parseExpression, ratio } from '../../resources/js/converter/decimal.js';

test('fiat keeps ISO display precision for ordinary amounts', () => {
    assert.equal(formatAmount('12.3', { fractionDigits: 2, maxFractionDigits: 6 }), '12.30');
    assert.equal(formatAmount('12.3', { fractionDigits: 3, maxFractionDigits: 6 }), '12.300');
});

test('small non-zero fiat amount is not displayed as zero', () => {
    assert.equal(formatAmount('0.00012345', { fractionDigits: 2, maxFractionDigits: 6 }), '0.000123');
});

test('crypto values trim insignificant zeroes within six digits', () => {
    assert.equal(formatAmount('0.0001234500', { fractionDigits: 6, maxFractionDigits: 6, trimTrailingZeros: true }), '0.000123');
    assert.equal(formatAmount('1.200000', { fractionDigits: 6, maxFractionDigits: 6, trimTrailingZeros: true }), '1.2');
});

test('expression division honours requested precision', () => {
    assert.equal(fractionToDecimal(parseExpression('1/3'), 6), '0.333333');
});

test('rebased ratios retain all digits of a terminating decimal result', () => {
    const factor = ratio('0.123456789012345678901234', '84945.1');

    assert.equal(multiply('84945.1', factor), '0.123456789012345678901234');
});

for (const [amount, numerator, denominator, expected] of [
    ['1', '2', '3', '0.666666666666666667'],
    ['-1', '2', '3', '-0.666666666666666667'],
    ['1', '2', '-3', '-0.666666666666666667'],
    ['0', '2', '3', '0'],
]) {
    test(`multiplying ${amount} by ${numerator}/${denominator} rounds only the final recurring result`, () => {
        assert.equal(multiply(amount, ratio(numerator, denominator)), expected);
    });
}

test('zero denominators cannot create a rebased factor', () => {
    assert.equal(ratio('1', '0'), null);
});

test('negative decimal multiplication keeps its sign and leading zero', () => {
    assert.equal(multiply('-0.1', '0.2'), '-0.02');
});

for (const [value, expected] of [
    ['0.999999999999999999', '1'],
    ['-0.999999999999999999', '-1'],
    ['999999999999.9999999', '1 000 000 000 000'],
    ['-0.0000004', '0'],
]) {
    test(`crypto display rounds ${value} to ${expected} without floating-point conversion`, () => {
        assert.equal(formatAmount(value, { fractionDigits: 6, maxFractionDigits: 6, trimTrailingZeros: true }), expected);
    });
}

test('fiat display rounds ties away from zero and carries to the integer part', () => {
    assert.equal(formatAmount('12.345'), '12.35');
    assert.equal(formatAmount('-12.345'), '-12.35');
    assert.equal(formatAmount('12.999'), '13.00');
    assert.equal(formatAmount('19.5', { fractionDigits: 0, maxFractionDigits: 6 }), '20');
});
