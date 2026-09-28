import assert from 'node:assert/strict';
import test from 'node:test';

import { formatAmount, fractionToDecimal, parseExpression } from '../../resources/js/converter/decimal.js';

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
