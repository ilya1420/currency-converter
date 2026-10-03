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
        meta: Object.fromEntries(Object.entries(currencyMeta).map(([code, info]) => [code, { ...info }])),
        catalogMessage: '',
        storageMessage: '',
        catalogToken: 0,
        catalogComplete: false,
        cryptoProvider: null,
        ...converterSlice(catalog),
        ...chartSlice(),
        ...uiSlice(),
        ...gestureSlice(),
        init() {
            if (this.initializationPromise) return this.initializationPromise;

            converterStorage.onWriteFailure = () => { this.storageMessage = 'Не удалось сохранить настройки на устройстве. Изменения доступны до закрытия приложения.'; };
            this.initializeLayout();
            this.initializationPromise = this.loadCatalog();
        },
        invalidateProviderData(capability) {
            if (capability === 'crypto_rates' || capability === 'catalog') this.catalogToken++;
            if (capability?.endsWith('_market_data')) {
                this.chartRequestToken++;
                this.chartLoading = false;
                this.chart = null;
            }
            if (capability === 'crypto_rates') {
                this.catalogComplete = false;
                this.cryptoProvider = null;
                this.applyCatalog(this.catalog.filter(({ type }) => type === 'fiat'));
            }
            this.requestToken++;
            this.loadAllKey = null;
            this.factors = {};
            this.sources = [];
            this.lastUpdatedAt = null;
            this.rows.forEach((item) => { item.loading = false; item.dailyChange = null; item.dailyChangeStatus = null; });
            this.loading = false;
        },
        async loadCatalog(strict = false) {
            const token = ++this.catalogToken;
            try {
                const settings = this.providerSettings || await currencyApi.providerSettings();
                if (token !== this.catalogToken) return;
                const capability = settings.capabilities?.crypto_rates;
                const provider = capability?.selected || capability?.default || null;
                if (provider !== this.cryptoProvider) {
                    this.cryptoProvider = provider;
                    this.catalogComplete = false;
                    const fiat = this.catalog.filter(({ type }) => type === 'fiat');
                    const cached = converterStorage.loadCatalog(provider);
                    this.applyCatalog(cached.length ? cached : fiat);
                }
                const data = await currencyApi.catalog();
                if (token !== this.catalogToken) return;
                if (!Array.isArray(data.currencies)) throw new Error('Некорректный ответ каталога валют.');
                if (data.cryptoProvider && provider && data.cryptoProvider !== provider) throw new Error('Источник каталога изменился. Обновите данные.');
                this.catalogMessage = data.message || '';
                this.catalogComplete = data.isComplete !== false;
                this.applyCatalog(data.currencies);
                if (this.catalogComplete && !data.isFallback) converterStorage.saveCatalog(data.currencies, data.cryptoProvider || provider);
                if (strict && !this.catalogComplete) throw new Error(data.message || 'Каталог источника временно недоступен.');
            } catch (error) {
                if (token !== this.catalogToken) return;
                this.catalogComplete = false;
                this.catalogMessage = error.message || 'Каталог источника временно недоступен.';
                if (strict) throw error;
            }
        },
        currencyUnsupported(currency) {
            return this.catalogComplete && !this.currencies.includes(currency);
        },
        applyCatalog(currencies) {
            this.catalog = currencies;
            this.rows.forEach((item) => {
                if (this.catalogComplete && !currencies.some(({ code }) => code === item.currency)) {
                    item.error = 'Не поддерживается';
                    delete this.factors[item.currency];
                } else if (item.error === 'Не поддерживается') item.error = '';
            });
            this.currencies = currencies.map(({ code }) => code);
            currencies.forEach(({ code, type, name, icon, flag }) => {
                this.meta[code] ??= { label: code, color: type === 'crypto' ? cryptoFallbackColor(code) : fiatFallbackColor(code) };
                this.meta[code].type = type;
                if (name) this.meta[code].name = name;
                if (icon) this.meta[code].icon = icon;
                if (flag) this.meta[code].flag = flag;
            });
        },
        initializeLayout() {
            const saved = converterStorage.loadLayout();
            this.showFirstRunHint = !converterStorage.hasSeenFirstRunHint();
            if (saved && Array.isArray(saved.rows)) {
                const savedBase = saved.activeCurrency || saved.base || this.base;
                const currencies = [...new Set([
                    savedBase,
                    ...saved.rows.map(({ currency }) => currency),
                ].filter((currency) => typeof currency === 'string' && currency.length > 0))];

                // A provider can temporarily omit a previously selected asset. Keep the
                // user's layout and create fallback metadata instead of silently dropping it.
                currencies.forEach((currency) => {
                    const savedType = saved.rows.find((item) => item.currency === currency)?.type;
                    const type = this.catalog.find(({ code }) => code === currency)?.type
                        || savedType
                        || (this.meta[currency]?.icon ? 'crypto' : 'fiat');
                    if (this.meta[currency]) {
                        this.meta[currency].type ??= type;
                        return;
                    }

                    this.meta[currency] = {
                        label: currency,
                        name: currency,
                        type,
                        color: type === 'crypto' ? cryptoFallbackColor(currency) : fiatFallbackColor(currency),
                    };
                });

                this.base = savedBase;
                if (saved.amount !== undefined) this.amount = this.displayAmount = saved.amount;
                this.keyboardVisible = saved.keyboardVisible !== false;
                if (currencies.length) {
                    this.rows = currencies.map((currency, index) => row(index + 1, currency));
                    this.nextId = this.rows.length + 1;
                }
            }
            if (!this.rows.some((item) => item.currency === this.base)) this.base = this.rows[0]?.currency || 'USD';
            void this.loadAll();
        },
        dismissFirstRunHint() {
            this.showFirstRunHint = false;
            converterStorage.markFirstRunHintSeen();
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
        formatRateDate(date) {
            if (!date || !/^\d{4}-\d{2}-\d{2}$/.test(date)) return '';
            const [year, month, day] = date.split('-').map(Number);
            return new Intl.DateTimeFormat('ru-RU', { day: '2-digit', month: 'short' }).format(new Date(year, month - 1, day));
        },
        currencyType(currency) { return this.catalog.find(({ code }) => code === currency)?.type || this.meta[currency]?.type || (this.meta[currency]?.icon ? 'crypto' : 'fiat'); },
        fiatFractionDigits(currency) { if (ZERO_DECIMAL.has(currency)) return 0; if (THREE_DECIMAL.has(currency)) return 3; return FOUR_DECIMAL.has(currency) ? 4 : 2; },
        inputFractionDigits() { return this.currencyType(this.base) === 'crypto' ? 6 : this.fiatFractionDigits(this.base); },
        formatAmount(value, currency = this.base) { const crypto = this.currencyType(currency) === 'crypto'; return formatDecimalAmount(value, { fractionDigits: crypto ? 6 : this.fiatFractionDigits(currency), maxFractionDigits: 6, trimTrailingZeros: crypto }); },
        buzz() { if (navigator.vibrate) navigator.vibrate(8); },
        save() {
            const amount = /^-?\d+(?:\.\d*)?$/.test(this.amount)
                ? this.amount : this.rows.find(({ currency }) => currency === this.base)?.result;
            converterStorage.saveLayout({ base: this.base, amount, keyboardVisible: this.keyboardVisible, rows: this.rows.map((item) => ({ ...item, type: this.currencyType(item.currency) })) });
        },
    };
}
