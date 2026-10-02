import assert from 'node:assert/strict';
import test from 'node:test';

import { currencyApi } from '../../resources/js/converter/api.js';
import { chartMethods } from '../../resources/js/converter/charts.js';

function chartState() {
    const state = {
        chartCurrency: 'BTC', chartInterval: 60, chart: { source: 'old-chart' },
        chartLoading: false, chartError: '', chartRequestToken: 0,
        currencyType: () => 'crypto',
    };
    Object.defineProperties(state, Object.getOwnPropertyDescriptors(chartMethods));

    return state;
}

test('latest chart selection wins when requests resolve out of order', async () => {
    const originalMarket = currencyApi.market;
    const pending = new Map();
    currencyApi.market = (currency) => new Promise((resolve, reject) => pending.set(currency, { resolve, reject }));

    try {
        const state = chartState();
        const olderRequest = state.loadChart();
        state.chartCurrency = 'ETH';
        const latestRequest = state.loadChart();

        pending.get('BTC').resolve({ currency: 'BTC' });
        await olderRequest;

        assert.equal(state.chart, null);
        assert.equal(state.chartLoading, true);

        pending.get('ETH').resolve({ currency: 'ETH' });
        await latestRequest;

        assert.deepEqual(state.chart, { currency: 'ETH' });
        assert.equal(state.chartLoading, false);
        assert.equal(state.chartError, '');
    } finally {
        currencyApi.market = originalMarket;
    }
});

test('failed chart reload hides the previous selection and reports an error', async () => {
    const originalMarket = currencyApi.market;
    currencyApi.market = async () => { throw new Error('offline'); };

    try {
        const state = chartState();

        await state.loadChart();

        assert.equal(state.chart, null);
        assert.equal(state.chartLoading, false);
        assert.equal(state.chartError, 'Не удалось загрузить данные рынка.');
    } finally {
        currencyApi.market = originalMarket;
    }
});

test('an obsolete chart failure does not replace the current request state', async () => {
    const originalMarket = currencyApi.market;
    const pending = new Map();
    currencyApi.market = (currency) => new Promise((resolve, reject) => pending.set(currency, { resolve, reject }));

    try {
        const state = chartState();
        const olderRequest = state.loadChart();
        state.chartCurrency = 'ETH';
        const latestRequest = state.loadChart();

        pending.get('BTC').reject(new Error('obsolete failure'));
        await olderRequest;

        assert.equal(state.chartError, '');
        assert.equal(state.chartLoading, true);

        pending.get('ETH').resolve({ currency: 'ETH' });
        await latestRequest;

        assert.deepEqual(state.chart, { currency: 'ETH' });
        assert.equal(state.chartLoading, false);
    } finally {
        currencyApi.market = originalMarket;
    }
});
