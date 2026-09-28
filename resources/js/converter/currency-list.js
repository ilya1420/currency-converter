import { converterStorage } from './storage.js';

export const currencyListMethods = {
    get currencyGroups() {
        const crypto = this.catalog.filter(({ type }) => type === 'crypto');
        const visible = (key) => this.cryptoGroups[key] !== false;
        return [
            { title: 'Обычные валюты', key: 'fiat', type: 'fiat', items: visible('fiat') ? this.catalog.filter(({ type }) => type === 'fiat').map(({ code }) => code) : [] },
            { title: 'Популярные', key: 'popular', type: 'crypto', items: visible('popular') ? crypto.filter(({ group }) => group === 'popular').map(({ code }) => code) : [] },
            { title: 'Все остальные', key: 'other', type: 'crypto', items: visible('other') ? crypto.filter(({ group }) => group !== 'popular').map(({ code }) => code) : [] },
        ];
    },
    get cryptoGroupFilters() {
        return [
            { key: 'popular', label: 'Популярные' },
            { key: 'other', label: 'Все остальные' },
        ];
    },
    toggleCryptoGroup(group) { this.cryptoGroups[group] = !this.cryptoGroups[group]; converterStorage.saveCryptoGroups(this.cryptoGroups); },
    currencyInfo(currency) { return this.catalog.find(({ code }) => code === currency) || { code: currency, name: null }; },
    currencyName(currency) {
        const info = this.currencyInfo(currency);
        if (info.type === 'fiat' && typeof Intl.DisplayNames === 'function') {
            const name = new Intl.DisplayNames(['ru'], { type: 'currency' }).of(currency);
            if (name) return name.charAt(0).toUpperCase() + name.slice(1);
        }
        return info.name || currency;
    },
    badgeText(currency) { return currency.length > 4 ? currency.slice(0, 3) : currency; },
    badgeTextClass(currency) { return currency.length > 4 ? 'text-[9px] tracking-normal' : currency.length === 4 ? 'text-[10px]' : 'text-xs'; },
    get pickerTitle() {
        if (this.pickerTarget === 'add') return 'Добавить валюту';
        return 'Валюта в строке';
    },
    setPickerMarket(market) { this.pickerMarket = market; this.pickerSearch = ''; this.buzz(); },
    openPicker(target) {
        this.pickerTarget = target;
        this.pickerSearch = '';
        const row = this.rows.find((item) => item.id === target);
        this.pickerMarket = target === 'add' ? 'fiat' : (row && this.currencyType(row.currency) === 'crypto' ? 'crypto' : 'fiat');
        this.buzz();
    },
    closePicker() { this.pickerTarget = null; this.pickerSwipeOffset = 0; },
    filteredCurrencies(currencies) {
        const query = this.pickerSearch.trim().toLowerCase();
        return query ? currencies.filter((currency) => `${currency} ${this.currencyName(currency)}`.toLowerCase().includes(query)) : currencies;
    },
    isSelected(currency) {
        if (this.pickerTarget === 'add') return this.rows.some((row) => row.currency === currency);
        return this.rows.find((row) => row.id === this.pickerTarget)?.currency === currency;
    },
    canChoose(currency) {
        if (this.pickerTarget === 'add') return true;
        const row = this.rows.find((item) => item.id === this.pickerTarget);
        return !this.rows.some((item) => item !== row && item.currency === currency);
    },
    chooseCurrency(currency) {
        if (!this.canChoose(currency)) return;
        if (this.pickerTarget === 'add') {
            const index = this.rows.findIndex((row) => row.currency === currency);
            if (index >= 0) this.removeRow(index); else this.addRow(currency);
            return;
        } else {
            const row = this.rows.find((item) => item.id === this.pickerTarget);
            if (row) { row.currency = currency; this.rowChanged(row); }
        }
        this.closePicker();
    },
    rowChanged(row) {
        const wasActive = row.previousCurrency === this.base;
        if (this.rows.some((other) => other !== row && other.currency === row.currency)) {
            row.currency = row.previousCurrency; this.message = 'Каждая валюта может быть в списке только один раз.'; return;
        }
        row.previousCurrency = row.currency;
        if (wasActive) this.base = row.currency;
        row.error = ''; this.save(); this.loadAll();
    },
    addRow(currency, withHaptic = true) {
        if (!currency || this.rows.some((row) => row.currency === currency)) return;
        const row = { id: this.nextId++, currency, previousCurrency: currency, result: '', error: '', loading: false, swipeOffset: 0 };
        this.rows.push(row); this.save(); this.loadRow(row); if (withHaptic) this.buzz();
    },
    removeRow(index) {
        if (this.rows.length === 1) return;
        const removed = this.rows[index];
        this.rows.splice(index, 1); this.save(); this.buzz();
        if (removed.currency === this.base) {
            this.base = this.rows[Math.max(0, index - 1)]?.currency || this.rows[0].currency;
            this.loadAll();
        }
    },
    pickerSwipeStart(event) { this.pickerSwipeStartY = event.touches[0]?.clientY ?? null; },
    pickerSwipeMove(event) {
        if (this.pickerSwipeStartY === null) return;
        this.pickerSwipeOffset = Math.max(0, event.touches[0].clientY - this.pickerSwipeStartY);
    },
    pickerSwipeEnd() {
        if (this.pickerSwipeOffset > 96) this.closePicker(); else this.pickerSwipeOffset = 0;
        this.pickerSwipeStartY = null;
    },
};
