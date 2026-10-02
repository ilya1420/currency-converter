import { row } from './state/converter-slice.js';

const PRESETS = [
    { id: 'travel', label: 'Поездки', description: 'Основные валюты для сравнения расходов в поездке.', currencies: ['USD', 'EUR', 'BYN', 'RUB', 'GBP', 'PLN'] },
    { id: 'shopping', label: 'Покупки', description: 'Валюты для сравнения цен в зарубежных магазинах.', currencies: ['USD', 'EUR', 'BYN', 'CNY', 'GBP'] },
    { id: 'crypto', label: 'Криптовалюты', description: 'Несколько криптовалют вместе с долларом США.', currencies: ['USD', 'BTC', 'ETH', 'SOL', 'ZEC', 'LTC'] },
];

export const presetMethods = {
    get currencyPresets() {
        const available = new Set(this.currencies);
        const selected = new Set(this.rows.map(({ currency }) => currency));

        return PRESETS.map((preset) => ({
            ...preset,
            available: preset.currencies.filter((currency) => available.has(currency)),
            unavailable: preset.currencies.filter((currency) => !available.has(currency)),
            additions: preset.currencies.filter((currency) => available.has(currency) && !selected.has(currency)),
        }));
    },
    applyCurrencyPreset(id) {
        const preset = this.currencyPresets.find((item) => item.id === id);
        if (!preset?.additions.length) return;

        this.rows.push(...preset.additions.map((currency) => row(this.nextId++, currency)));
        this.factors = {};
        this.presetsOpen = false;
        this.save();
        void this.loadAll();
        this.buzz();
    },
};
