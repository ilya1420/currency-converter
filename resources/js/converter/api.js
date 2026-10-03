const JSON_HEADERS = { 'Content-Type': 'application/json' };

function batches(values) {
    const unique = [...new Set(values)];
    return Array.from({ length: Math.ceil(unique.length / 20) }, (_, index) => unique.slice(index * 20, index * 20 + 20));
}

async function request(url, options = {}) {
    const response = await fetch(url, options);
    const payload = await response.json();

    if (!response.ok) {
        const error = new Error(payload.message || 'Request failed');
        error.code = payload.code;
        error.provider = payload.provider;
        error.retryAfter = payload.retryAfter;
        error.status = response.status;
        throw error;
    }

    return payload;
}

export const currencyApi = {
    catalog() {
        return request('/currencies');
    },
    async conversions({ from, fromType, targets, refresh }) {
        const conversions = {};
        for (const batch of batches(targets)) {
            const result = await request('/conversions', {
                method: 'POST', headers: JSON_HEADERS,
                body: JSON.stringify({ from, fromType, targets: batch, refresh }),
            });
            Object.assign(conversions, result.conversions);
        }
        return { conversions };
    },
    async dailyChanges(currencies) {
        const changes = {};
        for (const batch of batches(currencies)) {
            const query = new URLSearchParams();
            batch.forEach((currency) => query.append('currencies[]', currency));
            Object.assign(changes, (await request(`/daily-changes?${query.toString()}`)).changes);
        }
        return { changes };
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
    providerSettings() {
        return request('/provider-settings');
    },
    saveCoinGeckoKey(apiKey) {
        return request('/provider-settings/coingecko', {
            method: 'PUT', headers: JSON_HEADERS,
            body: JSON.stringify({ api_key: apiKey }),
        });
    },
    selectProvider(capability, providerId) {
        return request(`/provider-settings/${capability}`, {
            method: 'PATCH', headers: JSON_HEADERS,
            body: JSON.stringify({ provider_id: providerId }),
        });
    },
    resetProvider(capability) {
        return request(`/provider-settings/${capability}`, { method: 'DELETE' });
    },
};
