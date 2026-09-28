import { converterStorage } from '../storage.js';

export function uiSlice() {
    return {
        activeTab: 'converter',
        isFreshInput: true,
        keyboardVisible: true,
        pickerTarget: null,
        pickerSearch: '',
        pickerSwipeStartY: null,
        pickerSwipeOffset: 0,
        ignoreNextRowClick: false,
        cryptoGroups: converterStorage.loadCryptoGroups(),
        keys: ['C', '⌫', '%', '/', '7', '8', '9', '*', '4', '5', '6', '-', '1', '2', '3', '+'],
    };
}
