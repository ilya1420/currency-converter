import assert from 'node:assert/strict';
import test from 'node:test';

import { presetMethods } from '../../resources/js/converter/presets.js';

function presetState() {
    const state = {
        currencies: ['USD', 'EUR', 'BYN', 'BTC', 'ETH'],
        rows: [{ id: 1, currency: 'BYN', result: '100' }, { id: 2, currency: 'EUR', result: '30' }, { id: 3, currency: 'RUB', error: 'Нет курса' }],
        base: 'BYN',
        amount: '100',
        nextId: 4,
        factors: { EUR: '0.3' },
        presetsOpen: true,
        saved: 0,
        loaded: 0,
        save() { this.saved++; },
        loadAll() { this.loaded++; },
        buzz() {},
    };
    Object.defineProperties(state, Object.getOwnPropertyDescriptors(presetMethods));

    return state;
}

test('a partial preset adds supported missing currencies and preserves the current calculation', () => {
    const state = presetState();
    const existingRows = [...state.rows];

    state.applyCurrencyPreset('crypto');

    assert.deepEqual(state.rows.map(({ currency }) => currency), ['BYN', 'EUR', 'RUB', 'USD', 'BTC', 'ETH']);
    assert.deepEqual(state.rows.slice(0, 3), existingRows);
    assert.equal(state.base, 'BYN');
    assert.equal(state.amount, '100');
    assert.equal(state.nextId, 7);
    assert.deepEqual(state.factors, {});
    assert.equal(state.saved, 1);
    assert.equal(state.loaded, 1);
    assert.equal(state.presetsOpen, false);
});

test('applying the same preset twice does not duplicate rows or reload rates again', () => {
    const state = presetState();

    state.applyCurrencyPreset('travel');
    state.applyCurrencyPreset('travel');

    assert.deepEqual(state.rows.map(({ currency }) => currency), ['BYN', 'EUR', 'RUB', 'USD']);
    assert.equal(state.saved, 1);
    assert.equal(state.loaded, 1);
});

test('preset availability follows the latest provider catalog at the time of application', () => {
    const state = presetState();
    assert.deepEqual(state.currencyPresets.find(({ id }) => id === 'crypto').unavailable, ['SOL', 'ZEC', 'LTC']);

    state.currencies = ['USD', 'SOL'];
    state.applyCurrencyPreset('crypto');

    assert.deepEqual(state.rows.map(({ currency }) => currency), ['BYN', 'EUR', 'RUB', 'USD', 'SOL']);
});

test('an unavailable or unknown preset leaves the layout and requests unchanged', () => {
    const state = presetState();
    state.currencies = [];
    const existingRows = [...state.rows];

    state.applyCurrencyPreset('crypto');
    state.applyCurrencyPreset('unknown');

    assert.deepEqual(state.rows, existingRows);
    assert.equal(state.saved, 0);
    assert.equal(state.loaded, 0);
    assert.equal(state.presetsOpen, true);
});
