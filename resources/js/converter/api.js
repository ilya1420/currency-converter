const JSON_HEADERS = { 'Content-Type': 'application/json' };

function batches(values) {
    const unique = [...new Set(values)];
    return Array.from({ length: Math.ceil(unique.length / 20) }, (_, index) => unique.slice(index * 20, index * 20 + 20));
}

async function request(url, options = {}) {
    const response = await fetch(url, { ...options, headers: { Accept: 'application/json', ...options.headers } });
    let payload;
    try {
        payload = await response.json();
    } catch {
        payload = null;
    }
    const validPayload = payload !== null && typeof payload === 'object' && !Array.isArray(payload);

    if (!response.ok || !validPayload) {
        const error = new Error(validPayload && typeof payload.message === 'string'
            ? payload.message : 'Не удалось получить ответ сервера. Попробуйте ещё раз.');
        error.code = validPayload ? payload.code : 'invalid_response';
        error.provider = validPayload ? payload.provider : undefined;
        error.retryAfter = validPayload ? payload.retryAfter : undefined;
        const retryAfter = response.headers?.get('Retry-After');
        if (error.retryAfter === undefined && /^\d+$/.test(retryAfter || '')) error.retryAfter = Number(retryAfter);
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
        const statuses = {};
        for (const batch of batches(currencies)) {
            const query = new URLSearchParams();
            batch.forEach((currency) => query.append('currencies[]', currency));
            const result = await request(`/daily-changes?${query.toString()}`);
            Object.assign(changes, result.changes);
            Object.assign(statuses, result.statuses);
        }
        return { changes, statuses };
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
