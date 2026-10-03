import assert from 'node:assert/strict';
import test from 'node:test';

import { conversionMethods } from '../../resources/js/converter/conversion.js';
import { currencyApi } from '../../resources/js/converter/api.js';
import { createConverterState } from '../../resources/js/converter/state.js';
import { calculatorMethods } from '../../resources/js/converter/calculator.js';

test('long conversion and daily-change lists are split into twenty-item batches', async () => {
    const originalFetch = globalThis.fetch;
    const sizes = [];
    globalThis.fetch = async (url, options) => {
        const isConversion = url === '/conversions';
        const codes = isConversion ? JSON.parse(options.body).targets : new URL(url, 'http://localhost').searchParams.getAll('currencies[]');
        sizes.push(codes.length);
        return new Response(JSON.stringify({ [isConversion ? 'conversions' : 'changes']: Object.fromEntries(codes.map(code => [code, isConversion ? { factor: '1' } : 0])) }));
    };
    try {
        const codes = Array.from({ length: 41 }, (_, index) => `C${index}`);
        const conversions = await currencyApi.conversions({ from: 'USD', targets: [...codes, codes[0]], refresh: true });
        const changes = await currencyApi.dailyChanges(codes);
        assert.deepEqual(sizes, [20, 20, 1, 20, 20, 1]);
        assert.equal(Object.keys(conversions.conversions).length, 41);
        assert.equal(Object.keys(changes.changes).length, 41);
    } finally { globalThis.fetch = originalFetch; }
});

test('daily-change status metadata is retained across twenty-item batches', async () => {
    const originalFetch = globalThis.fetch;
    globalThis.fetch = async (url) => {
        const codes = new URL(url, 'http://localhost').searchParams.getAll('currencies[]');
        return new Response(JSON.stringify({
            changes: Object.fromEntries(codes.map(code => [code, null])),
            statuses: Object.fromEntries(codes.map(code => [code, { status: 'error', code: 'provider_rate_limited', provider: 'kraken', retryAfter: 45 }])),
        }));
    };
    try {
        const result = await currencyApi.dailyChanges(Array.from({ length: 21 }, (_, index) => `C${index}`));
        assert.equal(Object.keys(result.statuses).length, 21);
        assert.equal(result.statuses.C20.retryAfter, 45);
        assert.equal(result.statuses.C0.provider, 'kraken');
    } finally { globalThis.fetch = originalFetch; }
});

test('daily-change failures have their own label and preserve conversion state', async () => {
    const originalFetch = globalThis.fetch;
    globalThis.fetch = async () => new Response(JSON.stringify({
        changes: { EUR: null },
        statuses: { EUR: { status: 'error', code: 'provider_rate_limited', provider: 'nbrb', retryAfter: 45 } },
    }));
    try {
        const state = conversionState();
        state.rows[1].result = '92';
        state.rows[1].isStale = false;
        await state.loadDailyChanges(0, ['EUR']);
        assert.equal(state.dailyChangeStatusLabel(state.rows[1]), 'Лимит данных');
        assert.equal(state.rows[1].result, '92');
        assert.equal(state.rows[1].isStale, false);
        assert.equal(state.message, '');
        assert.equal(state.rows[1].dailyChangeStatus.retryAfter, 45);
    } finally { globalThis.fetch = originalFetch; }
});

test('missing daily data and transport failures are distinguishable without blocking rates', async () => {
    const originalFetch = globalThis.fetch;
    globalThis.fetch = async () => new Response(JSON.stringify({ changes: { EUR: null }, statuses: { EUR: { status: 'unavailable' } } }));
    try {
        const state = conversionState();
        await state.loadDailyChanges(0, ['EUR']);
        assert.equal(state.dailyChangeStatusLabel(state.rows[1]), 'Нет данных');
        globalThis.fetch = async () => { throw new Error('offline'); };
        await state.loadDailyChanges(0, ['EUR']);
        assert.equal(state.dailyChangeStatusLabel(state.rows[1]), 'Сбой данных');
        assert.equal(state.message, '');
    } finally { globalThis.fetch = originalFetch; }
});

test('a late daily-change failure does not overwrite the new provider status', async () => {
    const originalFetch = globalThis.fetch;
    let fail;
    globalThis.fetch = () => new Promise((resolve, reject) => { fail = reject; });
    try {
        const state = conversionState();
        const pending = state.loadDailyChanges(0, ['EUR']);
        state.requestToken++;
        state.rows[1].dailyChangeStatus = { status: 'available', provider: 'new' };
        fail(new Error('old source failed'));
        await pending;
        assert.equal(state.rows[1].dailyChangeStatus.provider, 'new');
    } finally { globalThis.fetch = originalFetch; }
});

