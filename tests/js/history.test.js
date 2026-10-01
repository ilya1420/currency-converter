import assert from 'node:assert/strict';
import test from 'node:test';

import { historyMethods } from '../../resources/js/converter/history.js';
import { converterStorage } from '../../resources/js/converter/storage.js';

function historyState() {
    const state = {
        amount: '100+2',
        base: 'USD',
        rows: [
            { currency: 'USD', result: '102', error: '', loading: false },
            { currency: 'EUR', result: '94.5', error: '', loading: false },
            { currency: 'BTC', result: '', error: 'Нет курса', loading: false },
        ],
        meta: {},
        catalog: [],
        loading: false,
        conversionHistory: [],
        historyOpen: true,
        nextId: 4,
        factors: { EUR: '0.92' },
        message: '',
        inputFractionDigits: () => 2,
        currencyType: (currency) => currency === 'BTC' ? 'crypto' : 'fiat',
        save() {},
        loadAll() {},
        buzz() {},
    };

    Object.defineProperties(state, Object.getOwnPropertyDescriptors(historyMethods));

    return state;
}

test('saving current conversion explicitly stores the evaluated amount and successful results', () => {
    const state = historyState();
    const values = new Map();
    const previousStorage = globalThis.localStorage;
    globalThis.localStorage = {
        getItem: (key) => values.get(key) ?? null,
        setItem: (key, value) => values.set(key, value),
        removeItem: (key) => values.delete(key),
    };

    try {
        assert.equal(state.canSaveCurrentConversion, true);

        state.saveCurrentConversion();

        const [entry] = JSON.parse(values.get('currency-converter-history'));
        assert.equal(entry.amount, '102');
        assert.equal(entry.base, 'USD');
        assert.deepEqual(entry.rows, [
            { currency: 'USD', result: '102', type: 'fiat' },
            { currency: 'EUR', result: '94.5', type: 'fiat' },
        ]);
        assert.equal(state.conversionHistory.length, 1);
    } finally {
        globalThis.localStorage = previousStorage;
    }
});

test('saving is disabled while conversions load or the input is invalid', () => {
    const state = historyState();

    state.rows[1].loading = true;
    assert.equal(state.canSaveCurrentConversion, false);

    state.rows[1].loading = false;
    state.amount = '10-20';
    assert.equal(state.canSaveCurrentConversion, false);
});

test('restoring a history entry reapplies its amount and currencies, then refreshes conversions', () => {
    const state = historyState();
    let saveCount = 0;
    let loadCount = 0;
    state.save = () => { saveCount++; };
    state.loadAll = () => { loadCount++; };

    state.restoreHistoryEntry({
        id: 'entry-1',
        amount: '250',
        base: 'EUR',
        savedAt: '2026-10-01T10:00:00.000Z',
        rows: [
            { currency: 'EUR', result: '250', type: 'fiat' },
            { currency: 'BTC', result: '0.003', type: 'crypto' },
        ],
    });

    assert.equal(state.base, 'EUR');
    assert.equal(state.amount, '250');
    assert.equal(state.displayAmount, '250');
    assert.deepEqual(state.rows.map(({ currency }) => currency), ['EUR', 'BTC']);
    assert.equal(state.meta.BTC.type, 'crypto');
    assert.deepEqual(state.factors, {});
    assert.equal(state.historyOpen, false);
    assert.equal(saveCount, 1);
    assert.equal(loadCount, 1);
});

test('malformed entries cannot be restored', () => {
    const state = historyState();
    let loadCount = 0;
    state.loadAll = () => { loadCount++; };

    state.restoreHistoryEntry({ amount: '1', base: 'USD', rows: [{ currency: 'EUR', result: '2' }] });

    assert.equal(loadCount, 0);
    assert.equal(state.base, 'USD');
});

test('clearing history removes local data and resets the confirmation state', () => {
    const state = historyState();
    state.confirmClearHistory = true;
    const values = new Map([['currency-converter-history', '[{"id":"entry"}]']]);
    const previousStorage = globalThis.localStorage;
    globalThis.localStorage = {
        getItem: (key) => values.get(key) ?? null,
        setItem: (key, value) => values.set(key, value),
        removeItem: (key) => values.delete(key),
    };

    try {
        state.clearHistory();

        assert.deepEqual(state.conversionHistory, []);
        assert.equal(state.confirmClearHistory, false);
        assert.equal(values.has('currency-converter-history'), false);
    } finally {
        globalThis.localStorage = previousStorage;
    }
});

test('adding a conversion keeps the newest fifty valid entries', () => {
    const oldEntries = Array.from({ length: 50 }, (_, index) => ({
        id: `entry-${index}`,
        base: 'USD',
        amount: String(index + 1),
        savedAt: '2026-10-01T10:00:00.000Z',
        rows: [{ currency: 'USD', result: String(index + 1), type: 'fiat' }],
    }));
    const values = new Map([['currency-converter-history', JSON.stringify(oldEntries)]]);
    const previousStorage = globalThis.localStorage;
    globalThis.localStorage = {
        getItem: (key) => values.get(key) ?? null,
        setItem: (key, value) => values.set(key, value),
    };

    try {
        const entries = converterStorage.addConversionHistoryEntry({
            id: 'newest',
            base: 'USD',
            amount: '100',
            savedAt: '2026-10-01T11:00:00.000Z',
            rows: [{ currency: 'USD', result: '100', type: 'fiat' }],
        });

        assert.equal(entries.length, 50);
        assert.equal(entries[0].id, 'newest');
        assert.equal(entries.some(({ id }) => id === 'entry-49'), false);
    } finally {
        globalThis.localStorage = previousStorage;
    }
});

test('removing a conversion deletes only the selected entry', () => {
    const values = new Map([['currency-converter-history', JSON.stringify([
        { id: 'keep', base: 'USD', amount: '1', savedAt: '2026-10-01T10:00:00.000Z', rows: [{ currency: 'USD', result: '1' }] },
        { id: 'remove', base: 'EUR', amount: '2', savedAt: '2026-10-01T10:01:00.000Z', rows: [{ currency: 'EUR', result: '2' }] },
    ])]]);
    const previousStorage = globalThis.localStorage;
    globalThis.localStorage = {
        getItem: (key) => values.get(key) ?? null,
        setItem: (key, value) => values.set(key, value),
    };

    try {
        const entries = converterStorage.removeConversionHistoryEntry('remove');

        assert.deepEqual(entries.map(({ id }) => id), ['keep']);
    } finally {
        globalThis.localStorage = previousStorage;
    }
});
