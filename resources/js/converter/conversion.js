import { divide, multiply } from './decimal.js';
import { currencyApi } from './api.js';

export const conversionMethods = {
    recalculate(amount = this.amount) {
        this.rows.forEach((row) => {
            if (row.currency === this.base) row.result = amount;
            else if (this.factors[row.currency]) row.result = multiply(amount, this.factors[row.currency]);
        });
    },
    async refreshAll() { await this.loadAll(true); },
    async loadAll(refresh = false) {
        const targets = [...new Set(this.rows.map((row) => row.currency))];
        const key = `${this.base}|${targets.join(',')}|${refresh ? 'refresh' : 'cached'}`;
        if (this.loadAllPromise && this.loadAllKey === key) return this.loadAllPromise;

        this.loadAllKey = key;
        const operation = this.loadAllRequest(refresh, targets);
        const wrapped = operation.finally(() => {
            if (this.loadAllPromise === wrapped) {
                this.loadAllPromise = null;
                this.loadAllKey = null;
            }
        });
        this.loadAllPromise = wrapped;
        return this.loadAllPromise;
    },
    async loadAllRequest(refresh, targets) {
        const token = ++this.requestToken;
        this.loading = true; this.sources = []; this.message = '';
        this.rows.forEach((row) => { row.loading = true; row.error = ''; });
        try {
            const data = await currencyApi.conversions({ from: this.base, fromType: this.currencyType(this.base), targets, refresh });
            if (token !== this.requestToken) return;
            this.changes = { ...this.changes, ...(data.changes || {}) };
            this.rows.forEach((row) => {
                if (row.currency === this.base) {
                    row.result = this.amount;
                    return;
                }
                const conversion = data.conversions[row.currency];
                if (conversion?.error) { row.error = conversion.error; return; }
                this.factors[row.currency] = conversion.factor;
                row.result = multiply(this.amount, conversion.factor);
                this.sources = [...new Set([...this.sources, ...conversion.sources])];
                this.lastUpdatedAt = conversion.updatedAt;
                if (conversion.isStale) this.message = 'Нет сети. Используются сохранённые курсы.';
            });
        } catch {
            if (token === this.requestToken) {
                this.message = 'Не удалось обновить курс. Показаны последние значения.';
                this.rows.forEach((row) => {
                    if (!row.result) row.error = 'Нет курса';
                });
            }
        } finally {
            if (token === this.requestToken) {
                this.rows.forEach((row) => { row.loading = false; });
                this.loading = false;
            }
        }
    },
    dailyChangeLabel(currency) {
        const change = this.changes[currency];
        if (change === null || change === undefined) return '—';
        const digits = Math.abs(change) < 0.1 ? 3 : 2;
        return `${change >= 0 ? '+' : ''}${change.toFixed(digits)}%`;
    },
    dailyChangeClass(currency) {
        return this.changes[currency] >= 0 ? 'text-emerald-400' : 'text-pink-300';
    },
    async loadRow(row, refresh = false, token = this.requestToken) {
        if (row.currency === this.base) {
            row.result = this.amount;
            return;
        }
        const pair = `${this.base}:${row.currency}`;
        row.loading = true; row.error = '';
        try {
            const data = await currencyApi.conversion({ from: this.base, fromType: this.currencyType(this.base), to: row.currency, toType: this.currencyType(row.currency), refresh });
            if (token !== this.requestToken || pair !== `${this.base}:${row.currency}`) return;
            this.factors[row.currency] = data.factor;
            row.result = multiply(this.amount, data.factor);
            this.sources = [...new Set([...this.sources, ...data.sources])];
            this.lastUpdatedAt = data.updatedAt;
            if (data.isStale) this.message = 'Нет сети. Используются сохранённые курсы.';
        } catch {
            if (token === this.requestToken && pair === `${this.base}:${row.currency}`) row.error = 'Нет курса';
        } finally {
            if (token === this.requestToken) row.loading = false;
        }
    },
    activateRow(row) {
        if (!row.result || row.error) return;

        const sourceAmount = String(row.result);
        const baseChanged = row.currency !== this.base;

        if (baseChanged) {
            const previousResults = new Map(this.rows.map((item) => [item.currency, item.result]));
            this.base = row.currency;
            this.factors = {};
            this.rows.forEach((item) => {
                if (item.currency === this.base) return;
                const factor = divide(previousResults.get(item.currency), sourceAmount);
                if (factor !== null) this.factors[item.currency] = factor;
            });
            this.save();
        }

        // The current value remains visible, but the next digit starts a new amount.
        // This avoids the extra "C" tap without making the row flash to zero.
        this.amount = sourceAmount;
        this.displayAmount = sourceAmount;
        this.isFreshInput = true;
        this.recalculate(sourceAmount);
        if (baseChanged) void this.loadAll();
        this.showKeyboard();
        this.buzz();
    },
};