function conversionState() {
    const state = {
        base: 'USD', amount: '100', displayAmount: '100',
        rows: [
            { currency: 'USD', result: '100', error: '', loading: false },
            { currency: 'EUR', result: '', error: '', loading: false },
        ],
        factors: {}, sources: [], lastUpdatedAt: null, message: '', loading: false,
        requestToken: 0, loadAllPromise: null, loadAllKey: null,
        currencyType(currency) { return currency === 'BTC' ? 'crypto' : 'fiat'; },
        save() {}, showKeyboard() {}, buzz() {},
    };
    Object.defineProperties(state, Object.getOwnPropertyDescriptors(conversionMethods));
    return state;
}

test('aggregate update date is independent of row order and fallback is not a network claim', async () => {
    const originalFetch = globalThis.fetch;
    globalThis.fetch = async () => new Response(JSON.stringify({ conversions: {
        EUR: { factor: '0.9', sources: ['nbrb'], updatedAt: '2026-10-01T00:00:00Z', isStale: false, isFallback: true },
        BTC: { factor: '0.00001', sources: ['kraken'], updatedAt: '2026-10-01T12:00:00Z', isStale: false },
    }, changes: {} }));
    try {
        const state = conversionState();
        state.rows.push({ currency: 'BTC', result: '', error: '' });
        await state.loadAll();
        assert.equal(state.lastUpdatedAt, '2026-10-01T00:00:00Z');
        assert.equal(state.rows[1].isFallback, true);
        assert.equal(state.message, '');
        state.rows.reverse();
        await state.loadAll();
        assert.equal(state.lastUpdatedAt, '2026-10-01T00:00:00Z');
    } finally { globalThis.fetch = originalFetch; }
});

test('concurrent conversion loads are coalesced into one request', async () => {
    let requests = 0;
    const originalFetch = globalThis.fetch;
    globalThis.fetch = async (url) => {
        if (String(url).startsWith('/daily-changes')) {
            return new Response(JSON.stringify({ changes: { EUR: -0.3 } }), { status: 200, headers: { 'Content-Type': 'application/json' } });
        }
        requests++;
        await new Promise((resolve) => setTimeout(resolve, 5));
        return new Response(JSON.stringify({
            conversions: { EUR: { factor: '0.92', sources: ['test'], updatedAt: '2026-09-28T00:00:00Z', isStale: false } },
        }), { status: 200, headers: { 'Content-Type': 'application/json' } });
    };

    try {
        const state = conversionState();
        await Promise.all([state.loadAll(), state.loadAll()]);
        assert.equal(requests, 1);
        assert.equal(state.rows[1].result, '92');
    } finally {
        globalThis.fetch = originalFetch;
    }
});

test('partial failure marks the unavailable row while preserving stale rates and metadata', async () => {
    const originalFetch = globalThis.fetch;
    globalThis.fetch = async (url) => {
        if (String(url).startsWith('/daily-changes')) {
            return new Response(JSON.stringify({ changes: { EUR: -0.3 } }), { status: 200, headers: { 'Content-Type': 'application/json' } });
        }
        return new Response(JSON.stringify({
            conversions: {
                EUR: { factor: '0.92', sources: ['nbrb'], updatedAt: '2026-09-28T00:00:00Z', isStale: true },
                BYN: { error: 'provider_rate_limited', message: 'Kraken временно ограничил запросы.', provider: 'kraken' },
            },
        }), { status: 200, headers: { 'Content-Type': 'application/json' } });
    };

    try {
        const state = conversionState();
        const failedRow = { currency: 'BYN', result: '312', error: '', loading: false };
        state.rows.push(failedRow);
        state.factors.BYN = '3.12';

        await state.loadAll();

        assert.equal(state.rows[1].result, '92');
        assert.equal(failedRow.error, 'Лимит API');
        assert.equal(state.message, 'Используются сохранённые курсы. Не удалось получить актуальные данные. Kraken временно ограничил запросы.');
        assert.equal(failedRow.result, '');
        assert.equal(state.factors.BYN, undefined);
        assert.deepEqual(state.sources, ['nbrb']);
        assert.equal(state.lastUpdatedAt, '2026-09-28T00:00:00Z');
        assert.equal(state.message, 'Используются сохранённые курсы. Не удалось получить актуальные данные. Kraken временно ограничил запросы.');
    } finally {
        globalThis.fetch = originalFetch;
    }
});

