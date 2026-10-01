import assert from 'node:assert/strict';
import test from 'node:test';

import { createConverterState } from '../../resources/js/converter/state.js';

test('layout restoration keeps saved currencies even when catalog is incomplete', () => {
    const values = new Map([
        ['currency-converter-layout', JSON.stringify({
            activeCurrency: 'BTC',
            rows: [{ currency: 'BTC' }, { currency: 'USD' }, { currency: 'BONK' }],
        })],
    ]);
    const previousStorage = globalThis.localStorage;
    globalThis.localStorage = {
        getItem: (key) => values.get(key) ?? null,
        setItem: (key, value) => values.set(key, value),
    };

    try {
        const state = createConverterState([{ code: 'USD', type: 'fiat', name: 'Доллар США' }]);
        state.loadAll = () => Promise.resolve();
        state.initializeLayout();

        assert.deepEqual(state.rows.map(({ currency }) => currency), ['BTC', 'USD', 'BONK']);
        assert.equal(state.base, 'BTC');
        assert.equal(state.meta.BONK.label, 'BONK');
    } finally {
        globalThis.localStorage = previousStorage;
    }
});

test('favorite pairs are restored locally and malformed entries are ignored', () => {
    const values = new Map([
        ['currency-converter-favorite-pairs', JSON.stringify([
            { from: 'USD', to: 'EUR' },
            { from: 'USD', to: 'USD' },
            { from: '../USD', to: 'EUR' },
        ])],
    ]);
    const previousStorage = globalThis.localStorage;
    globalThis.localStorage = {
        getItem: (key) => values.get(key) ?? null,
        setItem: (key, value) => values.set(key, value),
    };

    try {
        const state = createConverterState([]);

        assert.deepEqual(state.favoritePairs, [{ from: 'USD', to: 'EUR' }]);
    } finally {
        globalThis.localStorage = previousStorage;
    }
});

test('first-run hint is shown once and dismissal is persisted locally', () => {
    const values = new Map();
    const previousStorage = globalThis.localStorage;
    globalThis.localStorage = {
        getItem: (key) => values.get(key) ?? null,
        setItem: (key, value) => values.set(key, value),
    };

    try {
        const firstState = createConverterState([]);
        firstState.loadAll = () => Promise.resolve();
        firstState.initializeLayout();

        assert.equal(firstState.showFirstRunHint, true);
        firstState.dismissFirstRunHint();
        assert.equal(values.get('currency-converter-first-run-hint-seen'), 'true');

        const returningState = createConverterState([]);
        returningState.loadAll = () => Promise.resolve();
        returningState.initializeLayout();

        assert.equal(returningState.showFirstRunHint, false);
    } finally {
        globalThis.localStorage = previousStorage;
    }
});

test('source label names built-in providers and removes duplicates', () => {
    const state = createConverterState([]);
    state.sources = ['nbrb', 'kraken', 'nbrb'];

    assert.equal(state.sourceLabel, 'НБРБ, Kraken');
});

test('last updated label is hidden when current results have no provider metadata', () => {
    const state = createConverterState([]);
    state.lastUpdatedAt = '2026-09-28T00:00:00Z';

    assert.equal(state.lastUpdatedLabel, '');
});
