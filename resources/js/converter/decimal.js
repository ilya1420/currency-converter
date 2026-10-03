function decimalParts(value) {
    const [whole, fraction = ''] = String(value).replace(',', '.').split('.');
    return [BigInt(`${whole}${fraction}` || '0'), fraction.length];
}

function decimalString(value, scale) {
    const sign = value < 0n ? '-' : '';
    let digits = (value < 0n ? -value : value).toString();
    if (scale) {
        digits = digits.padStart(scale + 1, '0');
        digits = `${digits.slice(0, -scale)}.${digits.slice(-scale)}`;
    }
    return `${sign}${digits}`.replace(/\.0+$/, '').replace(/(\.\d*?)0+$/, '$1');
}

function normalizeFraction(numerator, denominator) {
    if (!denominator) throw new Error('Division by zero');
    if (denominator < 0n) [numerator, denominator] = [-numerator, -denominator];
    let divisor = numerator < 0n ? -numerator : numerator;
    let remainder = denominator;
    while (remainder) [divisor, remainder] = [remainder, divisor % remainder];
    return { numerator: numerator / divisor, denominator: denominator / divisor };
}

/** @returns {{ numerator: bigint, denominator: bigint } | null} */
export function ratio(left, right) {
    const [leftValue, leftScale] = decimalParts(left);
    const [rightValue, rightScale] = decimalParts(right);
    if (rightValue === 0n) return null;
    return normalizeFraction(leftValue * 10n ** BigInt(rightScale), rightValue * 10n ** BigInt(leftScale));
}

/** @param {string | { numerator: bigint, denominator: bigint }} right */
export function multiply(left, right) {
    const [leftValue, leftScale] = decimalParts(left);
    if (typeof right === 'object') {
        const fraction = normalizeFraction(leftValue * right.numerator, 10n ** BigInt(leftScale) * right.denominator);
        let denominator = fraction.denominator;
        let twos = 0;
        let fives = 0;
        while (denominator % 2n === 0n) { denominator /= 2n; twos++; }
        while (denominator % 5n === 0n) { denominator /= 5n; fives++; }
        const precision = denominator === 1n ? Math.max(twos, fives) : 18;
        return fractionToDecimal(fraction, precision, true);
    }
    const [rightValue, rightScale] = decimalParts(right);
    return decimalString(leftValue * rightValue, leftScale + rightScale);
}

export function formatAmount(value, { fractionDigits = 2, maxFractionDigits = fractionDigits, trimTrailingZeros = false } = {}) {
    if (value === null || value === undefined || value === '') return '—';

    const [numerator, scale] = decimalParts(value);
    const fraction = { numerator, denominator: 10n ** BigInt(scale) };
    const standardValue = fractionToDecimal(fraction, fractionDigits, true);
    const precision = trimTrailingZeros ? maxFractionDigits
        : fractionDigits === maxFractionDigits || numerator === 0n || standardValue !== '0' ? fractionDigits : maxFractionDigits;
    const [integerPart, decimalPart = ''] = fractionToDecimal(fraction, precision, true).split('.');
    const sign = integerPart.startsWith('-') ? '-' : '';
    const whole = `${sign}${integerPart.replace('-', '').replace(/\B(?=(\d{3})+(?!\d))/g, ' ')}`;
    const decimals = trimTrailingZeros || precision !== fractionDigits
        ? decimalPart : decimalPart.padEnd(fractionDigits, '0');
    return decimals ? `${whole}.${decimals}` : whole;
}

export function parseExpression(input) {
    const clean = input.replace(/\s/g, '');
    const tokens = clean.match(/\d+(?:\.\d+)?|[()+\-*/%]/g) || [];
    if (!tokens.length || tokens.join('') !== clean) throw new Error('Invalid expression');

    let index = 0;
    const normalize = normalizeFraction;
    const number = (value) => {
        const [whole, fraction = ''] = value.split('.');
        return normalize(BigInt(`${whole}${fraction}`), 10n ** BigInt(fraction.length));
    };
    const primary = () => {
        if (tokens[index] === '(') {
            index++;
            const value = add();
            if (tokens[index++] !== ')') throw new Error('Unclosed parenthesis');
            return value;
        }
        if (!/^\d/.test(tokens[index] || '')) throw new Error('Expected number');
        return number(tokens[index++]);
    };
    const term = () => {
        let value = primary();
        while (['*', '/', '%'].includes(tokens[index])) {
            const operator = tokens[index++];
            if (operator === '%') {
                value = normalize(value.numerator, value.denominator * 100n);
                continue;
            }
            const right = primary();
            value = operator === '*'
                ? normalize(value.numerator * right.numerator, value.denominator * right.denominator)
                : normalize(value.numerator * right.denominator, value.denominator * right.numerator);
        }
        return value;
    };
    const add = () => {
        let value = term();
        while (['+', '-'].includes(tokens[index])) {
            const operator = tokens[index++];
            const right = term();
            value = normalize(
                operator === '+'
                    ? value.numerator * right.denominator + right.numerator * value.denominator
                    : value.numerator * right.denominator - right.numerator * value.denominator,
                value.denominator * right.denominator,
            );
        }
        return value;
    };

    const result = add();
    if (index !== tokens.length) throw new Error('Unexpected token');
    return result;
}

export function fractionToDecimal({ numerator, denominator }, precision = 2, round = false) {
    const negative = numerator < 0n;
    const scaled = (negative ? -numerator : numerator) * 10n ** BigInt(precision);
    let value = scaled / denominator;
    if (round && (scaled % denominator) * 2n >= denominator) value++;
    return decimalString(negative ? -value : value, precision);
}
