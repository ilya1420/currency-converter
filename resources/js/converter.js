const FIAT = new Set(['BYN', 'USD', 'EUR', 'PLN', 'GBP', 'CNY', 'RUB', 'UAH']);

const meta = {
    BYN: { label: 'BYN', mark: 'Br', color: 'linear-gradient(135deg, #D83A4E, #157A4B)' },
    USD: { label: 'USD', mark: '$', color: 'linear-gradient(135deg, #2563EB, #1E40AF)' },
    EUR: { label: 'EUR', mark: '€', color: 'linear-gradient(135deg, #1D4ED8, #EAB308)' },
    PLN: { label: 'PLN', mark: 'zł', color: 'linear-gradient(135deg, #E5E7EB, #DC2626)' },
    GBP: { label: 'GBP', mark: '£', color: 'linear-gradient(135deg, #1D4ED8, #DC2626)' },
    CNY: { label: 'CNY', mark: '¥', color: 'linear-gradient(135deg, #DC2626, #991B1B)' },
    RUB: { label: 'RUB', mark: '₽', color: 'linear-gradient(135deg, #2563EB, #B91C1C)' },
    UAH: { label: 'UAH', mark: '₴', color: 'linear-gradient(135deg, #2563EB, #EAB308)' },
    BTC: { label: 'BTC', mark: '₿', color: 'linear-gradient(135deg, #F7931A, #C2410C)' },
    ETH: { label: 'ETH', mark: 'Ξ', color: 'linear-gradient(135deg, #627EEA, #3730A3)' },
    USDT: { label: 'USDT', mark: '₮', color: 'linear-gradient(135deg, #26A17B, #047857)' },
    SOL: { label: 'SOL', mark: 'S', color: 'linear-gradient(135deg, #9945FF, #14F195)' },
    XRP: { label: 'XRP', mark: 'X', color: 'linear-gradient(135deg, #334155, #0F172A)' },
};

function multiply(left, right) {
    const decimal = (value) => {
        const [whole, fraction = ''] = String(value).replace(',', '.').split('.');
        return [BigInt(`${whole}${fraction}` || '0'), fraction.length];
    };
    const [leftValue, leftScale] = decimal(left);
    const [rightValue, rightScale] = decimal(right);
    let value = (leftValue * rightValue).toString();
    const scale = leftScale + rightScale;

    if (scale) {
        value = value.padStart(scale + 1, '0');
        value = `${value.slice(0, -scale)}.${value.slice(-scale)}`;
    }

    return value.replace(/\.0+$/, '').replace(/(\.\d*?)0+$/, '$1');
}

function formatAmount(value, currency) {
    if (!value) return '—';

    const [integerPart, decimalPart = ''] = String(value).split('.');
    const sign = integerPart.startsWith('-') ? '-' : '';
    const whole = `${sign}${integerPart.replace('-', '').replace(/\B(?=(\d{3})+(?!\d))/g, ' ' )}`;
    const decimals = FIAT.has(currency)
        ? decimalPart.padEnd(2, '0').slice(0, 2)
        : decimalPart.slice(0, 8).replace(/0+$/, '');

    return decimals ? `${whole}.${decimals}` : whole;
}

function parseExpression(input) {
    const clean = input.replace(/\s/g, '');
    const tokens = clean.match(/\d+(?:\.\d+)?|[()+\-*/%]/g) || [];
    if (!tokens.length || tokens.join('') !== clean) throw new Error('Invalid expression');

    let index = 0;
    const gcd = (left, right) => {
        let a = left < 0n ? -left : left;
        let b = right;
        while (b) [a, b] = [b, a % b];
        return a;
    };
    const normalize = (numerator, denominator) => {
        if (!denominator) throw new Error('Division by zero');
        if (denominator < 0n) [numerator, denominator] = [-numerator, -denominator];
        const divisor = gcd(numerator, denominator);
        return { numerator: numerator / divisor, denominator: denominator / divisor };
    };
    const number = (value) => {
        const [whole, fraction = ''] = value.split('.');
        return normalize(BigInt(`${whole}${fraction}`), 10n ** BigInt(fraction.length));
    };
    const primary = () => {
        if (tokens[index] === '(') {
            index++;
            const value = add();
            if (tokens[index++] !== ')') throw new Error('Unclosed parenthesis');
            return value;
        }
        if (!/^\d/.test(tokens[index] || '')) throw new Error('Expected number');
        return number(tokens[index++]);
    };
    const term = () => {
        let value = primary();
        while (['*', '/', '%'].includes(tokens[index])) {
            const operator = tokens[index++];
            if (operator === '%') {
                value = normalize(value.numerator, value.denominator * 100n);
                continue;
            }
            const right = primary();
            value = operator === '*'
                ? normalize(value.numerator * right.numerator, value.denominator * right.denominator)
                : normalize(value.numerator * right.denominator, value.denominator * right.numerator);
        }
        return value;
    };
    const add = () => {
        let value = term();
        while (['+', '-'].includes(tokens[index])) {
            const operator = tokens[index++];
            const right = term();
            value = normalize(
                operator === '+'
                    ? value.numerator * right.denominator + right.numerator * value.denominator
                    : value.numerator * right.denominator - right.numerator * value.denominator,
                value.denominator * right.denominator,
            );
        }
        return value;
    };

    const result = add();
    if (index !== tokens.length) throw new Error('Unexpected token');
    return result;
}