test('request failure preserves displayed values and their source metadata', async () => {
    const originalFetch = globalThis.fetch;
    globalThis.fetch = async () => { throw new Error('offline'); };

    try {
        const state = conversionState();
        state.rows[1].result = '92';
        state.sources = ['nbrb'];
        state.lastUpdatedAt = '2026-09-28T00:00:00Z';

        await state.loadAll();

        assert.equal(state.rows[1].result, '92');
        assert.deepEqual(state.sources, ['nbrb']);
        assert.equal(state.lastUpdatedAt, '2026-09-28T00:00:00Z');
        assert.equal(state.message, 'Не удалось обновить курс. Показаны последние значения.');
    } finally {
        globalThis.fetch = originalFetch;
    }
});

test('daily changes load separately and do not block conversion results', async () => {
    const originalFetch = globalThis.fetch;
    let finishDailyChanges;
    globalThis.fetch = async (url) => {
        if (String(url).startsWith('/daily-changes')) {
            return new Promise((resolve) => { finishDailyChanges = resolve; });
        }

        return new Response(JSON.stringify({
            conversions: { EUR: { factor: '0.92', sources: ['nbrb'], updatedAt: '2026-09-28T00:00:00Z', isStale: false } },
        }), { status: 200, headers: { 'Content-Type': 'application/json' } });
    };

    try {
        const state = conversionState();
        await state.loadAll();

        assert.equal(state.rows[1].result, '92');
        assert.equal(state.rows[1].dailyChange, null);
        finishDailyChanges(new Response(JSON.stringify({ changes: { EUR: -0.3 } }), { status: 200, headers: { 'Content-Type': 'application/json' } }));
        await new Promise((resolve) => setTimeout(resolve, 0));

        assert.equal(state.rows[1].dailyChange, -0.3);
    } finally {
        globalThis.fetch = originalFetch;
    }
});

test('activating a converted row preserves its amount and recalculates the list', () => {
    const state = conversionState();
    state.rows[1].result = '92';
    state.factors.EUR = '0.92';

    state.activateRow(state.rows[1]);

    assert.equal(state.base, 'EUR');
    assert.equal(state.amount, '92');
    assert.equal(state.displayAmount, '92');
    assert.ok(Math.abs(Number(state.rows[0].result) - 100) < 0.000001);
});

function baseSwitchState(amount) {
    const state = createConverterState([
        { code: 'BTC', type: 'crypto' }, { code: 'USD', type: 'fiat' }, { code: 'NEAR', type: 'crypto' },
    ]);
    Object.defineProperties(state, Object.getOwnPropertyDescriptors(conversionMethods));
    Object.defineProperties(state, Object.getOwnPropertyDescriptors(calculatorMethods));
    state.base = 'BTC';
    state.amount = state.displayAmount = amount;
    state.rows = ['BTC', 'USD', 'NEAR'].map(currency => ({ currency, result: '', error: '' }));
    state.factors = { USD: '84945.1', NEAR: '18257.94734' };
    state.save = state.buzz = () => {};
    state.loadAll = () => {};
    state.recalculate();
    return state;
}

for (const [amount, usd, near] of [
    ['1', '84945.1', '18257.94734'],
    ['2', '169890.2', '36515.89468'],
    ['0.125', '10618.1375', '2282.2434175'],
    ['-1', '-84945.1', '-18257.94734'],
]) {
    test(`switching the base preserves exact ${amount} BTC through repeated currency selections`, () => {
        const state = baseSwitchState(amount);

        for (const currency of ['USD', 'NEAR', 'BTC', 'USD', 'BTC']) {
            state.activateRow(state.rows.find(row => row.currency === currency));
            assert.deepEqual(state.rows.map(row => row.result), [amount, usd, near]);
        }

        assert.equal(state.amount, amount);
        assert.equal(state.keyboardVisible, true);
    });
}

test('editing an amount after a base switch uses the exact ratio of the previous values', () => {
    const state = baseSwitchState('1');
    state.activateRow(state.rows[1]);

    state.recalculate('169890.2');

    assert.deepEqual(state.rows.map(row => row.result), ['2', '169890.2', '36515.89468']);
});

test('opening the calculator for the current base preserves its fractional amount', () => {
    const state = baseSwitchState('0.125');
    state.activateRow(state.rows[1]);
    state.keyboardVisible = false;

    state.activateRow(state.rows[1]);

    assert.equal(state.amount, '10618.1375');
    assert.equal(state.displayAmount, '10618.1375');
    assert.equal(state.rows[0].result, '0.125');
    assert.equal(state.keyboardVisible, true);
});

test('switching the base with a zero amount keeps all values at zero', () => {
    const state = baseSwitchState('0');

    state.activateRow(state.rows[1]);

    assert.equal(state.base, 'USD');
    assert.equal(state.amount, '0');
    assert.deepEqual(state.rows.map(row => row.result), ['0', '0', '0']);
});

