export function multiply(left, right) {
    const decimal = (value) => {
        const [whole, fraction = ''] = String(value).replace(',', '.').split('.');
        return [BigInt(`${whole}${fraction}` || '0'), fraction.length];
    };
    const [leftValue, leftScale] = decimal(left);
    const [rightValue, rightScale] = decimal(right);
    let value = (leftValue * rightValue).toString();
    const scale = leftScale + rightScale;

    if (scale) {
        value = value.padStart(scale + 1, '0');
        value = `${value.slice(0, -scale)}.${value.slice(-scale)}`;
    }

    return value.replace(/\.0+$/, '').replace(/(\.\d*?)0+$/, '$1');
}

export function divide(left, right, precision = 18) {
    const decimal = (value) => {
        const normalized = String(value).replace(',', '.');
        const sign = normalized.startsWith('-') ? -1n : 1n;
        const [whole, fraction = ''] = normalized.replace('-', '').split('.');

        return [sign * BigInt(`${whole}${fraction}` || '0'), fraction.length];
    };
    const [leftValue, leftScale] = decimal(left);
    const [rightValue, rightScale] = decimal(right);
    if (rightValue === 0n) return null;

    const sign = (leftValue < 0n) !== (rightValue < 0n) ? '-' : '';
    const numerator = (leftValue < 0n ? -leftValue : leftValue) * (10n ** BigInt(precision + rightScale));
    const denominator = (rightValue < 0n ? -rightValue : rightValue) * (10n ** BigInt(leftScale));
    let value = (numerator / denominator).toString().padStart(precision + 1, '0');
    value = `${value.slice(0, -precision)}.${value.slice(-precision)}`;

    return `${sign}${value}`.replace(/\.0+$/, '').replace(/(\.\d*?)0+$/, '$1');
}

export function formatAmount(value, { fractionDigits = 2, maxFractionDigits = fractionDigits, trimTrailingZeros = false } = {}) {
    if (value === null || value === undefined || value === '') return '—';

    const [integerPart, decimalPart = ''] = String(value).split('.');
    const sign = integerPart.startsWith('-') ? '-' : '';
    const whole = `${sign}${integerPart.replace('-', '').replace(/\B(?=(\d{3})+(?!\d))/g, ' ')}`;
    const standardDecimals = decimalPart.padEnd(fractionDigits, '0').slice(0, fractionDigits);

    if (trimTrailingZeros) {
        const decimals = decimalPart.slice(0, maxFractionDigits).replace(/0+$/, '');

        return decimals ? `${whole}.${decimals}` : whole;
    }

    // ISO precision is used for ordinary fiat values. A non-zero converted
    // value must never be displayed as zero solely because it is very small.
    if (fractionDigits === maxFractionDigits || Number(`${integerPart}.${decimalPart}`) === 0 || Number(`${integerPart}.${decimalPart}`) >= 1 || Number(`${integerPart}.${decimalPart}`) <= -1 || Number(`0.${standardDecimals}`) !== 0) {
        return fractionDigits ? `${whole}.${standardDecimals}` : whole;
    }

    const decimals = decimalPart.slice(0, maxFractionDigits).replace(/0+$/, '');

    return decimals ? `${whole}.${decimals}` : whole;
}

export function parseExpression(input) {
    const clean = input.replace(/\s/g, '');
    const tokens = clean.match(/\d+(?:\.\d+)?|[()+\-*/%]/g) || [];
    if (!tokens.length || tokens.join('') !== clean) throw new Error('Invalid expression');

    let index = 0;
    const gcd = (left, right) => {
        let a = left < 0n ? -left : left;
        let b = right;
        while (b) [a, b] = [b, a % b];
        return a;
    };
    const normalize = (numerator, denominator) => {
        if (!denominator) throw new Error('Division by zero');
        if (denominator < 0n) [numerator, denominator] = [-numerator, -denominator];
        const divisor = gcd(numerator, denominator);
        return { numerator: numerator / divisor, denominator: denominator / divisor };
    };
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

export function fractionToDecimal({ numerator, denominator }, precision = 2) {
    const sign = numerator < 0n ? '-' : '';
    let value = numerator < 0n ? -numerator : numerator;
    const whole = value / denominator;
    let remainder = value % denominator;
    if (!remainder) return `${sign}${whole}`;

    let fraction = '';
    for (let position = 0; position < precision && remainder; position++) {
        remainder *= 10n;
        fraction += (remainder / denominator).toString();
        remainder %= denominator;
    }

    return `${sign}${whole}.${fraction.replace(/0+$/, '')}`;
}
