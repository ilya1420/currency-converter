import assert from 'node:assert/strict';
import test from 'node:test';
import { converterStorage } from '../../resources/js/converter/storage.js';

function storageFixture(run) {
    const original = globalThis.localStorage;
    const values = new Map();
    globalThis.localStorage = {
        getItem: (key) => values.get(key) ?? null,
        setItem: (key, value) => values.set(key, value),
        removeItem: (key) => values.delete(key),
    };
    try { run(values); } finally { globalThis.localStorage = original; }
}

test('catalog caches are isolated by provider and legacy unscoped data is ignored', () => storageFixture((values) => {
    values.set('currency-converter-catalog', JSON.stringify([{ code: 'OLD', type: 'crypto' }]));
    converterStorage.saveCatalog([{ code: 'BTC', type: 'crypto' }], 'kraken');
    converterStorage.saveCatalog([{ code: 'BONK', type: 'crypto' }], 'coingecko');
    assert.deepEqual(converterStorage.loadCatalog('kraken').map(({ code }) => code), ['BTC']);
    assert.deepEqual(converterStorage.loadCatalog('coingecko').map(({ code }) => code), ['BONK']);
    assert.deepEqual(converterStorage.loadCatalog(), []);
    assert.deepEqual(converterStorage.loadCatalog('unknown'), []);
}));

test('legacy layout is sanitized and saved with a version while preserving unsupported rows', () => storageFixture((values) => {
    values.set('currency-converter-layout', JSON.stringify({
        base: '../USD', keyboardVisible: false,
        rows: [null, {}, { currency: 'USD' }, { currency: 'USD' }, { currency: 'BONK', type: 'crypto' }, { currency: '<script>' }],
    }));
    const layout = converterStorage.loadLayout();
    assert.equal(layout.activeCurrency, 'USD');
    assert.equal(layout.keyboardVisible, false);
    assert.deepEqual(layout.rows, [{ currency: 'USD', type: 'fiat' }, { currency: 'BONK', type: 'crypto' }]);
    converterStorage.saveLayout({ base: 'BONK', rows: layout.rows, keyboardVisible: true, amount: '12.345' });
    const restored = converterStorage.loadLayout();
    assert.equal(restored.version, 1);
    assert.equal(restored.amount, '12.345');
    assert.equal(restored.activeCurrency, 'BONK');
}));

test('damaged and future layout schemas are rejected and oversized layouts are bounded', () => storageFixture((values) => {
    for (const value of ['{', 'null', '[]', '{"rows":[null]}', '{"version":2,"rows":[{"currency":"USD"}]}']) {
        values.set('currency-converter-layout', value);
        assert.equal(converterStorage.loadLayout(), null);
    }
    values.set('currency-converter-layout', JSON.stringify({ rows: Array.from({ length: 150 }, (_, index) => ({ currency: `C${index}` })) }));
    assert.equal(converterStorage.loadLayout().rows.length, 100);
}));

for (const failure of ['QuotaExceededError', 'SecurityError']) {
    test(`all persistence operations continue after ${failure} and report the failure`, () => {
        const original = globalThis.localStorage;
        const originalHandler = converterStorage.onWriteFailure;
        let failures = 0;
        converterStorage.onWriteFailure = () => { failures++; };
        globalThis.localStorage = {
            getItem() { throw new Error(failure); },
            setItem() { throw new Error(failure); },
            removeItem() { throw new Error(failure); },
        };
        try {
            assert.equal(converterStorage.loadLayout(), null);
            converterStorage.saveLayout({ base: 'USD', rows: [{ currency: 'USD' }] });
            converterStorage.saveCatalog([{ code: 'USD', type: 'fiat' }], 'kraken');
            converterStorage.saveCryptoGroups({ popular: false });
            converterStorage.saveFavoritePairs([{ from: 'USD', to: 'EUR' }]);
            converterStorage.markFirstRunHintSeen();
            const entry = { id: 'one', base: 'USD', amount: '2', savedAt: '2026-10-01T00:00:00Z', rows: [{ currency: 'USD', result: '2' }] };
            assert.equal(converterStorage.addConversionHistoryEntry(entry).length, 1);
            assert.deepEqual(converterStorage.removeConversionHistoryEntry('one'), []);
            converterStorage.clearConversionHistory();
            assert.equal(failures, 8);
        } finally {
            globalThis.localStorage = original;
            converterStorage.onWriteFailure = originalHandler;
        }
    });
}
