import assert from 'node:assert/strict';
import test from 'node:test';

import { createConverterState } from '../../resources/js/converter/state.js';
import { converterStorage } from '../../resources/js/converter/storage.js';
import { currencyApi } from '../../resources/js/converter/api.js';

test('catalog fallback preserves assets and exposes a separate warning without persisting it as fresh', async () => {
    const originalRequest = currencyApi.catalog;
    const originalSave = converterStorage.saveCatalog;
    let saves = 0;
    currencyApi.catalog = async () => ({ currencies: [{ code: 'BTC', type: 'crypto' }], isComplete: false, isFallback: true, message: 'Сохранённый каталог' });
    converterStorage.saveCatalog = () => { saves++; };
    try {
        const state = createConverterState([]);
        await state.loadCatalog();
        assert.equal(state.currencies.includes('BTC'), true);
        assert.equal(state.catalogMessage, 'Сохранённый каталог');
        assert.equal(saves, 0);
        await assert.rejects(state.loadCatalog(true), /Сохранённый каталог/);
    } finally {
        currencyApi.catalog = originalRequest;
        converterStorage.saveCatalog = originalSave;
    }
});

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

test('startup restores the cached catalog and layout before the remote catalog responds', async () => {
    const cachedCatalog = [
        { code: 'BTC', type: 'crypto', name: 'Bitcoin', group: 'popular', icon: '/images/currencies/btc.svg' },
        { code: 'ZEC', type: 'crypto', name: 'Zcash', group: 'other' },
    ];
    const values = new Map([
        ['currency-converter-layout', JSON.stringify({ activeCurrency: 'ZEC', rows: [{ currency: 'ZEC' }, { currency: 'USD' }] })],
        ['currency-converter-catalog', JSON.stringify(cachedCatalog)],
    ]);
    const previousStorage = globalThis.localStorage;
    const previousCatalogRequest = currencyApi.catalog;
    globalThis.localStorage = {
        getItem: (key) => values.get(key) ?? null,
        setItem: (key, value) => values.set(key, value),
    };
    let resolveCatalog;
    currencyApi.catalog = () => new Promise((resolve) => { resolveCatalog = resolve; });

    try {
        const state = createConverterState([]);
        let conversionLoads = 0;
        state.loadAll = () => { conversionLoads++; };

        const initializationResult = state.init();

        assert.equal(initializationResult, undefined);
        assert.deepEqual(state.rows.map(({ currency }) => currency), ['ZEC', 'USD']);
        assert.equal(state.currencyType('ZEC'), 'crypto');
        assert.equal(conversionLoads, 1);
        assert.equal(state.currencies.includes('BTC'), true);

        resolveCatalog({ currencies: [...cachedCatalog, { code: 'ETH', type: 'crypto', name: 'Ethereum' }] });
        await state.initializationPromise;

        assert.equal(state.currencies.includes('ETH'), true);
        assert.equal(JSON.parse(values.get('currency-converter-catalog')).some(({ code }) => code === 'ETH'), true);
    } finally {
        currencyApi.catalog = previousCatalogRequest;
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

test('conversion history ignores malformed entries and retains at most fifty records', () => {
    const entries = Array.from({ length: 51 }, (_, index) => ({
        id: `entry-${index}`,
        base: 'USD',
        amount: String(index + 1),
        savedAt: '2026-10-01T10:00:00.000Z',
        rows: [{ currency: 'USD', result: String(index + 1) }, { currency: 'EUR', result: '0.9' }],
    }));
    entries.splice(12, 0, { id: 'bad-entry', base: 'USD', amount: 'invalid', rows: [] });
    const values = new Map([['currency-converter-history', JSON.stringify(entries)]]);
    const previousStorage = globalThis.localStorage;
    globalThis.localStorage = {
        getItem: (key) => values.get(key) ?? null,
        setItem: (key, value) => values.set(key, value),
        removeItem: (key) => values.delete(key),
    };

    try {
        const history = converterStorage.loadConversionHistory();

        assert.equal(history.length, 50);
        assert.equal(history.some(({ id }) => id === 'bad-entry'), false);
        assert.equal(history[0].id, 'entry-0');
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
