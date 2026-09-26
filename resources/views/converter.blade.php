<!doctype html>
@php
    $currencyCatalog = array_map(
        static fn (\App\Currency\Enums\Currency $currency) => ['code' => $currency->value, 'type' => $currency->type()->value],
        \App\Currency\Enums\Currency::cases(),
    );
@endphp
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <title>Конвертер валют</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-[100dvh] overflow-hidden bg-[#09090B] font-sans text-[#F8FAFC] antialiased">
<main class="mx-auto flex h-[100dvh] max-w-md flex-col overflow-hidden" x-data="converter(@js($currencyCatalog))" x-init="init()">
    <header class="shrink-0 flex items-center justify-between px-5 pb-3 pt-[max(1rem,env(safe-area-inset-top))]">
        <div><p class="text-xs font-bold uppercase tracking-[0.22em] text-fuchsia-300">Конвертер</p>
            <p x-show="lastUpdatedLabel" x-cloak class="mt-1 text-xs text-zinc-500" x-text="lastUpdatedLabel"></p></div>
        <button
            class="grid h-11 w-11 place-items-center rounded-full bg-[#18181B] text-xl text-fuchsia-300 active:scale-95 disabled:opacity-40"
            @click="refreshAll" :disabled="loading" aria-label="Обновить курсы" x-text="loading ? '◌' : '↻'"></button>
    </header>

    <p x-show="activeTab === 'converter' && message" x-cloak class="mx-5 mb-3 shrink-0 text-xs text-pink-300" aria-live="polite"
       x-text="message"></p>

    <section x-show="activeTab === 'converter'" class="flex min-h-0 w-full flex-1 flex-col overflow-hidden border-y border-white/5 bg-[#18181B]">
        <div
            class="flex min-h-20 items-center gap-3 border-b border-white/5 bg-gradient-to-r from-fuchsia-500/10 to-pink-500/10 px-5">
            <span
                class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl border border-white/15 text-xl font-bold shadow-lg shadow-black/20 ring-1 ring-white/5"
                :style="{ background: meta[base].color }"><img x-show="base === 'BYN'" x-cloak
                                                               src="/images/byn-symbol.svg" class="h-8 w-8" alt=""><span
                    x-show="base !== 'BYN'" x-text="meta[base].mark"></span></span>
            <button class="flex w-20 shrink-0 items-center gap-1 text-left text-base font-semibold outline-none"
                    @click="openPicker('base')" aria-label="Изменить базовую валюту"><span
                    x-text="meta[base].label"></span>
                <svg class="h-3.5 w-3.5 text-zinc-500" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                    <path d="m5 7.5 5 5 5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                          stroke-linejoin="round"/>
                </svg>
            </button>
            <span
                class="min-w-0 flex-1 whitespace-nowrap text-right font-amount text-xl font-semibold tracking-tight tabular-nums"
                x-text="displayAmount"></span>
        </div>

        <div class="min-h-0 flex-1 overflow-y-auto overscroll-contain">
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
                        class="relative flex min-h-20 cursor-pointer touch-pan-y items-center gap-3 bg-[#18181B] px-5 transition-transform duration-200 ease-out active:bg-white/5"
                        @click="makeBase(index)"
                        @touchstart="swipeStart(index, $event)" @touchmove="swipeMove(index, $event)"
                        @touchend="swipeEnd(index, $event)" :style="{ transform: `translateX(${row.swipeOffset}px)` }"
                        :class="[row.loading ? 'opacity-60' : '', dragIndex === index ? 'bg-fuchsia-500/10' : '']"
                        role="button" tabindex="0" @keydown.enter="makeBase(index)">
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
                        <span
                            class="grid h-12 w-12 shrink-0 place-items-center rounded-2xl border border-white/15 text-xl font-bold shadow-lg shadow-black/20 ring-1 ring-white/5"
                            :style="{ background: meta[row.currency].color }"><img x-show="row.currency === 'BYN'"
                                                                                   x-cloak src="/images/byn-symbol.svg"
                                                                                   class="h-8 w-8" alt=""><span
                                x-show="row.currency !== 'BYN'" x-text="meta[row.currency].mark"></span></span>
                        <button
                            class="flex w-16 shrink-0 items-center gap-1 text-left text-base font-semibold outline-none"
                            @click.stop="openPicker(row.id)" :aria-label="`Изменить валюту строки ${index + 1}`"><span
                                x-text="meta[row.currency].label"></span>
                            <svg class="h-3.5 w-3.5 text-zinc-500" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                                <path d="m5 7.5 5 5 5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                      stroke-linejoin="round"/>
                            </svg>
                        </button>
                        <span
                            class="min-w-0 flex-1 whitespace-nowrap text-right font-amount text-xl font-medium tracking-tight tabular-nums"
                            x-text="row.error || formatAmount(row.result, row.currency)"></span>
                    </div>
                </div>
            </template>
            <button
                class="flex w-full items-center justify-center gap-2 border-b border-white/5 py-3 text-sm font-semibold text-fuchsia-300 active:text-pink-300"
                @click="openPicker('add')"><span class="text-xl leading-none">+</span> Добавить валюту
            </button>
        </div>
    </section>

    <section x-show="activeTab === 'charts'" x-cloak class="min-h-0 flex-1 overflow-y-auto pt-2">
        <div class="px-5">
            <div class="flex items-center justify-between"><div><p class="text-xs font-bold uppercase tracking-[0.2em] text-fuchsia-300" x-text="chart?.source || 'Рынок'"></p><h1 class="mt-1 text-2xl font-semibold" x-text="chartCurrency + (chart?.source === 'NBRB' ? ' / BYN' : ' / USD')"></h1></div><div x-show="chart" class="text-right"><p class="font-amount text-xl font-semibold" x-text="chartAxisValue(chart?.ticker?.last || chart?.candles?.at(-1)?.close)"></p><p x-show="chartChange !== null" class="mt-0.5 text-xs font-semibold" :class="chartChange >= 0 ? 'text-emerald-400' : 'text-pink-300'" x-text="`${chartChange >= 0 ? '+' : ''}${chartChange?.toFixed(2)}% за период`"></p></div></div>
            <div class="mt-5 flex gap-2 overflow-x-auto pb-1"><template x-for="currency in chartCurrencies" :key="currency"><button @click="selectChartCurrency(currency)" class="rounded-full px-3 py-1.5 text-xs font-bold" :class="chartCurrency === currency ? 'bg-fuchsia-500 text-white' : 'bg-[#27272A] text-zinc-400'" x-text="currency"></button></template></div>
            <div class="mt-3 flex items-center justify-between gap-3"><div class="flex gap-3"><template x-for="interval in chartIntervals" :key="interval.value"><button @click="chartInterval = interval.value; loadChart()" class="text-xs font-semibold" :class="chartInterval === interval.value ? 'text-pink-300' : 'text-zinc-500'" x-text="interval.label"></button></template></div><span class="text-[10px] text-zinc-500" x-text="chartPeriodLabel"></span></div>
            <div class="relative mt-4 h-[55vh] min-h-72 pr-12"><p x-show="chartLoading" class="pt-28 text-center text-sm text-zinc-500">Загрузка…</p><p x-show="chartError" class="pt-28 text-center text-sm text-pink-300" x-text="chartError"></p><svg x-show="chart && !chartLoading" class="h-full w-full" viewBox="0 0 100 100" preserveAspectRatio="none" aria-label="График курса"><path d="M0 4H100M0 50H100M0 96H100" stroke="rgba(255,255,255,.09)" stroke-width=".25" vector-effect="non-scaling-stroke"/><g x-show="chart?.source !== 'NBRB'" x-html="chartCandlesMarkup"></g><path x-show="chart?.source === 'NBRB'" :d="chartPath" fill="none" stroke="#EC4899" stroke-width="1.5" vector-effect="non-scaling-stroke" stroke-linecap="round" stroke-linejoin="round"/></svg><div x-show="chartRange" class="pointer-events-none absolute inset-y-0 right-0 flex flex-col justify-between text-[10px] text-zinc-500"><template x-for="value in chartYLabels" :key="value"><span x-text="chartAxisValue(value)"></span></template></div></div>
            <div x-show="chartRange" class="grid grid-cols-4 text-[10px] text-zinc-500"><template x-for="(label, index) in chartXLabels" :key="index"><span :class="index === 3 ? 'text-right' : ''" x-text="label"></span></template></div>
            <div x-show="chart?.source === 'Kraken'" class="mt-3 grid grid-cols-3 gap-2 border-t border-white/5 pt-4 text-center"><div><p class="text-[10px] uppercase text-zinc-500">High · UTC</p><p class="mt-1 text-sm font-semibold" x-text="chartValue(chart?.ticker?.high)"></p></div><div><p class="text-[10px] uppercase text-zinc-500">Low · UTC</p><p class="mt-1 text-sm font-semibold" x-text="chartValue(chart?.ticker?.low)"></p></div><div><p class="text-[10px] uppercase text-zinc-500">Сделки · UTC</p><p class="mt-1 text-sm font-semibold" x-text="chart?.ticker?.trades || '—'"></p></div></div>
            <div x-show="fiatStats" class="mt-4 grid grid-cols-3 gap-2 border-t border-white/5 pt-4 text-center"><div><p class="text-[10px] uppercase text-zinc-500">Изменение</p><p class="mt-1 text-sm font-semibold" :class="fiatStats?.change >= 0 ? 'text-emerald-400' : 'text-pink-300'" x-text="`${fiatStats?.change >= 0 ? '+' : ''}${fiatStats?.change?.toFixed(2)}%`"></p></div><div><p class="text-[10px] uppercase text-zinc-500">Публикаций</p><p class="mt-1 text-sm font-semibold" x-text="fiatStats?.observations"></p></div><div><p class="text-[10px] uppercase text-zinc-500">Курс на</p><p class="mt-1 text-sm font-semibold" x-text="fiatStats?.updated?.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' })"></p></div></div>
            <div x-show="marketStats" class="mt-5 border-t border-white/5 pt-4"><p class="text-[10px] font-bold uppercase tracking-[0.18em] text-zinc-500">Стакан · top 10</p><div class="mt-3 grid grid-cols-2 gap-3"><div class="rounded-2xl bg-[#27272A] p-3"><p class="text-[10px] uppercase text-zinc-500">Изменение 24ч</p><p class="mt-1 text-sm font-semibold" :class="marketStats?.change >= 0 ? 'text-emerald-400' : 'text-pink-300'" x-text="marketStats?.change === null ? '—' : `${marketStats.change >= 0 ? '+' : ''}${marketStats.change.toFixed(2)}%`"></p></div><div class="rounded-2xl bg-[#27272A] p-3"><p class="text-[10px] uppercase text-zinc-500">Spread</p><p class="mt-1 text-sm font-semibold" x-text="`${marketStats?.spread?.toFixed(3)}%`"></p></div><div class="rounded-2xl bg-[#27272A] p-3"><p class="text-[10px] uppercase text-zinc-500">Дисбаланс</p><p class="mt-1 text-sm font-semibold text-emerald-400" x-text="`${marketStats?.imbalance?.toFixed(0)}% bid`"></p></div><div class="rounded-2xl bg-[#27272A] p-3"><p class="text-[10px] uppercase text-zinc-500">Глубина · USD</p><p class="mt-1 text-sm font-semibold" x-text="formatUsd(marketStats?.liquidity)"></p></div></div></div>
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
            <button class="flex h-7 w-full items-center justify-center text-zinc-500 transition active:text-fuchsia-300" @click="toggleKeyboard" aria-label="Скрыть клавиатуру">
                <svg class="h-5 w-5" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                    <path d="m5 7.5 5 5 5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            </button>
            <div class="grid grid-cols-4 gap-2">
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
        <button
                x-show="!keyboardVisible"
                x-cloak
                x-transition:enter="transition ease-out duration-200"
                x-transition:enter-start="translate-y-2 opacity-0"
                x-transition:enter-end="translate-y-0 opacity-100"
                x-transition:leave="transition ease-in duration-150"
                x-transition:leave-start="translate-y-0 opacity-100"
                x-transition:leave-end="translate-y-2 opacity-0"
                class="absolute bottom-0 left-1/2 grid h-10 w-10 -translate-x-1/2 translate-y-1/2 place-items-center rounded-full border border-white/10 bg-[#18181B] text-zinc-400 shadow-lg shadow-black/40 transition active:scale-[0.95] active:text-fuchsia-200"
                @click="toggleKeyboard" aria-label="Показать клавиатуру">
            <svg class="h-4 w-4 text-fuchsia-300" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <rect x="4" y="3" width="16" height="18" rx="3" stroke="currentColor" stroke-width="1.8"/>
                <path d="M8 8h8M8 12h2m4 0h2m-8 4h2m4 0h2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/>
            </svg>
        </button>
    </section>

    <nav class="flex shrink-0 items-center justify-around border-t border-white/5 bg-[#111114] px-20 pb-[max(0.55rem,env(safe-area-inset-bottom))] pt-2" aria-label="Основная навигация">
        <button class="grid h-8 w-8 place-items-center" @click="activeTab === 'converter' ? toggleKeyboard() : setTab('converter')" :class="activeTab === 'converter' ? 'text-fuchsia-300' : 'text-zinc-500'" aria-label="Конвертер">
            <svg class="h-5 w-5" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M4 6h10m0 0-3-3m3 3-3 3M16 14H6m0 0 3-3m-3 3 3 3" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </button>
        <button class="grid h-8 w-8 place-items-center" @click="setTab('charts')" :class="activeTab === 'charts' ? 'text-fuchsia-300' : 'text-zinc-500'" aria-label="Графики">
            <svg class="h-5 w-5" viewBox="0 0 20 20" fill="none" aria-hidden="true"><path d="M3 16V4m0 12h14M6 13l3-3 2 2 5-6" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
        </button>
    </nav>

    <div x-show="pickerTarget !== null" x-cloak class="fixed inset-0 z-20 flex items-end bg-black/70"
         @click.self="closePicker" @keydown.escape.window="closePicker">
        <section @touchstart.stop="pickerSwipeStart($event)" @touchmove.stop="pickerSwipeMove($event)" @touchend.stop="pickerSwipeEnd"
            class="max-h-[78vh] w-full overflow-y-auto rounded-t-3xl border-t border-white/10 bg-[#18181B] px-5 pb-[max(1.25rem,env(safe-area-inset-bottom))] pt-4 shadow-2xl"
            :style="{ transform: `translateY(${pickerSwipeOffset}px)` }"
            role="dialog" aria-modal="true" aria-label="Выбор валюты">
            <div class="mx-auto mb-4 h-1 w-10 rounded-full bg-zinc-600"></div>
            <div class="mb-4"><h2 class="text-lg font-bold" x-text="pickerTitle"></h2></div>
            <label
                class="mb-4 flex items-center gap-2 rounded-2xl bg-[#09090B] px-4 py-3 text-zinc-400"><span>⌕</span><input
                    class="w-full bg-transparent text-sm text-white outline-none" x-model="pickerSearch"
                    placeholder="Найти валюту или код" autocomplete="off"></label>
            <template x-for="group in currencyGroups" :key="group.title">
                <div class="mb-5"><p class="mb-2 text-[10px] font-bold uppercase tracking-[0.18em] text-zinc-500"
                                     x-text="group.title"></p>
                    <div class="overflow-hidden rounded-2xl bg-[#27272A]">
                        <template x-for="currency in filteredCurrencies(group.items)" :key="currency">
                            <button
                                class="flex w-full items-center gap-3 border-b border-white/5 px-4 py-3.5 text-left last:border-0 disabled:opacity-35"
                                :class="pickerTarget === 'add' && isSelected(currency) ? 'bg-fuchsia-500/10' : ''"
                                @click="chooseCurrency(currency)" :disabled="!canChoose(currency)"><span
                                    class="grid h-11 w-11 shrink-0 place-items-center rounded-2xl border border-white/15 text-base font-bold shadow-md shadow-black/20"
                                    :style="{ background: meta[currency].color }"><img x-show="currency === 'BYN'"
                                                                                       x-cloak
                                                                                       src="/images/byn-symbol.svg"
                                                                                       class="h-7 w-7" alt=""><span
                                        x-show="currency !== 'BYN'" x-text="meta[currency].mark"></span></span><span
                                    class="flex-1 font-semibold" x-text="meta[currency].label"></span><span
                                    class="text-sm text-fuchsia-300" x-text="pickerTarget === 'add' && isSelected(currency) ? 'Убрать' : (isSelected(currency) ? '✓' : '')"></span>
                            </button>
                        </template>
                    </div>
                </div>
            </template>
        </section>
    </div>
</main>
</body>
</html>
