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
        refreshes: 0,
        async loadAll(refresh) { if (refresh) this.refreshes++; },
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
    currencyApi.providerSettings = async () => ({ capabilities: { crypto_rates: { selected: 'kraken' } } });
    currencyApi.selectProvider = async (...args) => selections.push(['select', ...args]);
    currencyApi.resetProvider = async (...args) => selections.push(['reset', ...args]);

    try {
        const state = settingsState();
        await state.openProviderSettings();
        await state.saveProviderSelection('crypto_rates', 'coingecko');
        await state.resetProviderSelection('crypto_rates');

        assert.equal(state.providerSettingsOpen, true);
        assert.equal(state.providerSettings.capabilities.crypto_rates.selected, 'kraken');
        assert.deepEqual(selections, [
            ['select', 'crypto_rates', 'coingecko'],
            ['reset', 'crypto_rates'],
        ]);
        assert.equal(state.refreshes, 2);
        assert.equal(state.providerSettingsSaving, false);
    } finally {
        Object.assign(currencyApi, original);
    }
});
