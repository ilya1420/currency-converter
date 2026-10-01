const LAYOUT_KEY = 'currency-converter-layout';
const CRYPTO_GROUPS_KEY = 'currency-converter-crypto-groups';
const FIRST_RUN_HINT_KEY = 'currency-converter-first-run-hint-seen';
const FAVORITE_PAIRS_KEY = 'currency-converter-favorite-pairs';
const CONVERSION_HISTORY_KEY = 'currency-converter-history';
const CURRENCY_CATALOG_KEY = 'currency-converter-catalog';
const HISTORY_LIMIT = 50;

function read(key, fallback) {
    try {
        return JSON.parse(localStorage.getItem(key) || 'null') ?? fallback;
    } catch {
        return fallback;
    }
}

function validCurrencyCode(currency) {
    return typeof currency === 'string' && /^[A-Z0-9]{2,10}$/.test(currency);
}

function validAmount(amount) {
    return typeof amount === 'string' && amount.length <= 64 && /^\d+(?:\.\d+)?$/.test(amount);
}

function sanitizeHistoryEntry(entry) {
    if (!entry || typeof entry !== 'object'
        || typeof entry.id !== 'string'
        || !validCurrencyCode(entry.base)
        || !validAmount(entry.amount)
        || typeof entry.savedAt !== 'string'
        || !Number.isFinite(Date.parse(entry.savedAt))
        || !Array.isArray(entry.rows)) {
        return null;
    }

    const rows = entry.rows.filter((item) => item
        && validCurrencyCode(item.currency)
        && validAmount(item.result))
        .map(({ currency, result, type }) => ({ currency, result, type: type === 'crypto' ? 'crypto' : 'fiat' }));
    if (!rows.some(({ currency }) => currency === entry.base)) return null;

    return {
        id: entry.id,
        base: entry.base,
        amount: entry.amount,
        savedAt: entry.savedAt,
        rows,
    };
}

function sanitizeCatalog(currencies) {
    if (!Array.isArray(currencies)) return [];

    return currencies.filter((currency) => currency
        && validCurrencyCode(currency.code)
        && ['fiat', 'crypto'].includes(currency.type))
        .map(({ code, type, providerSymbol, name, group, icon, flag, coinGeckoId }) => ({
            code,
            type,
            providerSymbol: typeof providerSymbol === 'string' ? providerSymbol : null,
            name: typeof name === 'string' ? name : null,
            group: typeof group === 'string' ? group : null,
            icon: typeof icon === 'string' && /^\/images\/currencies\/[a-z0-9$]+\.svg$/.test(icon) ? icon : null,
            flag: typeof flag === 'string' && /^\/images\/flags\/[a-z0-9-]+\.svg$/.test(flag) ? flag : null,
            coinGeckoId: typeof coinGeckoId === 'string' ? coinGeckoId : null,
        }));
}

export const converterStorage = {
    loadCatalog() {
        return sanitizeCatalog(read(CURRENCY_CATALOG_KEY, []));
    },
    saveCatalog(currencies) {
        try {
            localStorage.setItem(CURRENCY_CATALOG_KEY, JSON.stringify(sanitizeCatalog(currencies)));
        } catch {}
    },
    loadLayout() {
        return read(LAYOUT_KEY, null);
    },
    saveLayout({ base, keyboardVisible, rows }) {
        localStorage.setItem(LAYOUT_KEY, JSON.stringify({
            activeCurrency: base,
            keyboardVisible,
            rows: rows.map(({ currency, type }) => ({ currency, type: type === 'crypto' ? 'crypto' : 'fiat' })),
        }));
    },
    hasSeenFirstRunHint() {
        return read(FIRST_RUN_HINT_KEY, false) === true;
    },
    markFirstRunHintSeen() {
        try {
            localStorage.setItem(FIRST_RUN_HINT_KEY, 'true');
        } catch {}
    },
    loadCryptoGroups() {
        return { fiat: true, popular: true, other: true, stable: true, meme: true, alt: true, ...read(CRYPTO_GROUPS_KEY, {}) };
    },
    saveCryptoGroups(groups) {
        localStorage.setItem(CRYPTO_GROUPS_KEY, JSON.stringify(groups));
    },
    loadFavoritePairs() {
        const pairs = read(FAVORITE_PAIRS_KEY, []);
        if (!Array.isArray(pairs)) return [];

        return pairs.filter((pair) => pair
            && typeof pair.from === 'string'
            && /^[A-Z0-9]{2,10}$/.test(pair.from)
            && typeof pair.to === 'string'
            && /^[A-Z0-9]{2,10}$/.test(pair.to)
            && pair.from !== pair.to)
            .map(({ from, to }) => ({ from, to }));
    },
    saveFavoritePairs(pairs) {
        localStorage.setItem(FAVORITE_PAIRS_KEY, JSON.stringify(pairs));
    },
    loadConversionHistory() {
        const entries = read(CONVERSION_HISTORY_KEY, []);
        if (!Array.isArray(entries)) return [];

        return entries.map(sanitizeHistoryEntry).filter(Boolean).slice(0, HISTORY_LIMIT);
    },
    addConversionHistoryEntry(entry) {
        const entries = [sanitizeHistoryEntry(entry), ...this.loadConversionHistory()]
            .filter(Boolean)
            .slice(0, HISTORY_LIMIT);
        localStorage.setItem(CONVERSION_HISTORY_KEY, JSON.stringify(entries));

        return entries;
    },
    removeConversionHistoryEntry(id) {
        const entries = this.loadConversionHistory().filter((entry) => entry.id !== id);
        localStorage.setItem(CONVERSION_HISTORY_KEY, JSON.stringify(entries));

        return entries;
    },
    clearConversionHistory() {
        localStorage.removeItem(CONVERSION_HISTORY_KEY);
    },
};
