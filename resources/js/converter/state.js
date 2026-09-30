import { cryptoFallbackColor, currencyMeta, fiatFallbackColor } from './currency-meta.js';
import { formatAmount as formatDecimalAmount } from './decimal.js';
import { currencyApi } from './api.js';
import { converterStorage } from './storage.js';
import { converterSlice, row } from './state/converter-slice.js';
import { chartSlice } from './state/chart-slice.js';
import { gestureSlice } from './state/gesture-slice.js';
import { uiSlice } from './state/ui-slice.js';

const ZERO_DECIMAL = new Set(['BIF', 'CLP', 'DJF', 'GNF', 'ISK', 'JPY', 'KMF', 'KRW', 'MGA', 'PYG', 'RWF', 'UGX', 'VND', 'VUV', 'XAF', 'XOF', 'XPF']);
const THREE_DECIMAL = new Set(['BHD', 'IQD', 'JOD', 'KWD', 'LYD', 'OMR', 'TND']);
const FOUR_DECIMAL = new Set(['CLF', 'UYW']);
export function createConverterState(catalog) {
    return {
        meta: currencyMeta,
        ...converterSlice(catalog),
        ...chartSlice(),
        ...uiSlice(),
        ...gestureSlice(),
        init() {
            if (this.initializationPromise) return this.initializationPromise;

            this.initializationPromise = this.loadCatalog().then(() => this.initializeLayout());

            return this.initializationPromise;
        },
        async loadCatalog() {
            try {
                const data = await currencyApi.catalog();
                if (!Array.isArray(data.currencies)) return;
                this.catalog = data.currencies; this.currencies = data.currencies.map(({ code }) => code);
                data.currencies.forEach(({ code, type, name, icon, flag }) => {
                    this.meta[code] ??= { label: code, color: type === 'crypto' ? cryptoFallbackColor(code) : fiatFallbackColor(code) };
                    if (name) this.meta[code].name = name; if (icon) this.meta[code].icon = icon; if (flag) this.meta[code].flag = flag;
                });
            } catch { /* Built-in currencies keep the converter available offline. */ }
        },
        initializeLayout() {
            const saved = converterStorage.loadLayout();
            if (saved && Array.isArray(saved.rows)) {
                const savedBase = saved.activeCurrency || saved.base || this.base;
                const currencies = [...new Set([
                    savedBase,
                    ...saved.rows.map(({ currency }) => currency),
                ].filter((currency) => typeof currency === 'string' && currency.length > 0))];

                // A provider can temporarily omit a previously selected asset. Keep the
                // user's layout and create fallback metadata instead of silently dropping it.
                currencies.forEach((currency) => {
                    if (this.meta[currency]) return;
                    const type = this.catalog.find(({ code }) => code === currency)?.type || 'fiat';
                    this.meta[currency] = {
                        label: currency,
                        name: currency,
                        color: type === 'crypto' ? cryptoFallbackColor(currency) : fiatFallbackColor(currency),
                    };
                });

                this.base = savedBase;
                this.keyboardVisible = saved.keyboardVisible !== false;
                if (currencies.length) {
                    this.rows = currencies.map((currency, index) => row(index + 1, currency));
                    this.nextId = this.rows.length + 1;
                }
            }
            if (!this.rows.some((item) => item.currency === this.base)) this.base = this.rows[0]?.currency || 'USD';
            void this.loadAll();
        },
        get sourceLabel() {
            if (!this.sources.length) return '';
            const names = { nbrb: 'НБРБ', kraken: 'Kraken', coingecko: 'CoinGecko' };
            return [...new Set(this.sources)].map((source) => names[source] || source).join(', ');
        },
        get lastUpdatedLabel() {
            if (!this.lastUpdatedAt || !this.sources.length) return '';
            return `Обновлено ${new Intl.DateTimeFormat('ru-RU', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(this.lastUpdatedAt))}`;
        },
        currencyType(currency) { return this.catalog.find(({ code }) => code === currency)?.type || 'fiat'; },
        fiatFractionDigits(currency) { if (ZERO_DECIMAL.has(currency)) return 0; if (THREE_DECIMAL.has(currency)) return 3; return FOUR_DECIMAL.has(currency) ? 4 : 2; },
        inputFractionDigits() { return this.currencyType(this.base) === 'crypto' ? 6 : this.fiatFractionDigits(this.base); },
        formatAmount(value, currency = this.base) { const crypto = this.currencyType(currency) === 'crypto'; return formatDecimalAmount(value, { fractionDigits: crypto ? 6 : this.fiatFractionDigits(currency), maxFractionDigits: 6, trimTrailingZeros: crypto }); },
        buzz() { if (navigator.vibrate) navigator.vibrate(8); },
        save() { converterStorage.saveLayout({ base: this.base, keyboardVisible: this.keyboardVisible, rows: this.rows }); },
    };
}
