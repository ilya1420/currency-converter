import { row } from './state/converter-slice.js';
import { converterStorage } from './storage.js';
import { formatAmount, fractionToDecimal, parseExpression } from './decimal.js';
import { cryptoFallbackColor, fiatFallbackColor } from './currency-meta.js';

export const historyMethods = {
    get historyAmount() {
        try {
            const amount = fractionToDecimal(parseExpression(this.amount), this.inputFractionDigits());

            return /^\d+(?:\.\d+)?$/.test(amount) ? amount : null;
        } catch {
            return null;
        }
    },
    get canSaveCurrentConversion() {
        return this.historyAmount !== null
            && !this.loading
            && this.rows.every((item) => !item.loading)
            && this.rows.some((item) => !item.error && item.result !== '');
    },
    saveCurrentConversion() {
        if (!this.canSaveCurrentConversion) return;

        const rows = this.rows
            .filter((item) => !item.error && item.result !== '')
            .map(({ currency, result }) => ({ currency, result, type: this.currencyType(currency) }));
        const entry = {
            id: `${Date.now()}-${Math.random().toString(36).slice(2, 9)}`,
            base: this.base,
            amount: this.historyAmount,
            savedAt: new Date().toISOString(),
            rows,
        };

        this.conversionHistory = converterStorage.addConversionHistoryEntry(entry);
        this.buzz();
    },
    restoreHistoryEntry(entry) {
        if (!entry || !entry.rows.some(({ currency }) => currency === entry.base)) return;

        this.base = entry.base;
        this.amount = entry.amount;
        this.displayAmount = entry.amount;
        this.isFreshInput = true;
        this.rows = entry.rows.map(({ currency, type }, index) => {
            this.meta[currency] ??= {
                label: currency,
                name: currency,
                color: type === 'crypto' ? cryptoFallbackColor(currency) : fiatFallbackColor(currency),
            };
            this.meta[currency].type = type;

            return row(index + 1, currency);
        });
        this.nextId = this.rows.length + 1;
        this.factors = {};
        this.message = '';
        this.historyOpen = false;
        this.save();
        void this.loadAll();
        this.buzz();
    },
    removeHistoryEntry(id) {
        this.conversionHistory = converterStorage.removeConversionHistoryEntry(id);
        this.buzz();
    },
    clearHistory() {
        converterStorage.clearConversionHistory();
        this.conversionHistory = [];
        this.confirmClearHistory = false;
        this.buzz();
    },
    formatHistoryResult(item) {
        const crypto = item.type === 'crypto';

        return formatAmount(item.result, {
            fractionDigits: crypto ? 6 : this.fiatFractionDigits(item.currency),
            maxFractionDigits: 6,
            trimTrailingZeros: crypto,
        });
    },
    formatHistoryDate(value) {
        const date = new Date(value);
        if (Number.isNaN(date.getTime())) return '';

        return new Intl.DateTimeFormat('ru-RU', {
            day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit',
        }).format(date);
    },
};
