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

function write(key, value) {
    try {
        if (value === undefined) localStorage.removeItem(key);
        else localStorage.setItem(key, JSON.stringify(value));
        return true;
    } catch {
        converterStorage.onWriteFailure?.();
        return false;
    }
}

function sanitizeLayout(value) {
    if (!value || typeof value !== 'object' || (value.version !== undefined && value.version !== 1)
        || !Array.isArray(value.rows)) return null;
    const rows = value.rows.filter((item) => item && validCurrencyCode(item.currency))
        .map(({ currency, type }) => ({ currency, type: type === 'crypto' ? 'crypto' : 'fiat' }));
    const uniqueRows = [...new Map(rows.map((item) => [item.currency, item])).values()].slice(0, 100);
    if (!uniqueRows.length) return null;
    const base = value.activeCurrency || value.base;
    return {
        version: 1,
        activeCurrency: validCurrencyCode(base) && uniqueRows.some(({ currency }) => currency === base) ? base : uniqueRows[0].currency,
        keyboardVisible: value.keyboardVisible !== false,
        amount: validInput(value.amount) ? value.amount : undefined,
        rows: uniqueRows,
    };
}

function catalogKey(provider) {
    return typeof provider === 'string' && /^[a-z0-9_-]{1,64}$/.test(provider)
        ? `${CURRENCY_CATALOG_KEY}:${provider}` : null;
}

function validCurrencyCode(currency) {
    return typeof currency === 'string' && /^[A-Z0-9]{2,10}$/.test(currency);
}

function validAmount(amount) {
    return typeof amount === 'string' && amount.length <= 64 && /^\d+(?:\.\d+)?$/.test(amount);
}

function validInput(amount) {
    return typeof amount === 'string' && amount.length > 0 && amount.length <= 64
        && /^-?\d+(?:\.\d*)?$/.test(amount);
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
    onWriteFailure: null,
    loadCatalog(provider) {
        const key = catalogKey(provider);
        if (!key) return [];
        const saved = read(key, null);
        return saved?.version === 1 && saved.provider === provider ? sanitizeCatalog(saved.currencies) : [];
    },
    saveCatalog(currencies, provider) {
        const key = catalogKey(provider);
        return key ? write(key, { version: 1, provider, currencies: sanitizeCatalog(currencies) }) : false;
    },
    loadLayout() {
        return sanitizeLayout(read(LAYOUT_KEY, null));
    },
    saveLayout({ base, keyboardVisible, rows, amount }) {
        return write(LAYOUT_KEY, sanitizeLayout({ version: 1, activeCurrency: base, keyboardVisible, rows, amount }));
    },
    hasSeenFirstRunHint() {
        return read(FIRST_RUN_HINT_KEY, false) === true;
    },
    markFirstRunHintSeen() {
        return write(FIRST_RUN_HINT_KEY, true);
    },
    loadCryptoGroups() {
        const saved = read(CRYPTO_GROUPS_KEY, {});
        return Object.fromEntries(['fiat', 'popular', 'other', 'stable', 'meme', 'alt'].map((key) => [key, saved?.[key] !== false]));
    },
    saveCryptoGroups(groups) {
        return write(CRYPTO_GROUPS_KEY, groups);
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
        return write(FAVORITE_PAIRS_KEY, pairs);
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
        write(CONVERSION_HISTORY_KEY, entries);

        return entries;
    },
    removeConversionHistoryEntry(id) {
        const entries = this.loadConversionHistory().filter((entry) => entry.id !== id);
        write(CONVERSION_HISTORY_KEY, entries);

        return entries;
    },
    clearConversionHistory() {
        return write(CONVERSION_HISTORY_KEY);
    },
};
