export function chartSlice() {
    return {
        chartCurrency: 'BTC',
        chartInterval: 60,
        chart: null,
        chartLoading: false,
        chartError: '',
    };
}
