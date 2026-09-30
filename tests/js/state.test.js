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