function fractionToDecimal({ numerator, denominator }) {
    const sign = numerator < 0n ? '-' : '';
    let value = numerator < 0n ? -numerator : numerator;
    const whole = value / denominator;
    let remainder = value % denominator;
    if (!remainder) return `${sign}${whole}`;

    let fraction = '';
    for (let position = 0; position < 12 && remainder; position++) {
        remainder *= 10n;
        fraction += (remainder / denominator).toString();
        remainder %= denominator;
    }

    return `${sign}${whole}.${fraction.replace(/0+$/, '')}`;
}

window.converter = function converter() {
    return {
        meta,
        currencies: Object.keys(meta),
        base: 'USD',
        amount: '100',
        displayAmount: '100',
        rows: [
            { id: 1, currency: 'EUR', previousCurrency: 'EUR', result: '', error: '', loading: false, swipeOffset: 0 },
            { id: 2, currency: 'BYN', previousCurrency: 'BYN', result: '', error: '', loading: false, swipeOffset: 0 },
            { id: 3, currency: 'RUB', previousCurrency: 'RUB', result: '', error: '', loading: false, swipeOffset: 0 },
        ],
        nextId: 4,
        factors: {},
        sources: [],
        message: '',
        lastUpdatedAt: null,
        loading: false,
        isFreshInput: true,
        keyboardVisible: true,
        requestToken: 0,
        dragIndex: null,
        touchStartY: null,
        touchStartX: null,
        touchDragTimer: null,
        touchDragging: false,
        swipeStartX: null,
        swipeStartY: null,
        ignoreNextRowClick: false,
        pickerTarget: null,
        pickerSearch: '',
        currencyGroups: [
            { title: 'Фиат', items: ['BYN', 'USD', 'EUR', 'PLN', 'GBP', 'CNY', 'RUB', 'UAH'] },
            { title: 'Крипта', items: ['BTC', 'ETH', 'USDT', 'SOL', 'XRP'] },
        ],
        keys: ['C', '⌫', '%', '/', '7', '8', '9', '*', '4', '5', '6', '-', '1', '2', '3', '+'],

        init() {
            const saved = JSON.parse(localStorage.getItem('currency-converter-layout') || 'null');
            if (saved && meta[saved.base] && Array.isArray(saved.rows)) {
                this.base = saved.base;
                this.keyboardVisible = saved.keyboardVisible !== false;
                this.rows = saved.rows
                    .filter((row) => meta[row.currency] && row.currency !== saved.base)
                    .map((row, index) => ({ id: index + 1, currency: row.currency, previousCurrency: row.currency, result: '', error: '', loading: false, swipeOffset: 0 }));
                this.nextId = this.rows.length + 1;
            }
            if (!this.rows.length) this.addRow(this.currencies.find((currency) => currency !== this.base), false);
            this.loadAll();
        },

        isOperator(key) { return ['+', '-', '*', '/', '%'].includes(key); },
        get lastUpdatedLabel() {
            if (!this.lastUpdatedAt) return '';
            return `Обновлено ${new Intl.DateTimeFormat('ru-RU', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(this.lastUpdatedAt))}`;
        },
        formatAmount,
        buzz() { if (navigator.vibrate) navigator.vibrate(8); },
        save() { localStorage.setItem('currency-converter-layout', JSON.stringify({ base: this.base, keyboardVisible: this.keyboardVisible, rows: this.rows.map(({ currency }) => ({ currency })) })); },
        toggleKeyboard() { this.keyboardVisible = !this.keyboardVisible; this.save(); this.buzz(); },

        get pickerTitle() {
            if (this.pickerTarget === 'add') return 'Добавить валюту';
            return this.pickerTarget === 'base' ? 'Базовая валюта' : 'Валюта в строке';
        },

        openPicker(target) {
            this.pickerTarget = target;
            this.pickerSearch = '';
            this.buzz();
        },

        closePicker() { this.pickerTarget = null; },

        filteredCurrencies(currencies) {
            const query = this.pickerSearch.trim().toLowerCase();
            if (!query) return currencies;
            return currencies.filter((currency) => meta[currency].label.toLowerCase().includes(query));
        },

        isSelected(currency) {
            if (this.pickerTarget === 'base') return this.base === currency;
            if (this.pickerTarget === 'add') return this.rows.some((row) => row.currency === currency);
            return this.rows.find((row) => row.id === this.pickerTarget)?.currency === currency;
        },

        canChoose(currency) {
            if (this.pickerTarget === 'base') return true;
            const row = this.rows.find((item) => item.id === this.pickerTarget);
            return currency !== this.base && !this.rows.some((item) => item !== row && item.currency === currency);
        },

        chooseCurrency(currency) {
            if (!this.canChoose(currency)) return;
            if (this.pickerTarget === 'base') {
                this.base = currency;
                this.baseChanged();
            } else if (this.pickerTarget === 'add') {
                this.addRow(currency);
                return;
            } else {
                const row = this.rows.find((item) => item.id === this.pickerTarget);
                if (row) {
                    row.currency = currency;
                    this.rowChanged(row);
                }
            }
            this.closePicker();
        },

        baseChanged() {
            this.rows.forEach((row) => {
                if (row.currency === this.base) row.currency = row.previousCurrency;
                row.previousCurrency = row.currency;
            });
            this.ensureUniqueRows();
            this.save();
            this.loadAll();
        },

        rowChanged(row) {
            if (row.currency === this.base || this.rows.some((other) => other !== row && other.currency === row.currency)) {
                row.currency = row.previousCurrency;
                this.message = 'Каждая валюта может быть в списке только один раз.';
                return;
            }
            row.previousCurrency = row.currency;
            row.error = '';
            this.save();
            this.loadRow(row);
        },

        ensureUniqueRows() {
            const used = new Set([this.base]);
            this.rows.forEach((row) => {
                if (used.has(row.currency)) row.currency = this.currencies.find((currency) => !used.has(currency)) || row.currency;
                row.previousCurrency = row.currency;
                used.add(row.currency);
            });
        },

        addRow(currency, withHaptic = true) {
            if (!currency || currency === this.base || this.rows.some((row) => row.currency === currency)) return;
            const row = { id: this.nextId++, currency, previousCurrency: currency, result: '', error: '', loading: false, swipeOffset: 0 };
            this.rows.push(row);
            this.save();
            this.loadRow(row);
            if (withHaptic) this.buzz();
        },

        removeRow(index) {
            if (this.rows.length === 1) return;
            this.rows.splice(index, 1);
            this.save();
            this.buzz();
        },

        deleteBackgroundOpacity(row) {
            return Math.min(1, Math.abs(row.swipeOffset) / 100);
        },

        swipeStart(index, event) {
            this.swipeStartX = event.touches[0]?.clientX ?? null;
            this.swipeStartY = event.touches[0]?.clientY ?? null;
            this.rows[index].swipeOffset = 0;
        },

        swipeMove(index, event) {
            if (this.swipeStartX === null || this.swipeStartY === null) return;
            const touch = event.touches[0];
            const horizontalDistance = touch.clientX - this.swipeStartX;
            const verticalDistance = touch.clientY - this.swipeStartY;
            if (horizontalDistance < 0 && Math.abs(horizontalDistance) > Math.abs(verticalDistance)) {
                this.rows[index].swipeOffset = Math.max(horizontalDistance, -180);
            }
        },

        swipeEnd(index) {
            const row = this.rows[index];
            if (row.swipeOffset < -104) {
                this.swipeRemove(index);
            } else {
                row.swipeOffset = 0;
            }
            this.swipeStartX = null;
            this.swipeStartY = null;
        },

        swipeRemove(index) {
            if (this.rows.length === 1) return;
            const row = this.rows[index];
            row.swipeOffset = -500;
            this.ignoreNextRowClick = true;
            setTimeout(() => {
                const currentIndex = this.rows.findIndex((item) => item.id === row.id);
                if (currentIndex >= 0) this.removeRow(currentIndex);
                this.ignoreNextRowClick = false;
            }, 180);
        },

        dragStart(index) { this.dragIndex = index; },

        dropRow(index) {
            if (this.dragIndex === null || this.dragIndex === index) return;
            this.moveRow(this.dragIndex, index);
            this.ignoreNextRowClick = true;
            setTimeout(() => { this.ignoreNextRowClick = false; }, 0);
            this.dragIndex = null;
        },

        touchStart(index, event) {
            this.dragIndex = index;
            this.touchStartY = event.touches[0]?.clientY ?? null;
            this.touchStartX = event.touches[0]?.clientX ?? null;
            this.touchDragging = false;
            this.touchDragTimer = setTimeout(() => {
                this.touchDragging = true;
                this.buzz();
            }, 280);
        },

        touchEnd(index, event) {
            const endY = event.changedTouches[0]?.clientY;
            const endX = event.changedTouches[0]?.clientX;
            if (this.touchStartY === null || this.touchStartX === null || endY === undefined || endX === undefined) return;
            clearTimeout(this.touchDragTimer);
            const verticalDistance = endY - this.touchStartY;
            const steps = Math.round(Math.abs(verticalDistance) / 72);
            if (this.touchDragging && steps > 0) {
                const target = Math.max(0, Math.min(this.rows.length - 1, index + (verticalDistance > 0 ? steps : -steps)));
                if (target !== index) {
                    this.moveRow(index, target);
                    this.ignoreNextRowClick = true;
                    setTimeout(() => { this.ignoreNextRowClick = false; }, 0);
                }
            }
            this.dragIndex = null;
            this.touchStartY = null;
            this.touchStartX = null;
            this.touchDragging = false;
        },

        moveRow(from, to) {
            const [row] = this.rows.splice(from, 1);
            this.rows.splice(to, 0, row);
            this.save();
            this.buzz();
        },

        makeBase(index) {
            if (this.ignoreNextRowClick) return;
            const row = this.rows[index];
            if (!row || row.loading) return;
            const previousBase = this.base;
            this.base = row.currency;
            row.currency = previousBase;
            row.previousCurrency = previousBase;
            this.save();
            this.loadAll();
            this.buzz();
        },

        press(key) {
            this.buzz();
            if (key === 'C') return this.clear();
            if (key === '⌫') return this.backspace();
            if (this.isOperator(key)) {
                if (!this.amount || /[+\-*/]$/.test(this.amount)) return;
                this.amount += key;
                this.isFreshInput = false;
            } else if (key === '.') {
                if (this.isFreshInput) { this.amount = '0'; this.isFreshInput = false; }
                const lastNumber = this.amount.split(/[+\-*/]/).at(-1);
                if (!lastNumber.includes('.')) this.amount += key;
            } else {
                this.amount = this.isFreshInput || this.amount === '0' ? key : this.amount + key;
                this.isFreshInput = false;
            }
            this.displayAmount = this.amount;
        },

        backspace() {
            if (this.isFreshInput) return;
            this.amount = this.amount.slice(0, -1) || '0';
            this.displayAmount = this.amount;
            this.recalculate();
        },

        clear() {
            this.amount = '0';
            this.displayAmount = '0';
            this.isFreshInput = true;
            this.recalculate();
        },

        calculate() {
            try {
                this.amount = fractionToDecimal(parseExpression(this.amount));
                this.displayAmount = this.amount;
                this.isFreshInput = true;
                this.recalculate();
            } catch {
                this.message = 'Проверьте выражение.';
            }
            this.buzz();
        },

        recalculate() {
            this.rows.forEach((row) => {
                if (this.factors[row.currency]) row.result = multiply(this.amount, this.factors[row.currency]);
            });
        },

        async refreshAll() { await this.loadAll(true); },

        async loadAll(refresh = false) {
            const token = ++this.requestToken;
            this.loading = true;
            this.sources = [];
            this.message = '';
            await Promise.all(this.rows.map((row) => this.loadRow(row, refresh, token)));
            if (token === this.requestToken) this.loading = false;
        },

        async loadRow(row, refresh = false, token = this.requestToken) {
            const pair = `${this.base}:${row.currency}`;
            row.loading = true;
            row.error = '';
            try {
                const response = await fetch('/conversion', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content },
                    body: JSON.stringify({ amount: '1', from: this.base, to: row.currency, refresh }),
                });
                const data = await response.json();
                if (!response.ok) throw new Error(data.message);
                if (token !== this.requestToken || pair !== `${this.base}:${row.currency}`) return;
                this.factors[row.currency] = data.factor;
                row.result = multiply(this.amount, data.factor);
                this.sources = [...new Set([...this.sources, ...data.sources])];
                this.lastUpdatedAt = data.updatedAt;
                if (data.isStale) this.message = 'Нет сети. Используются сохранённые курсы.';
            } catch (error) {
                if (token === this.requestToken && pair === `${this.base}:${row.currency}`) row.error = 'Нет курса';
            } finally {
                if (token === this.requestToken) row.loading = false;
            }
        },
    };
};
