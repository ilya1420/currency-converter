const JSON_HEADERS = { 'Content-Type': 'application/json' };

async function request(url, options = {}) {
    const response = await fetch(url, options);
    const payload = await response.json();

    if (!response.ok) throw new Error(payload.message || 'Request failed');

    return payload;
}

export const currencyApi = {
    catalog() {
        return request('/currencies');
    },
    conversions({ from, fromType, targets, refresh }) {
        return request('/conversions', {
            method: 'POST', headers: JSON_HEADERS,
            body: JSON.stringify({ from, fromType, targets, refresh }),
        });
    },
    conversion({ from, fromType, to, toType, refresh }) {
        return request('/conversion', {
            method: 'POST', headers: JSON_HEADERS,
            body: JSON.stringify({ amount: '1', from, fromType, to, toType, refresh }),
        });
    },
    market(currency, interval, type) {
        return request(`/market/${currency}?interval=${interval}&type=${type}`);
    },
};
