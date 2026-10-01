import assert from 'node:assert/strict';
import test from 'node:test';

import { currencyListMethods } from '../../resources/js/converter/currency-list.js';

function listState() {
    const state = {
        catalog: [
            { code: 'USD', type: 'fiat', name: 'Доллар США' },
            { code: 'EUR', type: 'fiat', name: 'Евро' },
            { code: 'BTC', type: 'crypto', name: 'Bitcoin', group: 'alt' },
        ],
        currencies: ['USD', 'EUR', 'BTC'],
        cryptoGroups: { fiat: true, stable: true, meme: true, alt: true, other: true },
        rows: [
            { id: 1, currency: 'USD', previousCurrency: 'USD', result: '100', error: '' },
            { id: 2, currency: 'EUR', previousCurrency: 'EUR', result: '92', error: '' },
        ],
        favoritePairs: [],
        base: 'USD',
        pickerTarget: null,
        pickerSearch: '',
        pickerSwipeOffset: 0,
        message: '',
        nextId: 3,
        buzz() {},
        save() {},
        loadAll() {},
        loadRow() {},
    };

    Object.defineProperties(state, Object.getOwnPropertyDescriptors(currencyListMethods));
    return state;
}

test('currency picker rejects a duplicate and restores the previous value', () => {
    const state = listState();
    state.pickerTarget = 1;
    state.rows[0].currency = 'EUR';
    state.rowChanged(state.rows[0]);

    assert.equal(state.rows[0].currency, 'USD');
    assert.match(state.message, /только один раз/);
});

test('add mode adds a currency once and removes an already selected currency', () => {
    const state = listState();
    state.addRow = (currency) => state.rows.push({ id: state.nextId++, currency, previousCurrency: currency });
    state.removeRow = (index) => state.rows.splice(index, 1);

    state.pickerTarget = 'add';
    state.chooseCurrency('BTC');
    assert.deepEqual(state.rows.map(({ currency }) => currency), ['USD', 'EUR', 'BTC']);

    state.chooseCurrency('BTC');
    assert.deepEqual(state.rows.map(({ currency }) => currency), ['USD', 'EUR']);
});

test('favorite pairs are directional, persisted, and can be applied to the converter', () => {
    const state = listState();
    const stored = new Map();
    const previousStorage = globalThis.localStorage;
    globalThis.localStorage = { setItem: (key, value) => stored.set(key, value) };
    let saveCount = 0;
    let loadCount = 0;
    state.save = () => { saveCount++; };
    state.loadAll = () => { loadCount++; };

    try {
        state.toggleFavoritePair('EUR');

        assert.deepEqual(state.favoritePairs, [{ from: 'USD', to: 'EUR' }]);
        assert.equal(state.isFavoritePair('EUR'), true);
        assert.equal(state.isFavoritePair('USD'), false);
        assert.equal(stored.get('currency-converter-favorite-pairs'), '[{"from":"USD","to":"EUR"}]');

        state.base = 'EUR';
        state.applyFavoritePair(state.favoritePairs[0]);

        assert.equal(state.base, 'USD');
        assert.equal(loadCount, 1);
        assert.equal(saveCount, 1);
        assert.equal(state.canApplyFavoritePair({ from: 'USD', to: 'MISSING' }), false);
    } finally {
        globalThis.localStorage = previousStorage;
    }
});

test('removing a favorite pair persists the remaining pairs', () => {
    const state = listState();
    state.favoritePairs = [{ from: 'USD', to: 'EUR' }, { from: 'EUR', to: 'BTC' }];
    const stored = new Map();
    const previousStorage = globalThis.localStorage;
    globalThis.localStorage = { setItem: (key, value) => stored.set(key, value) };

    try {
        state.removeFavoritePair({ from: 'USD', to: 'EUR' });

        assert.deepEqual(state.favoritePairs, [{ from: 'EUR', to: 'BTC' }]);
        assert.equal(stored.get('currency-converter-favorite-pairs'), '[{"from":"EUR","to":"BTC"}]');
    } finally {
        globalThis.localStorage = previousStorage;
    }
});

test('currency search matches both code and localized name', () => {
    const state = listState();

    state.pickerSearch = 'bitcoin';
    assert.deepEqual(state.filteredCurrencies(['USD', 'BTC']), ['BTC']);

    state.pickerSearch = 'eur';
    assert.deepEqual(state.filteredCurrencies(['USD', 'EUR']), ['EUR']);
});

test('ordinary currencies can be hidden like any other group', () => {
    const state = listState();
    const previousStorage = globalThis.localStorage;
    globalThis.localStorage = { setItem() {} };

    try {
        assert.deepEqual(state.currencyGroups.find(({ key }) => key === 'fiat').items, ['USD', 'EUR']);
        state.toggleCryptoGroup('fiat');

        assert.deepEqual(state.currencyGroups.find(({ key }) => key === 'fiat').items, []);
    } finally {
        globalThis.localStorage = previousStorage;
    }
});

test('currencies missing from the selected provider stay visible as unavailable and cannot be chosen', () => {
    const state = listState();
    state.rows.push({ id: 3, currency: 'ZEC', previousCurrency: 'ZEC' });
    state.pickerTarget = 'add';

    assert.deepEqual(state.unavailableCurrencies, ['ZEC']);
    assert.equal(state.canChoose('ZEC'), false);
    assert.equal(state.canChoose('BTC'), true);
});
