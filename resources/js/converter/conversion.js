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
        if (this.providerSettingsSaving && !refresh) return;
        const targets = [...new Set(this.rows.map((row) => row.currency))].filter((currency) => !this.currencyUnsupported?.(currency));
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
        this.loading = true; this.message = '';
        this.rows.forEach((row) => {
            row.loading = !this.currencyUnsupported?.(row.currency);
            row.error = this.currencyUnsupported?.(row.currency) ? 'Не поддерживается' : '';
        });
        if (this.currencyUnsupported?.(this.base)) {
            this.rows.forEach((row) => { row.loading = false; });
            this.loading = false;
            this.message = 'Базовая валюта не поддерживается выбранным источником.';
            return;
        }
        void this.loadDailyChanges(token, targets);
        try {
            const data = await currencyApi.conversions({ from: this.base, fromType: this.currencyType(this.base), targets, refresh });
            if (token !== this.requestToken) return;
            const sources = [];
            let lastUpdatedAt = null;
            this.rows.forEach((row) => {
                if (this.currencyUnsupported?.(row.currency)) return;
                if (row.currency === this.base) {
                    row.result = this.amount;
                    row.isStale = false;
                    row.isFallback = false;
                    row.rateDate = null;
                    return;
                }
                const conversion = data.conversions[row.currency];
                if (conversion?.error) {
                    row.isStale = false;
                    row.isFallback = false;
                    row.rateDate = null;
                    row.error = this.errorLabel(conversion.error);
                    row.result = '';
                    delete this.factors[row.currency];
                    if (conversion.message && !this.message.includes(conversion.message)) {
                        this.message = [this.message, conversion.message].filter(Boolean).join(' ');
                    }
                    return;
                }
                this.factors[row.currency] = conversion.factor;
                row.result = multiply(this.amount, conversion.factor);
                sources.push(...conversion.sources);
                if (!lastUpdatedAt || Date.parse(conversion.updatedAt) < Date.parse(lastUpdatedAt)) lastUpdatedAt = conversion.updatedAt;
                row.isStale = Boolean(conversion.isStale);
                row.isFallback = Boolean(conversion.isFallback);
                row.rateDate = conversion.rateDate || conversion.rateDates?.[0] || null;
                if (conversion.isStale) this.message = 'Используются сохранённые курсы. Не удалось получить актуальные данные.';
            });
            this.sources = [...new Set(sources)];
            this.lastUpdatedAt = lastUpdatedAt;
        } catch {
            if (token === this.requestToken) {
                this.message = 'Не удалось обновить курс. Показаны последние значения.';
                this.rows.forEach((row) => {
                    if (row.currency !== this.base && row.result) {
                        row.isStale = this.currencyType(this.base) === 'crypto' || this.currencyType(row.currency) === 'crypto';
                        row.isFallback = true;
                    } else if (!row.result) row.error = 'Нет курса';
                });
            }
        } finally {
            if (token === this.requestToken) {
                this.rows.forEach((row) => { row.loading = false; });
                this.loading = false;
            }
        }
    },
    async loadDailyChanges(token, currencies) {
        const targets = [...new Set(currencies.filter((currency) => currency !== this.base))];
        this.rows.forEach((row) => {
            if (targets.includes(row.currency) || row.currency === this.base) {
                row.dailyChange = null;
                row.dailyChangeStatus = null;
            }
        });

        if (!targets.length) return;

        try {
            const data = await currencyApi.dailyChanges(targets);
            if (token !== this.requestToken) return;

            this.rows.forEach((row) => {
                if (Object.hasOwn(data.changes ?? {}, row.currency)) {
                    row.dailyChange = data.changes[row.currency];
                    row.dailyChangeStatus = data.statuses?.[row.currency] || null;
                }
            });
        } catch (error) {
            if (token !== this.requestToken) return;
            this.rows.forEach((row) => {
                if (targets.includes(row.currency)) {
                    row.dailyChangeStatus = {
                        status: 'error', code: error.code || 'provider_unavailable',
                        message: error.message || 'Не удалось получить дневное изменение.',
                        provider: error.provider || null, retryAfter: error.retryAfter ?? null,
                    };
                }
            });
        }
    },
    dailyChangeStatusLabel(row) {
        if (row.currency === this.base || row.currency === 'BYN') return '';
        if (row.dailyChangeStatus?.status === 'error') {
            return row.dailyChangeStatus.code === 'provider_rate_limited' ? 'Лимит данных' : 'Сбой данных';
        }
        return row.dailyChangeStatus?.status === 'unavailable' ? 'Нет данных' : '';
    },
    async loadRow(row, refresh = false, token = this.requestToken) {
        if (this.providerSettingsSaving && !refresh) return;
        if (this.currencyUnsupported?.(row.currency) || this.currencyUnsupported?.(this.base)) {
            row.error = 'Не поддерживается';
            row.loading = false;
            return;
        }
        if (row.currency === this.base) {
            row.result = this.amount;
            row.dailyChange = null;
            row.dailyChangeStatus = null;
            return;
        }
        void this.loadDailyChanges(token, [row.currency]);
        const pair = `${this.base}:${row.currency}`;
        row.loading = true; row.error = '';
        try {
            const data = await currencyApi.conversion({ from: this.base, fromType: this.currencyType(this.base), to: row.currency, toType: this.currencyType(row.currency), refresh });
            if (token !== this.requestToken || pair !== `${this.base}:${row.currency}`) return;
            this.factors[row.currency] = data.factor;
            row.result = multiply(this.amount, data.factor);
            this.sources = [...new Set([...this.sources, ...data.sources])];
            this.lastUpdatedAt = data.updatedAt;
            row.isStale = Boolean(data.isStale);
            row.isFallback = Boolean(data.isFallback);
            row.rateDate = data.rateDate || data.rateDates?.[0] || null;
            if (data.isStale) this.message = 'Используются сохранённые курсы. Не удалось получить актуальные данные.';
        } catch (error) {
            if (token === this.requestToken && pair === `${this.base}:${row.currency}`) {
                row.error = this.errorLabel(error.code);
                this.message = error.message || 'Не удалось получить курс для выбранной валюты.';
                if (row.result) {
                    row.isFallback = true;
                    row.isStale = this.currencyType(this.base) === 'crypto' || this.currencyType(row.currency) === 'crypto';
                }
            }
        } finally {
            if (token === this.requestToken) row.loading = false;
        }
    },
    errorLabel(code) {
        return ({
            provider_rate_limited: 'Лимит API',
            provider_timeout: 'Таймаут',
            provider_unavailable: 'Нет связи',
            unsupported_pair: 'Нет пары',
            rate_unavailable: 'Нет курса',
        })[code] || 'Нет курса';
    },
    activateRow(row) {
        if (this.providerSettingsSaving || !row.result || row.error || this.currencyUnsupported?.(row.currency)) return;

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
        }

        // The current value remains visible, but the next digit starts a new amount.
        // This avoids the extra "C" tap without making the row flash to zero.
        this.amount = sourceAmount;
        this.displayAmount = sourceAmount;
        this.isFreshInput = true;
        this.recalculate(sourceAmount);
        this.save();
        if (baseChanged) void this.loadAll();
        this.showKeyboard();
        this.buzz();
    },
};
