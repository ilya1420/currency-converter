import { converterStorage } from '../storage.js';

export function uiSlice() {
    return {
        activeTab: 'converter',
        showFirstRunHint: false,
        providerSettingsOpen: false,
        providerSettings: null,
        providerSettingsLoading: false,
        providerSettingsSaving: false,
        providerSettingsError: '',
        coingeckoApiKey: '',
        isFreshInput: true,
        keyboardVisible: true,
        pickerTarget: null,
        pickerMarket: 'fiat',
        pickerSearch: '',
        pickerSwipeStartY: null,
        pickerSwipeOffset: 0,
        ignoreNextRowClick: false,
        cryptoGroups: converterStorage.loadCryptoGroups(),
        favoritePairs: converterStorage.loadFavoritePairs(),
        keys: ['C', '⌫', '%', '/', '7', '8', '9', '*', '4', '5', '6', '-', '1', '2', '3', '+'],
    };
}
