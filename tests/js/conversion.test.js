import assert from 'node:assert/strict';
import test from 'node:test';

import { conversionMethods } from '../../resources/js/converter/conversion.js';

function conversionState() {
    const state = {
        base: 'USD', amount: '100', displayAmount: '100',
        rows: [
            { currency: 'USD', result: '100', error: '', loading: false },
            { currency: 'EUR', result: '', error: '', loading: false },
        ],
        factors: {}, changes: {}, sources: [], lastUpdatedAt: null, message: '', loading: false,
        requestToken: 0, loadAllPromise: null, loadAllKey: null,
        currencyType(currency) { return currency === 'BTC' ? 'crypto' : 'fiat'; },
        save() {}, showKeyboard() {}, buzz() {},
    };
    Object.defineProperties(state, Object.getOwnPropertyDescriptors(conversionMethods));
    return state;
}

test('concurrent conversion loads are coalesced into one request', async () => {
    let requests = 0;
    const originalFetch = globalThis.fetch;
    globalThis.fetch = async () => {
        requests++;
        await new Promise((resolve) => setTimeout(resolve, 5));
        return new Response(JSON.stringify({
            conversions: { EUR: { factor: '0.92', sources: ['test'], updatedAt: '2026-09-28T00:00:00Z', isStale: false } },
            changes: { USD: 1.2, EUR: -0.3 },
        }), { status: 200, headers: { 'Content-Type': 'application/json' } });
    };

    try {
        const state = conversionState();
        await Promise.all([state.loadAll(), state.loadAll()]);
        assert.equal(requests, 1);
        assert.equal(state.rows[1].result, '92');
        assert.equal(state.changes.EUR, -0.3);
    } finally {
        globalThis.fetch = originalFetch;
    }
});

test('partial failure marks the unavailable row while preserving stale rates and metadata', async () => {
    const originalFetch = globalThis.fetch;
    globalThis.fetch = async () => new Response(JSON.stringify({
        conversions: {
            EUR: { factor: '0.92', sources: ['nbrb'], updatedAt: '2026-09-28T00:00:00Z', isStale: true },
            BYN: { error: 'Нет курса' },
        },
        changes: {},
    }), { status: 200, headers: { 'Content-Type': 'application/json' } });

    try {
        const state = conversionState();
        const failedRow = { currency: 'BYN', result: '312', error: '', loading: false };
        state.rows.push(failedRow);
        state.factors.BYN = '3.12';

        await state.loadAll();

        assert.equal(state.rows[1].result, '92');
        assert.equal(failedRow.error, 'Нет курса');
        assert.equal(failedRow.result, '');
        assert.equal(state.factors.BYN, undefined);
        assert.deepEqual(state.sources, ['nbrb']);
        assert.equal(state.lastUpdatedAt, '2026-09-28T00:00:00Z');
        assert.equal(state.message, 'Нет сети. Используются сохранённые курсы.');
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
