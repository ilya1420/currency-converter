<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <title>Конвертер валют</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-[100dvh] overflow-hidden bg-[#09090B] font-sans text-[#F8FAFC] antialiased">
<main class="mx-auto flex h-[100dvh] max-w-md flex-col overflow-hidden" x-data="converter([])">
    <header class="z-20 flex min-h-10 h-auto shrink-0 items-end justify-between gap-3 border-b border-white/5 bg-[#09090B] px-5 pb-2 pt-[max(0.75rem,env(safe-area-inset-top))]">
        <button x-show="activeTab === 'converter'" x-cloak class="grid h-8 w-8 shrink-0 place-items-center rounded-full text-zinc-400 transition active:text-fuchsia-300" @click="historyOpen = true" aria-label="История конвертаций" title="История конвертаций">
            <svg class="h-5 w-5" viewBox="0 0 20 20" fill="none" aria-hidden="true"><circle cx="10" cy="10" r="7" stroke="currentColor" stroke-width="1.5"/><path d="M10 5.5V10l3 1.8" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg>
        </button>
        <p x-show="sourceLabel" x-cloak class="truncate text-[11px] font-medium text-zinc-400" x-text="`Источник: ${sourceLabel}`"></p>
        <p x-show="lastUpdatedLabel" x-cloak class="shrink-0 text-[11px] font-medium text-zinc-500" x-text="lastUpdatedLabel"></p>
    </header>

    <aside x-show="showFirstRunHint" x-cloak class="mx-4 mt-2 flex shrink-0 items-start gap-3 rounded-xl border border-fuchsia-500/15 bg-fuchsia-500/5 px-3 py-2.5" role="note" aria-label="Подсказка для начала">
        <p class="min-w-0 flex-1 text-xs leading-relaxed text-zinc-300">Нажмите на значок валюты, чтобы заменить её. «Добавить валюту» добавит ещё одну строку.</p>
        <button class="shrink-0 py-0.5 text-xs font-semibold text-fuchsia-300 active:text-pink-300" @click="dismissFirstRunHint" aria-label="Закрыть подсказку">Понятно</button>
    </aside>

    <section x-show="activeTab === 'converter' && favoritePairs.length" x-cloak class="shrink-0 border-b border-white/5 px-4 py-2" aria-label="Избранные валютные пары">
        <div class="flex gap-2 overflow-x-auto pb-1">
            <template x-for="pair in favoritePairs" :key="`${pair.from}:${pair.to}`">
                <div class="flex shrink-0 items-center overflow-hidden rounded-full bg-white/[0.06]">
                    <button class="px-3 py-2 text-xs font-semibold text-zinc-300 transition active:text-fuchsia-300 disabled:opacity-40" @click="applyFavoritePair(pair)" :disabled="!canApplyFavoritePair(pair)" :aria-label="`Открыть пару ${pair.from} к ${pair.to}`" x-text="`${pair.from} → ${pair.to}`"></button>
                    <button class="px-2 py-2 text-xs text-zinc-500 active:text-pink-300" @click="removeFavoritePair(pair)" :aria-label="`Удалить пару ${pair.from} к ${pair.to} из избранного`">×</button>
                </div>
            </template>
        </div>
    </section>

    <p x-show="activeTab === 'converter' && (message || catalogMessage)" x-cloak class="mx-5 mb-3 shrink-0 text-xs text-pink-300" aria-live="polite"
       x-text="[message, catalogMessage].filter(Boolean).join(' ')"></p>

    <section x-show="activeTab === 'converter'" class="flex min-h-0 w-full flex-1 flex-col overflow-hidden border-y border-white/5 bg-[#18181B]">
        <div class="relative min-h-0 flex-1 overflow-hidden">
            <div class="pointer-events-none absolute left-1/2 top-0 z-0 flex h-12 w-44 -translate-x-1/2 items-center justify-center gap-2 text-[11px] font-medium text-fuchsia-200 transition-[transform,opacity] duration-200"
                 :style="{ transform: `translate(-50%, ${pullDistance - 48}px)`, opacity: pullRefreshProgress }">
                <svg class="h-6 w-6" :class="loading ? 'animate-spin' : ''" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <path d="M19 8.5A7.5 7.5 0 0 0 5.7 6L4 8m0 0V4.5M4 8h3.5M5 15.5A7.5 7.5 0 0 0 18.3 18L20 16m0 0v3.5M20 16h-3.5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                    <circle cx="12" cy="12" r="2.4" fill="currentColor" opacity=".9"/>
                </svg>
                <span x-text="loading ? 'Обновляем курсы' : (pullDistance >= 56 ? 'Отпустите для обновления' : 'Потяните для обновления')"></span>
            </div>
            <div x-ref="currencyList" class="relative z-10 h-full overflow-y-auto overscroll-contain transition-transform duration-200"
                 :style="{ transform: `translateY(${pullDistance}px)` }"
                 @touchstart.passive="pullRefreshStart($event)" @touchmove="pullRefreshMove($event)" @touchend="pullRefreshEnd" @touchcancel="pullRefreshEnd">
                <template x-for="(row, index) in rows" :key="row.id">
                <div class="relative overflow-hidden border-b border-white/5" :data-currency-row="index">
                    <div
                        class="absolute inset-0 flex items-center justify-end bg-gradient-to-r from-[#A855F7] to-[#EC4899] pr-6 text-white transition-opacity"
                        :style="{ opacity: deleteBackgroundOpacity(row) }">
                        <svg class="h-6 w-6" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                            <path d="M4 6h12m-8 3v6m4-6v6M7 6l1-2h4l1 2m-7 0 1 11h6l1-11" stroke="currentColor"
                                  stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                        </svg>
                    </div>
                    <div
                        class="relative flex min-h-[4.5rem] touch-pan-y items-center gap-3 bg-[#18181B] px-5 transition-[transform,background-color] duration-200 ease-out"
                        @touchstart="swipeStart(index, $event)" @touchmove="swipeMove(index, $event)"
                        @touchend="swipeEnd(index, $event)" :style="{ transform: `translateX(${row.swipeOffset}px)` }"
                        :class="[row.loading ? 'opacity-60' : '', dragIndex === index ? 'bg-fuchsia-500/10' : '', row.currency === base ? 'bg-gradient-to-r from-fuchsia-500/10 to-pink-500/5' : '']">
                        <button class="grid h-12 w-5 shrink-0 touch-none place-items-center text-zinc-500 active:text-fuchsia-300"
                                @click.stop @pointerdown.stop="sortStart(index, $event)"
                                @pointermove.stop="sortMove($event)" @pointerup.stop="sortEnd($event)"
                                @pointercancel.stop="sortEnd($event)" aria-label="Переместить валюту">
                            <svg class="h-5 w-4" viewBox="0 0 16 20" fill="currentColor" aria-hidden="true">
                                <circle cx="4" cy="4" r="1.5"/>
                                <circle cx="12" cy="4" r="1.5"/>
                                <circle cx="4" cy="10" r="1.5"/>
                                <circle cx="12" cy="10" r="1.5"/>
                                <circle cx="4" cy="16" r="1.5"/>
                                <circle cx="12" cy="16" r="1.5"/>
                            </svg>
                        </button>
                        <button
                            class="grid h-12 w-12 shrink-0 place-items-center transition active:scale-95"
                            :class="currencyType(row.currency) === 'crypto' ? 'rounded-full' : 'currency-icon-fiat'"
                            :style="!meta[row.currency].icon && !meta[row.currency].flag ? { background: meta[row.currency].color } : {}" @click.stop="openPicker(row.id)"
                            :aria-label="`Изменить валюту строки ${index + 1}`">
                            <img x-show="meta[row.currency].icon || meta[row.currency].flag" x-cloak :src="meta[row.currency].icon || meta[row.currency].flag" class="h-12 w-12" :class="currencyType(row.currency) === 'crypto' ? 'object-contain' : 'currency-flag-image'" :alt="meta[row.currency].label">
                            <span x-show="!meta[row.currency].icon && !meta[row.currency].flag" class="max-w-9 truncate text-center font-black text-white" :class="badgeTextClass(row.currency)" x-text="badgeText(row.currency)"></span>
                        </button>
                        <div class="w-20 shrink-0"><p class="truncate text-left text-base font-semibold" x-text="meta[row.currency].label"></p><p class="truncate text-[10px] text-zinc-500" x-text="currencyName(row.currency)"></p><p x-show="row.dailyChange !== null && row.dailyChange !== undefined" class="truncate text-[10px] font-semibold tabular-nums" :class="row.dailyChange >= 0 ? 'text-emerald-400' : 'text-pink-300'" x-text="`${row.dailyChange >= 0 ? '+' : ''}${Number(row.dailyChange).toFixed(2)}%`" :aria-label="`Дневное изменение ${Number(row.dailyChange).toFixed(2)}%`"></p></div>
                        <button
                            class="min-w-0 flex-1 whitespace-nowrap text-right font-amount text-2xl font-medium tracking-tight tabular-nums outline-none transition active:scale-[0.98] active:text-fuchsia-200"
                            @click.stop="activateRow(row)" :disabled="Boolean(row.error)"
                            :class="row.error ? 'text-pink-300' : ''"
                            :aria-label="row.error ? `Курс ${meta[row.currency].label} недоступен: ${row.error}` : `Ввести сумму в ${meta[row.currency].label}`"
                            x-text="row.error || (row.currency === base ? activeAmountLabel : formatAmount(row.result, row.currency))"></button>
                        <button class="grid h-10 w-8 shrink-0 place-items-center text-zinc-600 transition active:scale-110 disabled:opacity-20" @click.stop="toggleFavoritePair(row.currency)" @touchstart.stop @touchmove.stop @touchend.stop :disabled="row.currency === base" :aria-label="isFavoritePair(row.currency) ? `Убрать пару ${base} к ${row.currency} из избранного` : `Добавить пару ${base} к ${row.currency} в избранное`" :title="isFavoritePair(row.currency) ? 'Убрать из избранного' : 'В избранное'" :class="isFavoritePair(row.currency) ? 'text-amber-300' : ''">
                            <svg class="h-5 w-5" viewBox="0 0 20 20" aria-hidden="true"><path d="m10 2.5 2.3 4.7 5.2.8-3.8 3.7.9 5.2-4.6-2.5-4.6 2.5.9-5.2-3.8-3.7 5.2-.8L10 2.5Z" :fill="isFavoritePair(row.currency) ? 'currentColor' : 'none'" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg>
                        </button>
                    </div>
                </div>
                </template>
                <button
                    class="flex w-full items-center justify-center gap-2 border-b border-white/5 py-3 text-sm font-semibold text-fuchsia-300 active:text-pink-300"
                    @click="openPicker('add')"><span class="text-xl leading-none">+</span> Добавить валюту
                </button>
                <button class="flex w-full items-center justify-center border-b border-white/5 py-3 text-xs font-semibold text-zinc-400 active:text-fuchsia-300" @click="presetsOpen = true">Наборы валют</button>
            </div>
            <button
                x-show="!keyboardVisible"
                x-cloak
                class="flex w-full shrink-0 items-center justify-center gap-2 border-t border-white/5 py-3 text-sm font-semibold text-fuchsia-300 active:text-pink-300"
                @click="showKeyboard"
                aria-controls="converter-calculator-keypad"
                aria-expanded="false"
            >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <rect x="3" y="4" width="18" height="16" rx="3" stroke="currentColor" stroke-width="1.8"/>
                    <path d="M7 8h2m3 0h2m3 0h.01M7 12h2m3 0h2m3 0h.01M8 16h8" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
                </svg>
                Показать калькулятор
            </button>
        </div>
    </section>

    <section x-show="activeTab === 'charts'" x-cloak class="min-h-0 flex-1 overflow-y-auto pt-2">
        <div class="px-5">
            <div class="flex items-center justify-between"><div><div class="mt-1 flex items-center gap-2"><span class="grid h-9 w-9 shrink-0 place-items-center" :class="currencyType(chartCurrency) === 'crypto' ? 'rounded-full' : 'currency-icon-fiat'" :style="!meta[chartCurrency].icon && !meta[chartCurrency].flag ? { background: meta[chartCurrency].color } : {}"><img x-show="meta[chartCurrency].icon || meta[chartCurrency].flag" x-cloak :src="activeTab === 'charts' ? (meta[chartCurrency].icon || meta[chartCurrency].flag) : null" class="h-full w-full" :class="currencyType(chartCurrency) === 'crypto' ? 'object-contain' : 'currency-flag-image'" :alt="meta[chartCurrency].label" loading="lazy" decoding="async"><span x-show="!meta[chartCurrency].icon && !meta[chartCurrency].flag" class="text-[9px] font-black text-white" x-text="badgeText(chartCurrency)"></span></span><h1 class="text-2xl font-semibold" x-text="currencyName(chartCurrency)"></h1></div><p class="mt-1 text-[10px] text-zinc-600" x-text="chartQuoteLabel"></p></div><div x-show="chart" class="text-right"><div class="flex items-baseline justify-end gap-1"><p class="font-amount text-xl font-semibold" x-text="chartAxisValue(chart?.ticker?.last || chart?.candles?.at(-1)?.close)"></p><span class="text-xs font-semibold text-zinc-500" x-text="chartQuoteCurrency"></span></div><p x-show="chartChange !== null" class="mt-0.5 text-xs font-semibold" :class="chartChange >= 0 ? 'text-emerald-400' : 'text-pink-300'" x-text="`${chartChange >= 0 ? '+' : ''}${chartChange?.toFixed(2)}% за период`"></p></div></div>
            <div class="mt-5 grid grid-cols-2 gap-1 rounded-2xl bg-[#18181B] p-1">
                <button class="rounded-xl px-3 py-2 text-xs font-semibold transition" :class="chartMarket === 'fiat' ? 'bg-[#27272A] text-white shadow-sm' : 'text-zinc-500'" @click="setChartMarket('fiat')">Обычные валюты</button>
                <button class="rounded-xl px-3 py-2 text-xs font-semibold transition" :class="chartMarket === 'crypto' ? 'bg-[#27272A] text-white shadow-sm' : 'text-zinc-500'" @click="setChartMarket('crypto')">Криптовалюты</button>
            </div>
            <label class="mt-3 flex items-center gap-2 rounded-2xl bg-[#18181B] px-4 py-3 text-zinc-500">
                <span class="text-base leading-none">⌕</span>
                <input x-model="chartSearch" class="min-w-0 flex-1 bg-transparent text-sm text-white outline-none" placeholder="Найти валюту или код" autocomplete="off">
                <button x-show="chartSearch" x-cloak @click="chartSearch = ''" class="text-xs text-zinc-500" aria-label="Очистить поиск">Очистить</button>
            </label>
            <div class="mt-3 flex gap-2 overflow-x-auto pb-1"><template x-for="currency in chartCurrencies" :key="currency"><button @click="selectChartCurrency(currency)" class="flex min-w-28 items-center gap-2 rounded-2xl px-2.5 py-2 text-left transition" :class="chartCurrency === currency ? 'bg-fuchsia-500 text-white' : 'bg-[#27272A] text-zinc-400'"><span class="grid h-8 w-8 shrink-0 place-items-center" :class="currencyType(currency) === 'crypto' ? 'rounded-full' : 'currency-icon-fiat'" :style="!meta[currency].icon && !meta[currency].flag ? { background: meta[currency].color } : {}"><img x-show="meta[currency].icon || meta[currency].flag" x-cloak :src="activeTab === 'charts' ? (meta[currency].icon || meta[currency].flag) : null" class="h-full w-full" :class="currencyType(currency) === 'crypto' ? 'object-contain' : 'currency-flag-image'" :alt="meta[currency].label" loading="lazy" decoding="async"><span x-show="!meta[currency].icon && !meta[currency].flag" class="max-w-7 truncate text-center text-[8px] font-black text-white" x-text="badgeText(currency)"></span></span><span class="min-w-0"><span class="block text-xs font-bold" x-text="currency"></span><span class="mt-0.5 block max-w-20 truncate text-[10px] opacity-70" x-text="currencyName(currency)"></span></span></button></template></div>
            <p x-show="chartSearch && !chartCurrencies.length" class="mt-3 text-center text-xs text-zinc-500">Ничего не найдено</p>
            <div class="mt-3 flex items-center justify-between gap-3"><div class="flex gap-3"><template x-for="interval in chartIntervals" :key="interval.value"><button @click="chartInterval = interval.value; loadChart()" class="text-xs font-semibold" :class="chartInterval === interval.value ? 'text-pink-300' : 'text-zinc-500'" x-text="interval.label"></button></template></div><span class="text-[10px] text-zinc-500" x-text="chartPeriodLabel"></span></div>
            <div class="relative mt-4 h-[55vh] min-h-72 pr-12">
                <svg x-show="chart && !chartLoading" class="h-full w-full" viewBox="0 0 100 100" preserveAspectRatio="none" aria-label="График курса"><path d="M0 4H100M0 50H100M0 96H100" stroke="rgba(255,255,255,.09)" stroke-width=".25" vector-effect="non-scaling-stroke"/><g x-show="chart?.source !== 'NBRB'" x-html="chartCandlesMarkup"></g><path x-show="chart?.source === 'NBRB'" :d="chartPath" fill="none" stroke="#EC4899" stroke-width="1.5" vector-effect="non-scaling-stroke" stroke-linecap="round" stroke-linejoin="round"/></svg>
                <div x-show="chartLoading || chartError" class="absolute inset-0 grid place-items-center pr-12 text-center text-sm">
                    <p x-show="chartLoading" class="text-zinc-500">Загрузка…</p>
                    <p x-show="chartError" class="text-pink-300" x-text="chartError"></p>
                </div>
                <div x-show="chartRange" class="pointer-events-none absolute inset-y-0 right-0 flex flex-col justify-between text-[10px] text-zinc-500"><template x-for="value in chartYLabels" :key="value"><span x-text="chartAxisValue(value)"></span></template></div>
            </div>
            <div x-show="chartRange" class="grid grid-cols-4 text-[10px] text-zinc-500"><template x-for="(label, index) in chartXLabels" :key="index"><span :class="index === 3 ? 'text-right' : ''" x-text="label"></span></template></div>
            <p x-show="chart" class="mt-3 text-right text-[10px] text-zinc-600" x-text="`Источник данных: ${chart?.source || '—'}`"></p>
            <div x-show="chart?.source === 'Kraken'" class="mt-3 grid grid-cols-3 gap-2 border-t border-white/5 pt-4 text-center"><div><p class="text-[10px] uppercase text-zinc-500">24ч high</p><p class="mt-1 text-sm font-semibold" x-text="chartValue(chart?.ticker?.high)"></p></div><div><p class="text-[10px] uppercase text-zinc-500">24ч low</p><p class="mt-1 text-sm font-semibold" x-text="chartValue(chart?.ticker?.low)"></p></div><div><p class="text-[10px] uppercase text-zinc-500">Сделки</p><p class="mt-1 text-sm font-semibold" x-text="chart?.ticker?.trades || '—'"></p></div></div>
            <div x-show="fiatStats" class="mt-4 grid grid-cols-3 gap-2 border-t border-white/5 pt-4 text-center"><div><p class="text-[10px] uppercase text-zinc-500">Изменение</p><p class="mt-1 text-sm font-semibold" :class="fiatStats?.change >= 0 ? 'text-emerald-400' : 'text-pink-300'" x-text="`${fiatStats?.change >= 0 ? '+' : ''}${fiatStats?.change?.toFixed(2)}%`"></p></div><div><p class="text-[10px] uppercase text-zinc-500">Публикаций</p><p class="mt-1 text-sm font-semibold" x-text="fiatStats?.observations"></p></div><div><p class="text-[10px] uppercase text-zinc-500">Курс на</p><p class="mt-1 text-sm font-semibold" x-text="fiatStats?.updated?.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' })"></p></div></div>
            <div x-show="marketStats" class="mt-5 border-t border-white/5 pt-4"><p class="text-[10px] font-bold uppercase tracking-[0.18em] text-zinc-500">Стакан · top 10</p><div class="mt-3 grid grid-cols-2 gap-3"><div class="rounded-2xl bg-[#27272A] p-3"><p class="text-[10px] uppercase text-zinc-500">Изменение 24ч</p><p class="mt-1 text-sm font-semibold" :class="marketStats?.change >= 0 ? 'text-emerald-400' : 'text-pink-300'" x-text="`${marketStats?.change >= 0 ? '+' : ''}${marketStats?.change?.toFixed(2)}%`"></p></div><div class="rounded-2xl bg-[#27272A] p-3"><p class="text-[10px] uppercase text-zinc-500">Spread</p><p class="mt-1 text-sm font-semibold" x-text="`${marketStats?.spread?.toFixed(3)}%`"></p></div><div class="rounded-2xl bg-[#27272A] p-3"><p class="text-[10px] uppercase text-zinc-500">Покупатели</p><p class="mt-1 text-sm font-semibold text-emerald-400" x-text="`${marketStats?.imbalance?.toFixed(0)}%`"></p></div><div class="rounded-2xl bg-[#27272A] p-3"><p class="text-[10px] uppercase text-zinc-500">Ликвидность</p><p class="mt-1 text-sm font-semibold" x-text="chartValue((marketStats?.bidVolume || 0) + (marketStats?.askVolume || 0))"></p></div></div></div>
        </div>
    </section>

    <section
        x-show="activeTab === 'converter'"
        x-cloak
        class="relative z-10 shrink-0"
        :class="keyboardVisible ? 'px-3 pb-2' : 'h-0'"
        aria-label="Калькулятор"
    >
        <div
            x-show="keyboardVisible"
            x-cloak
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="translate-y-4 opacity-0"
            x-transition:enter-end="translate-y-0 opacity-100"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="translate-y-0 opacity-100"
            x-transition:leave-end="translate-y-4 opacity-0"
            class="pb-2"
        >
            <button class="flex h-8 w-full items-center justify-center gap-1 text-xs font-medium text-zinc-500 transition active:text-fuchsia-300" @click="toggleKeyboard" aria-controls="converter-calculator-keypad" aria-expanded="true" aria-label="Скрыть калькулятор">
                <svg class="h-5 w-5" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                    <path d="m5 7.5 5 5 5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
                <span>Скрыть калькулятор</span>
            </button>
            <div id="converter-calculator-keypad" class="grid grid-cols-4 gap-2">
                <template x-for="key in keys" :key="key">
                    <button
                        class="grid h-12 place-items-center rounded-xl text-lg font-semibold transition active:scale-95"
                        :class="isOperator(key) ? 'bg-fuchsia-500/15 text-fuchsia-300' : 'bg-white/[0.06] text-zinc-100'"
                        @click="press(key)" :aria-label="key === '⌫' ? 'Удалить последний символ' : key">
                        <svg x-show="key === '⌫'" class="h-5 w-5" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                            <path d="m9 5-5 5 5 5m-5-5h12" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                  stroke-linejoin="round"/>
                        </svg>
                        <span x-show="key !== '⌫'" x-text="key === '*' ? '×' : key === '/' ? '÷' : key"></span></button>
                </template>
                <button class="h-12 rounded-xl bg-white/[0.06] text-lg font-semibold" @click="press('0')">0</button>
                <button class="h-12 rounded-xl bg-white/[0.06] text-lg font-semibold" @click="press('.')">.</button>
                <button
                    class="col-span-2 h-12 rounded-xl bg-gradient-to-r from-[#A855F7] to-[#EC4899] text-lg font-bold text-white shadow-lg shadow-fuchsia-950/30 active:scale-95"
                    @click="calculate">=
                </button>
            </div>
        </div>
    </section>

    <nav class="flex shrink-0 items-center justify-around border-t border-white/5 bg-[#111114] px-12 pb-[max(0.55rem,env(safe-area-inset-bottom))] pt-1.5" aria-label="Основная навигация">
        <button class="flex min-h-11 min-w-14 flex-col items-center justify-center gap-1 text-[10px] font-medium leading-none" @click="setTab('converter')" :class="activeTab === 'converter' ? 'text-fuchsia-300' : 'text-zinc-500'" aria-label="Конвертер">
            <svg class="h-5 w-5" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M4 6h10m0 0-3-3m3 3-3 3M16 14H6m0 0 3-3m-3 3 3 3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <span>Конвертер</span>
        </button>
        <button class="flex min-h-11 min-w-14 flex-col items-center justify-center gap-1 text-[10px] font-medium leading-none" @click="setTab('charts')" :class="activeTab === 'charts' ? 'text-fuchsia-300' : 'text-zinc-500'" aria-label="Графики">
            <svg class="h-5 w-5" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M3 16V4m0 12h14M6 13l3-3 2 2 5-6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <span>Графики</span>
        </button>
        <button class="flex min-h-11 min-w-14 flex-col items-center justify-center gap-1 text-[10px] font-medium leading-none text-zinc-500 active:text-fuchsia-300" @click="openProviderSettings" aria-label="Настройки провайдеров">
            <svg class="h-5 w-5" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M8.2 2.8h3.6l.5 2a6.5 6.5 0 0 1 1.2.7l1.9-.7 1.8 3.1-1.5 1.4a6.5 6.5 0 0 1 0 1.4l1.5 1.4-1.8 3.1-1.9-.7a6.5 6.5 0 0 1-1.2.7l-.5 2H8.2l-.5-2a6.5 6.5 0 0 1-1.2-.7l-1.9.7-1.8-3.1 1.5-1.4a6.5 6.5 0 0 1 0-1.4L2.8 7.9l1.8-3.1 1.9.7a6.5 6.5 0 0 1 1.2-.7l.5-2Z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/><circle cx="10" cy="10" r="2.3" stroke="currentColor" stroke-width="1.4"/></svg>
            <span>Источники</span>
        </button>
    </nav>

    <template x-if="pickerTarget !== null">
    <div class="fixed inset-0 z-20 flex items-end bg-black/70"
         @click.self="closePicker" @keydown.escape.window="closePicker">
        <section
            class="max-h-[78vh] w-full overflow-y-auto overscroll-contain rounded-t-3xl border-t border-white/10 bg-[#18181B] px-5 pb-[max(1.25rem,env(safe-area-inset-bottom))] pt-4 shadow-2xl"
            :style="{ transform: `translateY(${pickerSwipeOffset}px)` }"
            role="dialog" aria-modal="true" aria-label="Выбор валюты">
            <div class="-mt-2 mb-2 flex h-10 cursor-grab touch-none flex-col items-center justify-center active:cursor-grabbing"
                 @touchstart.stop="pickerSwipeStart($event)" @touchmove.prevent.stop="pickerSwipeMove($event)" @touchend.stop="pickerSwipeEnd" @touchcancel.stop="pickerSwipeEnd">
                <div class="h-1 w-10 rounded-full bg-zinc-600"></div>
            </div>
            <div class="mb-4"><h2 class="text-lg font-bold" x-text="pickerTitle"></h2></div>
            <div class="mb-4 grid grid-cols-2 gap-1 rounded-2xl bg-[#09090B] p-1">
                <button class="rounded-xl px-3 py-2 text-xs font-semibold transition" :class="pickerMarket === 'fiat' ? 'bg-[#27272A] text-white shadow-sm' : 'text-zinc-500'" @click="setPickerMarket('fiat')">Обычные валюты</button>
                <button class="rounded-xl px-3 py-2 text-xs font-semibold transition" :class="pickerMarket === 'crypto' ? 'bg-[#27272A] text-white shadow-sm' : 'text-zinc-500'" @click="setPickerMarket('crypto')">Криптовалюты</button>
            </div>
            <div x-show="pickerMarket === 'crypto'" class="mb-4 flex gap-2 overflow-x-auto pb-1">
                <template x-for="filter in cryptoGroupFilters" :key="filter.key"><button class="shrink-0 rounded-full px-3 py-1.5 text-[11px] font-semibold transition" :class="cryptoGroups[filter.key] ? 'bg-fuchsia-500/20 text-fuchsia-200' : 'bg-white/5 text-zinc-500'" @click="toggleCryptoGroup(filter.key)" x-text="filter.label"></button></template>
            </div>
            <label
                class="mb-4 flex items-center gap-2 rounded-2xl bg-[#09090B] px-4 py-3 text-zinc-400"><span>⌕</span><input
                    class="w-full bg-transparent text-sm text-white outline-none" x-model="pickerSearch"
                    placeholder="Найти валюту или код" autocomplete="off"></label>
            <template x-for="group in currencyGroups" :key="group.title">
                <div x-show="group.type === pickerMarket && filteredCurrencies(group.items).length" class="mb-5"><p class="mb-2 flex items-center justify-between text-[10px] font-bold uppercase tracking-[0.18em] text-zinc-500"><span x-text="group.title"></span><span class="tracking-normal text-zinc-600" x-text="filteredCurrencies(group.items).length"></span></p>
                    <div class="overflow-hidden rounded-2xl bg-[#27272A]">
                        <template x-for="currency in filteredCurrencies(group.items)" :key="currency">
                            <button
                                class="flex w-full items-center gap-3 border-b border-white/5 px-4 py-3.5 text-left last:border-0 disabled:opacity-35"
                                :class="pickerTarget === 'add' && isSelected(currency) ? 'bg-fuchsia-500/10' : ''"
                                @click="chooseCurrency(currency)" :disabled="!canChoose(currency)"><span
                                    class="grid h-11 w-11 shrink-0 place-items-center"
                                    :class="currencyType(currency) === 'crypto' ? 'rounded-full' : 'currency-icon-fiat'"
                                    :style="!meta[currency].icon && !meta[currency].flag ? { background: meta[currency].color } : {}"><img x-show="meta[currency].icon || meta[currency].flag" x-cloak loading="lazy" decoding="async"
                                                                                       :src="pickerTarget !== null ? (meta[currency].icon || meta[currency].flag) : null" class="h-11 w-11" :class="currencyType(currency) === 'crypto' ? 'object-contain' : 'currency-flag-image'" :alt="meta[currency].label" loading="lazy" decoding="async"><span
                                        x-show="!meta[currency].icon && !meta[currency].flag" class="max-w-9 truncate text-center font-black text-white" :class="badgeTextClass(currency)" x-text="badgeText(currency)"></span></span><span
                                    class="min-w-0 flex-1"><span class="block truncate font-semibold" x-text="meta[currency].label"></span><span class="block truncate text-[11px] text-zinc-500" x-text="currencyName(currency)"></span></span><span
                                    class="text-sm text-fuchsia-300" x-text="pickerTarget === 'add' && isSelected(currency) ? 'Убрать' : (isSelected(currency) ? '✓' : '')"></span>
                            </button>
                        </template>
                    </div>
                </div>
            </template>
            <div x-show="unavailableCurrencies.length && filteredCurrencies(unavailableCurrencies).length" class="mb-5">
                <p class="mb-2 text-[10px] font-bold uppercase tracking-[0.18em] text-amber-300">Недоступны у выбранного источника</p>
                <div class="overflow-hidden rounded-2xl bg-[#27272A]">
                    <template x-for="currency in filteredCurrencies(unavailableCurrencies)" :key="`unavailable-${currency}`">
                        <button class="flex w-full items-center gap-3 border-b border-white/5 px-4 py-3.5 text-left opacity-45 last:border-0" disabled>
                            <span class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-white/5 text-xs font-black text-zinc-400" x-text="badgeText(currency)"></span>
                            <span class="min-w-0 flex-1"><span class="block truncate font-semibold text-zinc-300" x-text="currencyInfo(currency).label || currency"></span><span class="block truncate text-[11px] text-zinc-500" x-text="currencyName(currency)"></span></span>
                            <span class="text-[10px] text-amber-300">Недоступна</span>
                        </button>
                    </template>
                </div>
            </div>
        </section>
    </div>
    </template>

    <div x-show="presetsOpen" x-cloak class="fixed inset-0 z-30 flex items-end bg-black/70" @click.self="presetsOpen = false" @keydown.escape.window="presetsOpen = false">
        <section class="max-h-[82vh] w-full overflow-y-auto overscroll-contain rounded-t-3xl border-t border-white/10 bg-[#18181B] px-5 pb-[max(1.5rem,env(safe-area-inset-bottom))] pt-5 shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="currency-presets-title">
            <div class="mb-4 flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 id="currency-presets-title" class="text-lg font-bold">Наборы валют</h2>
                    <p class="mt-1 text-xs leading-relaxed text-zinc-400">Добавьте валюты к текущим строкам. Сумма и базовая валюта сохранятся.</p>
                </div>
                <button class="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-white/5 text-zinc-400" @click="presetsOpen = false" aria-label="Закрыть наборы валют">×</button>
            </div>
            <div class="grid gap-3">
                <template x-for="preset in currencyPresets" :key="preset.id">
                    <article class="rounded-2xl bg-[#27272A] p-4">
                        <h3 class="text-sm font-semibold" x-text="preset.label"></h3>
                        <p class="mt-1 text-xs text-zinc-400" x-text="preset.description"></p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <template x-for="currency in preset.currencies" :key="currency">
                                <span class="rounded-full bg-black/20 px-2.5 py-1 text-xs" :class="preset.unavailable.includes(currency) ? 'text-zinc-500 line-through' : 'text-zinc-200'" x-text="currency"></span>
                            </template>
                        </div>
                        <p x-show="preset.unavailable.length" class="mt-2 text-xs text-zinc-400" x-text="`Недоступны у текущих источников: ${preset.unavailable.join(', ')}. Они не будут добавлены.`"></p>
                        <button class="mt-3 min-h-11 w-full rounded-xl bg-fuchsia-500 px-3 py-2.5 text-xs font-semibold text-white transition active:bg-fuchsia-400 disabled:opacity-40" @click="applyCurrencyPreset(preset.id)" :disabled="!preset.additions.length" x-text="preset.additions.length ? `Добавить валюты (${preset.additions.length})` : (preset.available.length ? 'Доступные валюты уже добавлены' : 'Нет доступных валют')"></button>
                    </article>
                </template>
            </div>
        </section>
    </div>

    <div x-show="historyOpen" x-cloak class="fixed inset-0 z-30 flex items-end bg-black/70" @click.self="historyOpen = false" @keydown.escape.window="historyOpen = false">
        <section class="max-h-[82vh] w-full overflow-y-auto overscroll-contain rounded-t-3xl border-t border-white/10 bg-[#18181B] px-5 pb-[max(1.5rem,env(safe-area-inset-bottom))] pt-5 shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="conversion-history-title">
            <div class="mx-auto mb-4 h-1 w-10 rounded-full bg-zinc-600"></div>
            <div class="mb-4 flex items-center justify-between gap-3">
                <div>
                    <h2 id="conversion-history-title" class="text-lg font-bold">История конвертаций</h2>
                    <p class="mt-1 text-[11px] text-zinc-500">Сохраняется только по вашему нажатию · последние 50 записей</p>
                </div>
                <button class="grid h-9 w-9 shrink-0 place-items-center rounded-full bg-white/5 text-zinc-400" @click="historyOpen = false" aria-label="Закрыть историю">×</button>
            </div>
            <button class="mb-4 w-full rounded-xl bg-fuchsia-500 px-4 py-3 text-sm font-semibold text-white transition active:bg-fuchsia-400 disabled:opacity-40" @click="saveCurrentConversion" :disabled="!canSaveCurrentConversion">Сохранить текущий расчёт</button>
            <div x-show="!conversionHistory.length" class="rounded-2xl bg-[#27272A] px-4 py-8 text-center">
                <p class="text-sm font-medium text-zinc-300">История пока пуста</p>
                <p class="mt-1 text-xs text-zinc-500">Сохраните текущий расчёт, чтобы быстро вернуться к нему позже.</p>
            </div>
            <div x-show="conversionHistory.length" class="mb-3 flex justify-end">
                <button x-show="!confirmClearHistory" class="text-xs font-semibold text-zinc-500 active:text-pink-300" @click="confirmClearHistory = true">Очистить историю</button>
                <div x-show="confirmClearHistory" class="flex items-center gap-3 text-xs" role="alert">
                    <span class="text-zinc-400">Удалить все записи?</span>
                    <button class="font-semibold text-zinc-300" @click="confirmClearHistory = false">Отмена</button>
                    <button class="font-semibold text-pink-300" @click="clearHistory">Удалить</button>
                </div>
            </div>
            <div class="grid gap-2">
                <template x-for="entry in conversionHistory" :key="entry.id">
                    <article class="rounded-2xl bg-[#27272A] p-3">
                        <div class="flex items-center justify-between gap-3">
                            <div class="min-w-0">
                                <p class="truncate text-sm font-semibold" x-text="`${entry.amount} ${entry.base}`"></p>
                                <p class="mt-0.5 text-[10px] text-zinc-500" x-text="formatHistoryDate(entry.savedAt)"></p>
                            </div>
                            <button class="grid h-9 w-9 shrink-0 place-items-center rounded-full text-zinc-500 active:text-pink-300" @click="removeHistoryEntry(entry.id)" :aria-label="`Удалить запись ${entry.amount} ${entry.base} из истории`">×</button>
                        </div>
                        <div class="mt-2 flex flex-wrap gap-1.5">
                            <template x-for="item in entry.rows" :key="`${entry.id}-${item.currency}`">
                                <span x-show="item.currency !== entry.base" class="rounded-full bg-black/20 px-2.5 py-1 text-[11px] text-zinc-300" x-text="`${formatHistoryResult(item)} ${item.currency}`"></span>
                            </template>
                        </div>
                        <button class="mt-3 w-full rounded-xl border border-white/10 px-3 py-2.5 text-xs font-semibold text-fuchsia-200 transition active:bg-white/5" @click="restoreHistoryEntry(entry)">Вернуть в конвертер</button>
                    </article>
                </template>
            </div>
        </section>
    </div>

    <div x-show="providerSettingsOpen" x-cloak class="fixed inset-0 z-30 flex items-end bg-black/70" @click.self="providerSettingsOpen = false" @keydown.escape.window="providerSettingsOpen = false">
        <section class="max-h-[82vh] w-full overflow-y-auto overscroll-contain rounded-t-3xl border-t border-white/10 bg-[#18181B] px-5 pb-[max(1.5rem,env(safe-area-inset-bottom))] pt-5 shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="provider-settings-title">
            <div class="mx-auto mb-4 h-1 w-10 rounded-full bg-zinc-600"></div>
            <div class="mb-1 flex items-center justify-between gap-3">
                <h2 id="provider-settings-title" class="text-lg font-bold">Источники курсов</h2>
                <button class="grid h-9 w-9 place-items-center rounded-full bg-white/5 text-zinc-400" @click="providerSettingsOpen = false" aria-label="Закрыть настройки">×</button>
            </div>
            <p class="mb-5 text-xs leading-relaxed text-zinc-500">Выберите встроенный источник для каждой capability. Для фиата и криптовалют выбор разделён там, где провайдеры отличаются. При ошибке выбранного источника автоматического переключения не будет.</p>
            <p x-show="providerSettingsLoading" class="py-5 text-center text-sm text-zinc-500">Загрузка настроек…</p>
            <p x-show="providerSettingsError" x-cloak class="mb-3 text-sm text-pink-300" role="alert" x-text="providerSettingsError"></p>
            <template x-for="capability in providerCapabilities" :key="capability.id">
                <div x-show="providerSettings?.capabilities?.[capability.id]?.providers?.length" class="mb-3 rounded-2xl bg-[#27272A] p-4">
                    <label class="mb-2 block text-xs font-semibold text-zinc-300" :for="`provider-${capability.id}`" x-text="capability.label"></label>
                    <div class="flex items-center gap-2">
                        <select class="min-w-0 flex-1 rounded-xl border border-white/10 bg-[#18181B] px-3 py-3 text-sm text-white outline-none focus:border-fuchsia-400 disabled:opacity-50" :id="`provider-${capability.id}`" :value="providerSettings?.capabilities?.[capability.id]?.selected || '__automatic__'" @change="changeProviderSelection(capability.id, $event.target.value)" :disabled="providerSettingsSaving">
                            <option value="__automatic__" x-text="`Автоматически · ${providerDefaultName(capability.id)} по умолчанию`"></option>
                            <template x-for="provider in providerSettings?.capabilities?.[capability.id]?.providers || []" :key="provider.id">
                                <option :value="provider.id" :disabled="!provider.configured" x-text="provider.configured ? provider.name : `${provider.name} · сначала настройте ключ`"></option>
                            </template>
                        </select>
                        <button class="shrink-0 rounded-xl px-3 py-3 text-xs font-semibold text-fuchsia-300 disabled:opacity-50" @click="resetProviderSelection(capability.id)" :disabled="providerSettingsSaving || !providerSettings?.capabilities?.[capability.id]?.selected">Автоматически</button>
                    </div>
                </div>
            </template>
            <div class="mt-5">
                <h3 class="mb-2 text-xs font-bold uppercase tracking-[0.16em] text-zinc-400">Встроенные провайдеры и их возможности</h3>
                <div class="grid gap-2">
                    <template x-for="provider in providerSettings?.providers || []" :key="`provider-info-${provider.id}`">
                        <article class="rounded-2xl bg-[#27272A] p-4">
                            <div class="mb-3 flex items-center justify-between gap-3">
                                <h4 class="text-sm font-semibold text-white" x-text="provider.name"></h4>
                                <span x-show="provider.requires_api_key" class="text-[10px]" :class="provider.configured ? 'text-emerald-300' : 'text-amber-300'" x-text="provider.configured ? 'Ключ настроен' : 'Нужен ключ'"></span>
                            </div>
                            <div class="flex flex-wrap gap-1.5">
                                <template x-for="label in providerCapabilityLabels(provider)" :key="`${provider.id}-${label}`">
                                    <span class="rounded-full bg-white/5 px-2.5 py-1 text-[10px] text-zinc-300" x-text="label"></span>
                                </template>
                            </div>
                        </article>
                    </template>
                </div>
            </div>
            <div class="mt-4 rounded-2xl bg-[#27272A] p-4">
                <h3 class="mb-1 text-sm font-semibold">CoinGecko Demo API</h3>
                <p class="mb-3 text-[11px] leading-relaxed text-zinc-500">Ключ проверяется через API CoinGecko и хранится зашифрованным в локальной базе приложения. Он не отображается повторно и не отправляется в URL.</p>
                <p x-show="providerSettings?.provider_settings?.coingecko?.configured" class="mb-3 text-xs text-emerald-300">Ключ настроен. При необходимости введите новый, чтобы заменить его.</p>
                <label for="coingecko-api-key" class="mb-2 block text-xs font-semibold text-zinc-300">Demo API key</label>
                <input id="coingecko-api-key" x-model="coingeckoApiKey" type="password" autocomplete="off" spellcheck="false" class="mb-3 w-full rounded-xl border border-white/10 bg-[#18181B] px-3 py-3 text-sm text-white outline-none focus:border-fuchsia-400" placeholder="Вставьте ключ CoinGecko">
                <button class="w-full rounded-xl bg-fuchsia-500 px-4 py-3 text-sm font-semibold text-white disabled:opacity-50" @click="saveCoinGeckoKey" :disabled="providerSettingsSaving || !coingeckoApiKey.trim()" x-text="providerSettingsSaving ? 'Проверка…' : 'Проверить и сохранить'"></button>
            </div>
        </section>
    </div>
</main>
</body>
</html>
