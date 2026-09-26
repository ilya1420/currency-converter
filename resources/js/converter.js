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
    const decimals = decimalPart.padEnd(2, '0').slice(0, 2);

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
    for (let position = 0; position < 2 && remainder; position++) {
        remainder *= 10n;
        fraction += (remainder / denominator).toString();
        remainder %= denominator;
    }

    return `${sign}${whole}.${fraction.replace(/0+$/, '')}`;
}

window.converter = function converter(catalog) {
    return {
        meta,
        catalog,
        currencies: catalog.map(({ code }) => code),
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
        activeTab: 'converter',
        chartCurrency: 'BTC',
        chartInterval: 60,
        chart: null,
        chartLoading: false,
        chartError: '',
        isFreshInput: true,
        keyboardVisible: true,
        requestToken: 0,
        dragIndex: null,
        sortPointerId: null,
        swipeStartX: null,
        swipeStartY: null,
        swipeGesture: null,
        pickerSwipeStartY: null,
        pickerSwipeOffset: 0,
        ignoreNextRowClick: false,
        pickerTarget: null,
        pickerSearch: '',
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
        async setTab(tab) {
            this.activeTab = tab;
            this.buzz();
            if (tab === 'charts') await this.loadChart();
        },
        get currencyGroups() {
            return [
                { title: 'Фиат', items: this.catalog.filter(({ type }) => type === 'fiat').map(({ code }) => code) },
                { title: 'Крипта', items: this.catalog.filter(({ type }) => type === 'crypto').map(({ code }) => code) },
            ];
        },
        get chartCurrencies() { return this.currencies.filter((currency) => currency !== 'BYN'); },
        isFiatCurrency(currency) { return this.catalog.find(({ code }) => code === currency)?.type === 'fiat'; },
        get chartIntervals() {
            return this.isFiatCurrency(this.chartCurrency)
                ? [{ value: 7, label: '1Н' }, { value: 30, label: '1М' }, { value: 365, label: '1Г' }]
                : [{ value: 60, label: '1H' }, { value: 240, label: '4H' }, { value: 1440, label: '1D' }];
        },
        async selectChartCurrency(currency) {
            this.chartCurrency = currency;
            this.chartInterval = this.isFiatCurrency(currency) ? 30 : 60;
            await this.loadChart();
        },
        get visibleChartCandles() { return (this.chart?.candles || []).slice(-60); },
        get chartCandles() {
            const candles = this.visibleChartCandles;
            if (!candles.length) return [];
            const high = Math.max(...candles.map((candle) => Number(candle.high)));
            const low = Math.min(...candles.map((candle) => Number(candle.low)));
            const range = high - low || 1;
            const y = (value) => 96 - ((Number(value) - low) / range) * 92;
            return candles.map((candle, index) => ({ x: (index / candles.length) * 100 + 0.7, open: y(candle.open), close: y(candle.close), high: y(candle.high), low: y(candle.low), up: Number(candle.close) >= Number(candle.open) }));
        },
        get chartCandlesMarkup() {
            return this.chartCandles.map((candle) => {
                const color = candle.up ? '#34D399' : '#EC4899';
                const top = Math.min(candle.open, candle.close);
                const height = Math.max(0.5, Math.abs(candle.close - candle.open));
                return `<line x1="${candle.x}" x2="${candle.x}" y1="${candle.high}" y2="${candle.low}" stroke="${color}" stroke-width="0.35"/><rect x="${candle.x - 0.42}" y="${top}" width="0.84" height="${height}" fill="${color}"/>`;
            }).join('');
        },
        get chartRange() {
            const candles = this.visibleChartCandles; if (!candles.length) return null;
            return { high: Math.max(...candles.map((c) => Number(c.high))), low: Math.min(...candles.map((c) => Number(c.low))), from: new Date(candles[0].time * 1000), to: new Date(candles.at(-1).time * 1000) };
        },
        chartAxisValue(value) {
            return new Intl.NumberFormat('ru-RU', { minimumFractionDigits: this.chart?.source === 'NBRB' ? 4 : 2, maximumFractionDigits: this.chart?.source === 'NBRB' ? 4 : 2 }).format(value || 0);
        },
        get chartYLabels() {
            if (!this.chartRange) return [];
            const { high, low } = this.chartRange;
            return [high, low + (high - low) / 2, low];
        },
        get chartXLabels() {
            const candles = this.visibleChartCandles;
            if (!candles.length) return [];
            return [0, 0.33, 0.66, 1].map((point) => {
                const index = Math.min(candles.length - 1, Math.round((candles.length - 1) * point));
                const date = new Date(candles[index].time * 1000);
                return this.chart?.source === 'NBRB'
                    ? date.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' })
                    : this.chartInterval === 60
                        ? date.toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit', timeZone: 'UTC' })
                        : date.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short', timeZone: 'UTC' });
            });
        },
        get chartPeriodLabel() {
            const count = this.visibleChartCandles.length;
            if (!count) return '';
            if (this.chart?.source === 'NBRB') return `Официальный курс · ${count} публикаций`;
            const unit = { 60: '1H', 240: '4H', 1440: '1D' }[this.chartInterval] || '';
            return `${unit} · ${count} свечей`;
        },
        get chartChange() {
            const candles = this.visibleChartCandles;
            if (candles.length < 2) return null;
            const first = Number(candles[0].close);
            const last = Number(candles.at(-1).close);
            if (!first || !Number.isFinite(last)) return null;
            return ((last / first) - 1) * 100;
        },
        get chartPath() {
            const candles = this.visibleChartCandles;
            if (candles.length < 2) return '';
            const values = candles.map((candle) => Number(candle.close));
            const low = Math.min(...values); const high = Math.max(...values); const range = high - low || 1;
            return values.map((value, index) => `${index ? 'L' : 'M'}${(index / (values.length - 1)) * 100} ${96 - ((value - low) / range) * 92}`).join(' ');
        },
        chartValue(value) { return new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 }).format(Number(value || 0)); },
        get marketStats() {
            const ticker = this.chart?.ticker;
            const bids = this.chart?.depth?.bids || [];
            const asks = this.chart?.depth?.asks || [];
            if (!ticker || !bids.length || !asks.length) return null;
            const bidVolume = bids.reduce((total, level) => total + Number(level[1]), 0);
            const askVolume = asks.reduce((total, level) => total + Number(level[1]), 0);
            const bestBid = Number(bids[0][0]); const bestAsk = Number(asks[0][0]);
            return {
                change: ((Number(ticker.last) / Number(ticker.open || 1)) - 1) * 100,
                spread: ((bestAsk - bestBid) / bestBid) * 100,
                imbalance: (bidVolume / (bidVolume + askVolume || 1)) * 100,
                bidVolume,
                askVolume,
            };
        },
        get fiatStats() {
            const candles = this.chart?.source === 'NBRB' ? this.visibleChartCandles : [];
            if (candles.length < 2 || this.chartChange === null) return null;
            return { change: this.chartChange, observations: candles.length, updated: new Date(candles.at(-1).time * 1000) };
        },
        async loadChart() {
            this.chartLoading = true; this.chartError = '';
            try {
                const response = await fetch(`/market/${this.chartCurrency}?interval=${this.chartInterval}`);
                const data = await response.json();
                if (!response.ok) throw new Error(data.message);
                this.chart = data;
            } catch (error) { this.chartError = 'Не удалось загрузить данные рынка.'; }
            finally { this.chartLoading = false; }
        },

        get pickerTitle() {
            if (this.pickerTarget === 'add') return 'Добавить валюту';
            return this.pickerTarget === 'base' ? 'Базовая валюта' : 'Валюта в строке';
        },

        openPicker(target) {
            this.pickerTarget = target;
            this.pickerSearch = '';
            this.buzz();
        },

        closePicker() { this.pickerTarget = null; this.pickerSwipeOffset = 0; },

        pickerSwipeStart(event) { this.pickerSwipeStartY = event.touches[0]?.clientY ?? null; },
        pickerSwipeMove(event) {
            if (this.pickerSwipeStartY === null) return;
            this.pickerSwipeOffset = Math.max(0, event.touches[0].clientY - this.pickerSwipeStartY);
        },
        pickerSwipeEnd() {
            if (this.pickerSwipeOffset > 96) this.closePicker();
            else this.pickerSwipeOffset = 0;
            this.pickerSwipeStartY = null;
        },

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
            if (this.pickerTarget === 'base' || this.pickerTarget === 'add') return true;
            const row = this.rows.find((item) => item.id === this.pickerTarget);
            return currency !== this.base && !this.rows.some((item) => item !== row && item.currency === currency);
        },

        chooseCurrency(currency) {
            if (!this.canChoose(currency)) return;
            if (this.pickerTarget === 'base') {
                this.base = currency;
                this.baseChanged();
            } else if (this.pickerTarget === 'add') {
                const index = this.rows.findIndex((row) => row.currency === currency);
                if (index >= 0) this.removeRow(index);
                else this.addRow(currency);
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
            this.swipeGesture = null;
        },

        swipeMove(index, event) {
            if (this.swipeStartX === null || this.swipeStartY === null) return;
            const touch = event.touches[0];
            const horizontalDistance = touch.clientX - this.swipeStartX;
            const verticalDistance = touch.clientY - this.swipeStartY;
            if (Math.abs(verticalDistance) > 8 && Math.abs(verticalDistance) > Math.abs(horizontalDistance)) {
                this.swipeGesture = 'scroll';
                return;
            }
            if (horizontalDistance < -8 && Math.abs(horizontalDistance) > Math.abs(verticalDistance)) {
                this.swipeGesture = 'swipe';
                this.rows[index].swipeOffset = Math.max(horizontalDistance, -180);
                event.preventDefault();
            }
        },

        swipeEnd(index) {
            const row = this.rows[index];
            if (this.swipeGesture === 'swipe' && row.swipeOffset < -104) {
                this.swipeRemove(index);
            } else {
                row.swipeOffset = 0;
            }
            if (this.swipeGesture) {
                this.ignoreNextRowClick = true;
                setTimeout(() => { this.ignoreNextRowClick = false; }, 80);
            }
            this.swipeStartX = null;
            this.swipeStartY = null;
            this.swipeGesture = null;
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

        sortStart(index, event) {
            if (event.pointerType === 'mouse' && event.button !== 0) return;
            this.dragIndex = index;
            this.sortPointerId = event.pointerId;
            event.currentTarget.setPointerCapture?.(event.pointerId);
            this.buzz();
        },
        sortMove(event) {
            if (this.dragIndex === null || event.pointerId !== this.sortPointerId) return;
            const element = document.elementFromPoint(event.clientX, event.clientY)?.closest('[data-currency-row]');
            const target = Number(element?.dataset.currencyRow);
            if (Number.isInteger(target) && target >= 0 && target !== this.dragIndex) {
                this.moveRow(this.dragIndex, target, false);
                this.dragIndex = target;
            }
        },
        sortEnd(event) {
            if (event.pointerId !== this.sortPointerId) return;
            this.dragIndex = null;
            this.sortPointerId = null;
            this.save();
        },

        moveRow(from, to, withHaptic = true) {
            const [row] = this.rows.splice(from, 1);
            this.rows.splice(to, 0, row);
            this.save();
            if (withHaptic) this.buzz();
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
                if (this.amount.split(/[+\-*/]/).at(-1).split('.')[1]?.length >= 2) return;
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
                    headers: { 'Content-Type': 'application/json' },
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
