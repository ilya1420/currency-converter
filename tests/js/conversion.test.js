import assert from 'node:assert/strict';
import test from 'node:test';

import { conversionMethods } from '../../resources/js/converter/conversion.js';
import { currencyApi } from '../../resources/js/converter/api.js';
import { createConverterState } from '../../resources/js/converter/state.js';

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
