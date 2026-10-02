export function chartSlice() {
    return {
        chartMarket: 'crypto',
        chartSearch: '',
        chartCurrency: 'BTC',
        chartInterval: 60,
        chart: null,
        chartLoading: false,
        chartError: '',
        chartRequestToken: 0,
    };
}
