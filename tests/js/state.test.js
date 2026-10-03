import assert from 'node:assert/strict';
import test from 'node:test';

import { createConverterState } from '../../resources/js/converter/state.js';
import { converterStorage } from '../../resources/js/converter/storage.js';
import { currencyApi } from '../../resources/js/converter/api.js';

test('catalog fallback preserves assets and exposes a separate warning without persisting it as fresh', async () => {
    const originalSettings = currencyApi.providerSettings;
    currencyApi.providerSettings = async () => ({ capabilities: { crypto_rates: { default: 'kraken' } } });
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
        currencyApi.providerSettings = originalSettings;
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
        ['currency-converter-catalog:kraken', JSON.stringify({ version: 1, provider: 'kraken', currencies: cachedCatalog })],
    ]);
    const previousStorage = globalThis.localStorage;
    const previousSettingsRequest = currencyApi.providerSettings;
    currencyApi.providerSettings = async () => ({ capabilities: { crypto_rates: { default: 'kraken' } } });
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
        await Promise.resolve();

        assert.equal(initializationResult, undefined);
        assert.deepEqual(state.rows.map(({ currency }) => currency), ['ZEC', 'USD']);
        assert.equal(state.currencyType('ZEC'), 'crypto');
        assert.equal(conversionLoads, 1);
        assert.equal(state.currencies.includes('BTC'), true);

        resolveCatalog({ currencies: [...cachedCatalog, { code: 'ETH', type: 'crypto', name: 'Ethereum' }] });
        await state.initializationPromise;

        assert.equal(state.currencies.includes('ETH'), true);
        assert.equal(JSON.parse(values.get('currency-converter-catalog:kraken')).currencies.some(({ code }) => code === 'ETH'), true);
    } finally {
        currencyApi.providerSettings = previousSettingsRequest;
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

test('late startup catalog cannot replace the catalog of a newly selected provider', async () => {
    const originals = { catalog: currencyApi.catalog, providerSettings: currencyApi.providerSettings };
    const originalStorage = globalThis.localStorage;
    const values = new Map();
    globalThis.localStorage = { getItem: (key) => values.get(key) ?? null, setItem: (key, value) => values.set(key, value) };
    currencyApi.providerSettings = async () => ({ capabilities: { crypto_rates: { default: 'kraken' } } });
    const pending = [];
    currencyApi.catalog = () => new Promise((resolve) => pending.push(resolve));
    try {
        const state = createConverterState([{ code: 'USD', type: 'fiat' }]);
        state.loadAll = () => Promise.resolve();
        state.init();
        await Promise.resolve();
        state.amount = '123.456';
        state.rows.push({ currency: 'BONK', result: '20', error: '' });
        state.invalidateProviderData('crypto_rates');
        state.providerSettings = { capabilities: { crypto_rates: { selected: 'coingecko' } } };
        const reload = state.loadCatalog(true);
        pending[1]({ cryptoProvider: 'coingecko', isComplete: true, currencies: [{ code: 'USD', type: 'fiat' }, { code: 'ETH', type: 'crypto' }] });
        await reload;
        pending[0]({ cryptoProvider: 'kraken', isComplete: true, currencies: [{ code: 'BTC', type: 'crypto' }] });
        await state.initializationPromise;
        assert.deepEqual(state.currencies, ['USD', 'ETH']);
        assert.equal(state.amount, '123.456');
        assert.equal(state.rows.at(-1).currency, 'BONK');
        assert.equal(state.rows.at(-1).error, 'Не поддерживается');
        assert.equal(state.rows.at(-1).result, '20');
        assert.equal(values.has('currency-converter-catalog:kraken'), false);
        assert.equal(values.has('currency-converter-catalog:coingecko'), true);
    } finally { Object.assign(currencyApi, originals); globalThis.localStorage = originalStorage; }
});

test('failed catalog lookup after provider switch cannot reuse another provider cache or prove unsupported assets', async () => {
    const originals = { catalog: currencyApi.catalog, providerSettings: currencyApi.providerSettings };
    const originalStorage = globalThis.localStorage;
    const values = new Map();
    globalThis.localStorage = { getItem: (key) => values.get(key) ?? null, setItem: (key, value) => values.set(key, value) };
    converterStorage.saveCatalog([{ code: 'BTC', type: 'crypto' }], 'kraken');
    currencyApi.catalog = async () => { throw new Error('Каталог недоступен'); };
    try {
        const state = createConverterState([{ code: 'USD', type: 'fiat' }, { code: 'BTC', type: 'crypto' }]);
        state.cryptoProvider = 'kraken';
        state.catalogComplete = true;
        state.rows.push({ currency: 'BONK', result: '', error: '' });
        state.invalidateProviderData('crypto_rates');
        state.providerSettings = { capabilities: { crypto_rates: { selected: 'coingecko' } } };
        await assert.rejects(state.loadCatalog(true), /Каталог недоступен/);
        assert.deepEqual(state.currencies, ['USD']);
        assert.equal(state.currencyUnsupported('BONK'), false);
        assert.equal(state.rows.at(-1).currency, 'BONK');
        assert.equal(state.catalogMessage, 'Каталог недоступен');
    } finally { Object.assign(currencyApi, originals); globalThis.localStorage = originalStorage; }
});

test('storage failure shows a warning while preserving changed layout in memory', async () => {
    const originalStorage = globalThis.localStorage;
    const originals = { catalog: currencyApi.catalog, providerSettings: currencyApi.providerSettings };
    const originalHandler = converterStorage.onWriteFailure;
    globalThis.localStorage = { getItem: () => null, setItem: () => { throw new Error('QuotaExceeded'); } };
    currencyApi.providerSettings = async () => ({ capabilities: {} });
    currencyApi.catalog = async () => ({ currencies: [{ code: 'USD', type: 'fiat' }], isComplete: true });
    try {
        const state = createConverterState([]);
        state.loadAll = () => Promise.resolve();
        state.init();
        await state.initializationPromise;
        state.amount = '42';
        state.save();
        assert.equal(state.amount, '42');
        assert.match(state.storageMessage, /Не удалось сохранить/);
    } finally {
        globalThis.localStorage = originalStorage;
        Object.assign(currencyApi, originals);
        converterStorage.onWriteFailure = originalHandler;
    }
});

test('startup settings response is ignored after a provider selection begins', async () => {
    const originals = { catalog: currencyApi.catalog, providerSettings: currencyApi.providerSettings };
    let resolveSettings;
    let requests = 0;
    currencyApi.providerSettings = () => new Promise((resolve) => { resolveSettings = resolve; });
    currencyApi.catalog = async () => { requests++; return { cryptoProvider: 'coingecko', isComplete: true, currencies: [{ code: 'ETH', type: 'crypto' }] }; };
    try {
        const state = createConverterState([]);
        const startup = state.loadCatalog();
        state.invalidateProviderData('crypto_rates');
        state.providerSettings = { capabilities: { crypto_rates: { selected: 'coingecko' } } };
        await state.loadCatalog(true);
        resolveSettings({ capabilities: { crypto_rates: { default: 'kraken' } } });
        await startup;
        assert.equal(requests, 1);
        assert.equal(state.cryptoProvider, 'coingecko');
        assert.deepEqual(state.currencies, ['ETH']);
    } finally { Object.assign(currencyApi, originals); }
});

test('saved evaluated amount and layout are restored after a restart', () => {
    const originalStorage = globalThis.localStorage;
    const values = new Map();
    globalThis.localStorage = { getItem: (key) => values.get(key) ?? null, setItem: (key, value) => values.set(key, value) };
    try {
        const first = createConverterState([{ code: 'USD', type: 'fiat' }]);
        first.amount = '12+3';
        first.rows = [{ currency: 'USD', result: '15' }, { currency: 'BONK', result: '1' }];
        first.meta.BONK = { type: 'crypto' };
        first.save();
        const returning = createConverterState([{ code: 'USD', type: 'fiat' }]);
        returning.loadAll = () => Promise.resolve();
        returning.initializeLayout();
        assert.equal(returning.amount, '15');
        assert.equal(returning.displayAmount, '15');
        assert.deepEqual(returning.rows.map(({ currency }) => currency), ['USD', 'BONK']);
        assert.equal(returning.currencyType('BONK'), 'crypto');
    } finally { globalThis.localStorage = originalStorage; }
});

test('changing daily-change provider preserves the independent chart and startup catalog request', () => {
    const state = createConverterState([]);
    state.chart = { source: 'Kraken', candles: [] };
    state.chartRequestToken = 4;
    state.catalogToken = 2;
    state.invalidateProviderData('crypto_daily_changes');
    assert.equal(state.chart.source, 'Kraken');
    assert.equal(state.chartRequestToken, 4);
    assert.equal(state.catalogToken, 2);
    assert.equal(state.requestToken, 1);
});
