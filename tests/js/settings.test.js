import assert from 'node:assert/strict';
import test from 'node:test';

import { currencyApi } from '../../resources/js/converter/api.js';
import { providerSettingsMethods } from '../../resources/js/converter/settings.js';

function settingsState() {
    const state = {
        providerSettingsOpen: false,
        providerSettings: null,
        providerSettingsLoading: false,
        providerSettingsSaving: false,
        providerSettingsError: '',
        coingeckoApiKey: '',
        activeTab: 'converter',
        refreshes: 0,
        catalogReloads: 0,
        async loadAll(refresh) { if (refresh) this.refreshes++; },
        async loadCatalog() { this.catalogReloads++; },
    };
    Object.defineProperties(state, Object.getOwnPropertyDescriptors(providerSettingsMethods));
    return state;
}

test('provider settings load on opening and can be selected and reset', async () => {
    const original = {
        providerSettings: currencyApi.providerSettings,
        selectProvider: currencyApi.selectProvider,
        resetProvider: currencyApi.resetProvider,
    };
    const selections = [];
    currencyApi.providerSettings = async () => ({ capabilities: { crypto_rates: { selected: null, default: 'kraken', providers: [{ id: 'kraken', name: 'Kraken' }] } } });
    currencyApi.selectProvider = async (...args) => selections.push(['select', ...args]);
    currencyApi.resetProvider = async (...args) => selections.push(['reset', ...args]);

    try {
        const state = settingsState();
        await state.openProviderSettings();
        await state.saveProviderSelection('crypto_rates', 'coingecko');
        await state.changeProviderSelection('crypto_rates', '__automatic__');
        await state.saveProviderSelection('crypto_rates', 'coingecko');

        assert.equal(state.providerSettingsOpen, true);
        assert.equal(state.providerSettings.capabilities.crypto_rates.selected, null);
        assert.equal(state.providerDefaultName('crypto_rates'), 'Kraken');
        assert.equal(state.providerCapabilities.some(({ id }) => id === 'catalog'), false);
        assert.deepEqual(state.providerCapabilityLabels({ capabilities: ['catalog', 'crypto_rates', 'unknown'] }), ['Каталог валют', 'Курсы криптовалют', 'unknown']);
        assert.deepEqual(selections, [
            ['select', 'crypto_rates', 'coingecko'],
            ['reset', 'crypto_rates'],
            ['select', 'crypto_rates', 'coingecko'],
        ]);
        assert.equal(state.refreshes, 3);
        assert.equal(state.catalogReloads, 3);
        assert.equal(state.providerSettingsSaving, false);
    } finally {
        Object.assign(currencyApi, original);
    }
});

test('CoinGecko key is cleared only after successful verification and save', async () => {
    const original = {
        providerSettings: currencyApi.providerSettings,
        saveCoinGeckoKey: currencyApi.saveCoinGeckoKey,
    };
    let savedKey = null;
    currencyApi.saveCoinGeckoKey = async (apiKey) => { savedKey = apiKey; };
    currencyApi.providerSettings = async () => ({ provider_settings: { coingecko: { configured: true } } });

    try {
        const state = settingsState();
        state.coingeckoApiKey = ' demo-key ';
        await state.saveCoinGeckoKey();

        assert.equal(savedKey, 'demo-key');
        assert.equal(state.coingeckoApiKey, '');
        assert.equal(state.providerSettings.provider_settings.coingecko.configured, true);
        assert.equal(state.providerSettingsSaving, false);
    } finally {
        Object.assign(currencyApi, original);
    }
});

test('saved crypto provider still refreshes rates when its catalog is unavailable', async () => {
    const originals = { selectProvider: currencyApi.selectProvider, providerSettings: currencyApi.providerSettings };
    currencyApi.selectProvider = async () => ({ selected: 'coingecko' });
    currencyApi.providerSettings = async () => ({ capabilities: { crypto_rates: { selected: 'coingecko' } } });
    try {
        const state = settingsState();
        const invalidations = [];
        state.invalidateProviderData = (capability) => invalidations.push(capability);
        state.loadCatalog = async () => { throw new Error('Каталог недоступен'); };
        await state.saveProviderSelection('crypto_rates', 'coingecko');
        assert.deepEqual(invalidations, ['crypto_rates', 'crypto_rates']);
        assert.equal(state.refreshes, 1);
        assert.match(state.providerSettingsError, /Выбор сохранён.*Каталог недоступен/);
        assert.equal(state.providerSettingsSaving, false);
    } finally { Object.assign(currencyApi, originals); }
});
