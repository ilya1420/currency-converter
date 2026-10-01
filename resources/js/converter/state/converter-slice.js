const row = (id, currency) => ({ id, currency, previousCurrency: currency, result: '', dailyChange: null, error: '', loading: false, swipeOffset: 0 });

export function converterSlice(catalog) {
    return {
        catalog,
        currencies: catalog.map(({ code }) => code),
        base: 'USD',
        amount: '100',
        displayAmount: '100',
        rows: ['USD', 'EUR', 'BYN', 'RUB'].map((currency, index) => row(index + 1, currency)),
        nextId: 5,
        factors: {},
        sources: [],
        message: '',
        lastUpdatedAt: null,
        loading: false,
        requestToken: 0,
        initializationPromise: null,
        loadAllPromise: null,
        loadAllKey: null,
    };
}

export { row };
