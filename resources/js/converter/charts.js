import { isCrypto } from './currency-meta.js';
import { currencyApi } from './api.js';

const CRYPTO_INTERVALS = [{ value: 60, label: '1H' }, { value: 240, label: '4H' }, { value: 1440, label: '1D' }];
const FIAT_INTERVALS = [{ value: 7, label: '1Н' }, { value: 30, label: '1М' }, { value: 365, label: '1Г' }];

export const chartMethods = {
    async setTab(tab) {
        this.activeTab = tab;
        this.buzz();
        if (tab === 'charts') await this.loadChart();
    },
    get allChartCurrencies() {
        return this.currencies.filter((currency) => {
            if (currency === 'BYN') return false;
            return this.chartMarket === 'crypto' ? !this.isFiatCurrency(currency) : this.isFiatCurrency(currency);
        });
    },
    get chartCurrencies() {
        const query = this.chartSearch.trim().toLowerCase();
        if (!query) return this.allChartCurrencies;
        return this.allChartCurrencies.filter((currency) => `${currency} ${this.currencyName(currency)}`.toLowerCase().includes(query));
    },
    isFiatCurrency(currency) { return !isCrypto(this.catalog, currency); },
    async setChartMarket(market) {
        this.chartMarket = market;
        this.chartSearch = '';
        const currencies = this.allChartCurrencies;
        if (!currencies.includes(this.chartCurrency)) {
            this.chartCurrency = currencies[0] || (market === 'crypto' ? 'BTC' : 'USD');
        }
        this.chartInterval = this.isFiatCurrency(this.chartCurrency) ? 30 : 60;
        await this.loadChart();
    },
    get chartIntervals() { return this.isFiatCurrency(this.chartCurrency) ? FIAT_INTERVALS : CRYPTO_INTERVALS; },
    async selectChartCurrency(currency) {
        this.chartCurrency = currency;
        this.chartInterval = this.isFiatCurrency(currency) ? 30 : 60;
        await this.loadChart();
    },
    get visibleChartCandles() {
        if (!Array.isArray(this.chart?.candles)) return [];

        return this.chart.candles.flatMap((candle) => {
            if (!candle || typeof candle !== 'object') return [];

            const normalized = {
                time: Number(candle.time),
                open: Number(candle.open),
                high: Number(candle.high),
                low: Number(candle.low),
                close: Number(candle.close),
            };
            const prices = [normalized.open, normalized.high, normalized.low, normalized.close];

            if (!Number.isSafeInteger(normalized.time) || normalized.time <= 0
                || prices.some((price) => !Number.isFinite(price) || price <= 0)
                || normalized.high < Math.max(normalized.open, normalized.close, normalized.low)
                || normalized.low > Math.min(normalized.open, normalized.close)) {
                return [];
            }

            return [normalized];
        }).slice(-60);
    },
    get chartCandles() {
        const candles = this.visibleChartCandles;
        if (!candles.length) return [];
        const high = Math.max(...candles.map((candle) => Number(candle.high)));
        const low = Math.min(...candles.map((candle) => Number(candle.low)));
        const range = high - low || 1;
        const y = (value) => 96 - ((Number(value) - low) / range) * 92;
        return candles.map((candle, index) => ({
            x: (index / candles.length) * 100 + 0.7,
            open: y(candle.open), close: y(candle.close), high: y(candle.high), low: y(candle.low),
            up: Number(candle.close) >= Number(candle.open),
        }));
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
        const candles = this.visibleChartCandles;
        if (!candles.length) return null;
        return {
            high: Math.max(...candles.map((candle) => Number(candle.high))),
            low: Math.min(...candles.map((candle) => Number(candle.low))),
            from: new Date(candles[0].time * 1000),
            to: new Date(candles.at(-1).time * 1000),
        };
    },
    chartAxisValue(value) {
        const magnitude = Math.abs(Number(value || 0));
        const digits = this.chart?.source === 'NBRB'
            ? Math.min(6, Math.max(2, magnitude < 0.01 ? 6 : magnitude < 1 ? 4 : 2))
            : Math.min(8, Math.max(2, magnitude < 0.0001 ? 8 : magnitude < 0.01 ? 6 : magnitude < 1 ? 4 : 2));
        return new Intl.NumberFormat('ru-RU', { minimumFractionDigits: digits, maximumFractionDigits: digits }).format(value || 0);
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
            if (this.chart?.source === 'NBRB') return date.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' });
            return this.chartInterval === 60
                ? date.toLocaleString('ru-RU', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit', timeZone: 'UTC' })
                : date.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short', timeZone: 'UTC' });
        });
    },
    get chartPeriodLabel() {
        const count = this.visibleChartCandles.length;
        if (!count) return '';
        if (this.chart?.source === 'NBRB') return `Официальный курс · ${count} публикаций`;
        return `${{ 60: '1H', 240: '4H', 1440: '1D' }[this.chartInterval] || ''} · ${count} свечей`;
    },
    get chartQuoteLabel() {
        if (this.chart?.source === 'NBRB') return `Официальный курс · BYN за 1 ${this.chartCurrency}`;
        return `Рыночная цена · USD за 1 ${this.chartCurrency}`;
    },
    get chartQuoteCurrency() { return this.chart?.source === 'NBRB' ? 'BYN' : 'USD'; },
    get chartChange() {
        const candles = this.visibleChartCandles;
        if (candles.length < 2) return null;
        const first = Number(candles[0].close);
        const last = Number(candles.at(-1).close);
        return first && Number.isFinite(last) ? ((last / first) - 1) * 100 : null;
    },
    get chartPath() {
        const candles = this.visibleChartCandles;
        if (candles.length < 2) return '';
        const values = candles.map((candle) => Number(candle.close));
        const low = Math.min(...values); const high = Math.max(...values); const range = high - low || 1;
        return values.map((value, index) => `${index ? 'L' : 'M'}${(index / (values.length - 1)) * 100} ${96 - ((value - low) / range) * 92}`).join(' ');
    },
    chartValue(value) { return new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 2 }).format(Number(value || 0)); },
    get rolling24hChange() {
        const candles = this.visibleChartCandles;
        if (candles.length < 2) return null;
        const last = candles.at(-1); const targetTime = last.time - 86_400;
        const baseline = candles.reduce((closest, candle) => Math.abs(candle.time - targetTime) < Math.abs(closest.time - targetTime) ? candle : closest);
        const start = Number(baseline.close); const end = Number(last.close);
        return start && Number.isFinite(end) ? ((end / start) - 1) * 100 : null;
    },
    formatUsd(value) { return new Intl.NumberFormat('ru-RU', { style: 'currency', currency: 'USD', notation: 'compact', maximumFractionDigits: 1 }).format(Number(value || 0)); },
    get marketStats() {
        const ticker = this.chart?.ticker; const bids = this.chart?.depth?.bids || []; const asks = this.chart?.depth?.asks || [];
        if (!ticker || !bids.length || !asks.length) return null;
        const volume = (levels) => levels.reduce((total, level) => total + Number(level[1]), 0);
        const liquidity = (levels) => levels.reduce((total, [price, amount]) => total + Number(price) * Number(amount), 0);
        const bidVolume = volume(bids); const askVolume = volume(asks); const bestBid = Number(bids[0][0]); const bestAsk = Number(asks[0][0]);
        return {
            change: this.rolling24hChange,
            spread: bestBid > 0 ? ((bestAsk - bestBid) / bestBid) * 100 : null,
            imbalance: (bidVolume / (bidVolume + askVolume || 1)) * 100,
            bidVolume,
            askVolume,
            liquidity: liquidity(bids) + liquidity(asks),
        };
    },
    get fiatStats() {
        const candles = this.chart?.source === 'NBRB' ? this.visibleChartCandles : [];
        if (candles.length < 2 || this.chartChange === null) return null;
        return { change: this.chartChange, observations: candles.length, updated: new Date(candles.at(-1).time * 1000) };
    },
    async loadChart() {
        const token = ++this.chartRequestToken;
        this.chart = null;
        this.chartLoading = true; this.chartError = '';
        try {
            const chart = await currencyApi.market(this.chartCurrency, this.chartInterval, this.currencyType(this.chartCurrency));
            if (token === this.chartRequestToken) this.chart = chart;
        } catch {
            if (token === this.chartRequestToken) this.chartError = 'Не удалось загрузить данные рынка.';
        } finally {
            if (token === this.chartRequestToken) this.chartLoading = false;
        }
    },
};
