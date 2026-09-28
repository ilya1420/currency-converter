export const currencyMeta = {
    BYN: { label: 'BYN', mark: 'Br', flag: '/images/flags/by.svg', color: 'linear-gradient(135deg, #D83A4E, #157A4B)' },
    USD: { label: 'USD', mark: '$', flag: '/images/flags/us.svg', color: 'linear-gradient(135deg, #2563EB, #1E40AF)' },
    EUR: { label: 'EUR', mark: '€', flag: '/images/flags/eu.svg', color: 'linear-gradient(135deg, #1D4ED8, #EAB308)' },
    PLN: { label: 'PLN', mark: 'zł', flag: '/images/flags/pl.svg', color: 'linear-gradient(135deg, #E5E7EB, #DC2626)' },
    GBP: { label: 'GBP', mark: '£', flag: '/images/flags/gb.svg', color: 'linear-gradient(135deg, #1D4ED8, #DC2626)' },
    CNY: { label: 'CNY', mark: '¥', flag: '/images/flags/cn.svg', color: 'linear-gradient(135deg, #DC2626, #991B1B)' },
    RUB: { label: 'RUB', mark: '₽', flag: '/images/flags/ru.svg', color: 'linear-gradient(135deg, #2563EB, #B91C1C)' },
    UAH: { label: 'UAH', mark: '₴', flag: '/images/flags/ua.svg', color: 'linear-gradient(135deg, #2563EB, #EAB308)' },
    BTC: { label: 'BTC', icon: '/images/currencies/btc.svg', color: '#F7931A' },
    ETH: { label: 'ETH', icon: '/images/currencies/eth.svg', color: '#627EEA' },
    USDT: { label: 'USDT', icon: '/images/currencies/usdt.svg', color: '#26A17B' },
    SOL: { label: 'SOL', icon: '/images/currencies/sol.svg', color: '#66F9A1' },
    XRP: { label: 'XRP', icon: '/images/currencies/xrp.svg', color: '#23292F' },
};

const cryptoFallbackColors = [
    ['#7C3AED', '#C026D3'],
    ['#2563EB', '#0891B2'],
    ['#059669', '#0D9488'],
    ['#EA580C', '#E11D48'],
    ['#4F46E5', '#7C3AED'],
    ['#BE123C', '#DB2777'],
];

const fiatFlagColors = {
    AED: ['#047857', '#16A34A'], AMD: ['#1D4ED8', '#DC2626'], AUD: ['#1E3A8A', '#2563EB'],
    BGN: ['#15803D', '#DC2626'], BRL: ['#047857', '#CA8A04'], CAD: ['#DC2626', '#EF4444'],
    CHF: ['#B91C1C', '#EF4444'], CZK: ['#1D4ED8', '#DC2626'], DKK: ['#B91C1C', '#EF4444'],
    GEL: ['#DC2626', '#F87171'], HKD: ['#B91C1C', '#EF4444'], HUF: ['#15803D', '#DC2626'],
    INR: ['#D97706', '#15803D'], IRR: ['#15803D', '#DC2626'], ISK: ['#1D4ED8', '#DC2626'],
    JPY: ['#BE123C', '#F43F5E'], KGS: ['#BE123C', '#F59E0B'], KWD: ['#047857', '#DC2626'],
    MDL: ['#1D4ED8', '#F59E0B'], NOK: ['#B91C1C', '#1D4ED8'], NZD: ['#1E3A8A', '#2563EB'],
    RON: ['#1D4ED8', '#EAB308'], SAR: ['#047857', '#16A34A'], SEK: ['#1D4ED8', '#EAB308'],
    SGD: ['#DC2626', '#F87171'], TRY: ['#BE123C', '#EF4444'], ZAR: ['#047857', '#CA8A04'],
};

const genericFiatFlagColors = [
    ['#1D4ED8', '#2563EB'], ['#047857', '#0D9488'], ['#7C3AED', '#9333EA'], ['#BE123C', '#E11D48'],
];

export function cryptoFallbackColor(code) {
    const hash = [...code].reduce((value, character) => ((value * 31) + character.charCodeAt(0)) >>> 0, 0);
    const [from, to] = cryptoFallbackColors[hash % cryptoFallbackColors.length];

    return `linear-gradient(135deg, ${from}, ${to})`;
}

export function fiatFallbackColor(code) {
    const colors = fiatFlagColors[code] || genericFiatFlagColors[[...code].reduce((value, character) => ((value * 31) + character.charCodeAt(0)) >>> 0, 0) % genericFiatFlagColors.length];

    return `linear-gradient(145deg, ${colors[0]}, ${colors[1]})`;
}

export function isCrypto(catalog, currency) {
    return catalog.find(({ code }) => code === currency)?.type === 'crypto';
}