test('rebasing while another row is still loading does not invent a zero conversion', () => {
    const state = baseSwitchState('1');
    state.rows[2].result = '';

    state.activateRow(state.rows[1]);

    assert.equal(state.rows[0].result, '1');
    assert.equal(state.rows[2].result, '');
    assert.equal(state.factors.NEAR, undefined);
});

for (const [factor, expectedRaw, expectedDisplay] of [
    ['0.333333333333333333', '0.999999999999999999', '1'],
    ['0.25', '0.75', '0.75'],
]) {
    test(`a refreshed factor ${factor} preserves the base amount and displays ${expectedDisplay} BTC`, async () => {
        const originalConversions = currencyApi.conversions;
        const originalDailyChanges = currencyApi.dailyChanges;
        currencyApi.conversions = async () => ({ conversions: {
            BTC: { factor, sources: ['kraken'], updatedAt: '2026-10-03T12:00:00Z' },
            NEAR: { factor: '2', sources: ['kraken'], updatedAt: '2026-10-03T12:00:00Z' },
        } });
        currencyApi.dailyChanges = async () => ({ changes: {} });
        try {
            const state = baseSwitchState('1');
            state.factors.USD = '3';
            state.recalculate();
            state.activateRow(state.rows[1]);

            await state.loadAllRequest(false, ['BTC', 'USD', 'NEAR']);

            assert.equal(state.base, 'USD');
            assert.equal(state.amount, '3');
            assert.equal(state.rows[0].result, expectedRaw);
            assert.equal(state.formatAmount(state.rows[0].result, 'BTC'), expectedDisplay);
        } finally {
            currencyApi.conversions = originalConversions;
            currencyApi.dailyChanges = originalDailyChanges;
        }
    });
}

test('provider switch discards pending conversion and daily-change responses', async () => {
    const originalFetch = globalThis.fetch;
    let finishConversion;
    let finishChanges;
    globalThis.fetch = (url) => new Promise((resolve) => {
        if (String(url).startsWith('/daily-changes')) finishChanges = resolve;
        else finishConversion = resolve;
    });
    try {
        const state = createConverterState([{ code: 'USD', type: 'fiat' }, { code: 'EUR', type: 'fiat' }]);
        Object.defineProperties(state, Object.getOwnPropertyDescriptors(conversionMethods));
        state.rows = state.rows.slice(0, 2);
        const pending = state.loadAll();
        state.invalidateProviderData('crypto_rates');
        finishConversion(new Response(JSON.stringify({ conversions: { EUR: { factor: '9', sources: ['old'], updatedAt: '2026-10-01T00:00:00Z' } } })));
        finishChanges(new Response(JSON.stringify({ changes: { EUR: 99 } })));
        await pending;
        await Promise.resolve();
        assert.equal(state.rows[1].result, '');
        assert.equal(state.rows[1].dailyChange, null);
        assert.deepEqual(state.factors, {});
        assert.deepEqual(state.sources, []);
        assert.equal(state.loading, false);
        assert.equal(state.rows[1].loading, false);
    } finally { globalThis.fetch = originalFetch; }
});

test('unsupported saved rows stay disabled and are excluded from conversion requests', async () => {
    const originalFetch = globalThis.fetch;
    const requested = [];
    globalThis.fetch = async (url, options) => {
        if (String(url).startsWith('/daily-changes')) return new Response('{"changes":{}}');
        requested.push(...JSON.parse(options.body).targets);
        return new Response(JSON.stringify({ conversions: { EUR: { factor: '0.9', sources: ['nbrb'], updatedAt: '2026-10-01T00:00:00Z' } } }));
    };
    try {
        const state = conversionState();
        state.currencyUnsupported = (currency) => currency === 'BONK';
        state.rows.push({ currency: 'BONK', result: '12', error: '', loading: false });
        await state.loadAll();
        assert.deepEqual(requested, ['USD', 'EUR']);
        assert.equal(state.rows.at(-1).error, 'Не поддерживается');
        assert.equal(state.rows.at(-1).result, '12');
        state.activateRow(state.rows.at(-1));
        assert.equal(state.base, 'USD');
    } finally { globalThis.fetch = originalFetch; }
});

test('unsupported saved base preserves the layout and avoids requesting invalid pairs', async () => {
    const originalFetch = globalThis.fetch;
    let requests = 0;
    globalThis.fetch = async () => { requests++; throw new Error('Unexpected request'); };
    try {
        const state = conversionState();
        state.currencyUnsupported = (currency) => currency === 'USD';
        await state.loadAll();
        assert.equal(requests, 0);
        assert.equal(state.base, 'USD');
        assert.equal(state.rows[0].error, 'Не поддерживается');
        assert.equal(state.loading, false);
        assert.match(state.message, /Базовая валюта не поддерживается/);
    } finally { globalThis.fetch = originalFetch; }
});
