import assert from 'node:assert/strict';
import test from 'node:test';
import { currencyApi } from '../../resources/js/converter/api.js';

for (const [name, body, status] of [
    ['HTML 500', '<html>Internal error</html>', 500],
    ['empty 500', '', 500],
    ['empty successful response', '', 200],
    ['malformed JSON', '{', 502],
    ['null response', 'null', 200],
]) {
    test(`transport preserves status for ${name}`, async () => {
        const original = globalThis.fetch;
        globalThis.fetch = async (url, options) => {
            assert.equal(options.headers.Accept, 'application/json');
            return new Response(body, { status });
        };
        try {
            await assert.rejects(currencyApi.catalog(), (error) => error.status === status && error.code === 'invalid_response');
        } finally { globalThis.fetch = original; }
    });
}

for (const status of [422, 429]) {
    test(`transport preserves JSON error details for ${status}`, async () => {
        const original = globalThis.fetch;
        globalThis.fetch = async (url, options) => {
            assert.equal(options.headers.Accept, 'application/json');
            assert.equal(options.headers['Content-Type'], 'application/json');
            return new Response(JSON.stringify({ message: 'Ошибка источника', code: 'provider_rate_limited', provider: 'kraken' }), {
                status, headers: { 'Retry-After': '15' },
            });
        };
        try {
            await assert.rejects(currencyApi.selectProvider('crypto_rates', 'kraken'), (error) => {
                assert.equal(error.status, status);
                assert.equal(error.message, 'Ошибка источника');
                assert.equal(error.provider, 'kraken');
                assert.equal(error.retryAfter, 15);
                return error.code === 'provider_rate_limited';
            });
        } finally { globalThis.fetch = original; }
    });
}
