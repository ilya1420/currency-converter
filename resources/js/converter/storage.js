const LAYOUT_KEY = 'currency-converter-layout';
const CRYPTO_GROUPS_KEY = 'currency-converter-crypto-groups';

function read(key, fallback) {
    try {
        return JSON.parse(localStorage.getItem(key) || 'null') ?? fallback;
    } catch {
        return fallback;
    }
}

export const converterStorage = {
    loadLayout() {
        return read(LAYOUT_KEY, null);
    },
    saveLayout({ base, keyboardVisible, rows }) {
        localStorage.setItem(LAYOUT_KEY, JSON.stringify({
            activeCurrency: base,
            keyboardVisible,
            rows: rows.map(({ currency }) => ({ currency })),
        }));
    },
    loadCryptoGroups() {
        return { fiat: true, popular: true, other: true, stable: true, meme: true, alt: true, ...read(CRYPTO_GROUPS_KEY, {}) };
    },
    saveCryptoGroups(groups) {
        localStorage.setItem(CRYPTO_GROUPS_KEY, JSON.stringify(groups));
    },
};
