<!doctype html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Конвертер валют</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="h-[100dvh] overflow-hidden bg-[#09090B] font-sans text-[#F8FAFC] antialiased">
<main class="mx-auto flex h-[100dvh] max-w-md flex-col overflow-hidden" x-data="converter()" x-init="init()">
    <header class="shrink-0 flex items-center justify-between px-5 pb-3 pt-[max(1rem,env(safe-area-inset-top))]">
        <div><p class="text-xs font-bold uppercase tracking-[0.22em] text-fuchsia-300">Конвертер</p>
            <p x-show="lastUpdatedLabel" x-cloak class="mt-1 text-xs text-zinc-500" x-text="lastUpdatedLabel"></p></div>
        <button
            class="grid h-11 w-11 place-items-center rounded-full bg-[#18181B] text-xl text-fuchsia-300 active:scale-95 disabled:opacity-40"
            @click="refreshAll" :disabled="loading" aria-label="Обновить курсы" x-text="loading ? '◌' : '↻'"></button>
    </header>

    <p x-show="message" x-cloak class="mx-5 mb-3 shrink-0 text-xs text-pink-300" aria-live="polite"
       x-text="message"></p>

    <section class="flex min-h-0 w-full flex-1 flex-col overflow-hidden border-y border-white/5 bg-[#18181B]">
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
                <div class="relative overflow-hidden border-b border-white/5">
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
                        @dragover.prevent @drop="dropRow(index)" @click="makeBase(index)"
                        @touchstart="swipeStart(index, $event)" @touchmove="swipeMove(index, $event)"
                        @touchend="swipeEnd(index, $event)" :style="{ transform: `translateX(${row.swipeOffset}px)` }"
                        :class="[row.loading ? 'opacity-60' : '', dragIndex === index ? 'bg-fuchsia-500/10' : '']"
                        role="button" tabindex="0" @keydown.enter="makeBase(index)">
                        <button class="grid h-12 w-5 shrink-0 place-items-center text-zinc-500 active:text-fuchsia-300"
                                draggable="true" @click.stop @dragstart.stop="dragStart(index)"
                                @dragend="dragIndex = null" @touchstart.stop="touchStart(index, $event)"
                                @touchend.stop="touchEnd(index, $event)" aria-label="Переместить валюту">
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

    <section
        class="mt-4 shrink-0 border-t border-white/5 bg-[#111114] px-4 pb-[max(1rem,env(safe-area-inset-bottom))] pt-3"
        aria-label="Калькулятор">
        <div x-show="keyboardVisible" x-cloak>
            <button class="mb-2 flex w-full items-center justify-center py-1 text-zinc-500 active:text-fuchsia-300"
                    @click="toggleKeyboard" aria-label="Скрыть клавиатуру">
                <svg class="h-5 w-5" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                    <path d="m5 7.5 5 5 5-5" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                          stroke-linejoin="round"/>
                </svg>
            </button>
            <div class="grid grid-cols-4 gap-2">
                <template x-for="key in keys" :key="key">
                    <button
                        class="grid h-12 place-items-center rounded-2xl text-lg font-semibold transition active:scale-95"
                        :class="isOperator(key) ? 'bg-fuchsia-500/15 text-fuchsia-300' : 'bg-[#18181B] text-zinc-100'"
                        @click="press(key)" :aria-label="key === '⌫' ? 'Удалить последний символ' : key">
                        <svg x-show="key === '⌫'" class="h-5 w-5" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                            <path d="m9 5-5 5 5 5m-5-5h12" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                                  stroke-linejoin="round"/>
                        </svg>
                        <span x-show="key !== '⌫'" x-text="key === '*' ? '×' : key === '/' ? '÷' : key"></span></button>
                </template>
                <button class="h-12 rounded-2xl bg-[#18181B] text-lg font-semibold" @click="press('0')">0</button>
                <button class="h-12 rounded-2xl bg-[#18181B] text-lg font-semibold" @click="press('.')">.</button>
                <button
                    class="col-span-2 h-12 rounded-2xl bg-gradient-to-r from-[#A855F7] to-[#EC4899] text-lg font-bold text-white shadow-lg shadow-fuchsia-950/40 active:scale-95"
                    @click="calculate">=
                </button>
            </div>
        </div>
        <button x-show="!keyboardVisible" x-cloak
                class="flex h-10 w-full items-center justify-center text-zinc-400 active:text-fuchsia-300"
                @click="toggleKeyboard" aria-label="Показать клавиатуру">
            <svg class="h-5 w-5" viewBox="0 0 20 20" fill="none" aria-hidden="true">
                <path d="m5 12.5 5-5 5 5" stroke="currentColor" stroke-width="2" stroke-linecap="round"
                      stroke-linejoin="round"/>
            </svg>
        </button>
    </section>

    <div x-show="pickerTarget !== null" x-cloak class="fixed inset-0 z-20 flex items-end bg-black/70"
         @click.self="closePicker" @keydown.escape.window="closePicker">
        <section
            class="max-h-[78vh] w-full overflow-y-auto rounded-t-3xl border-t border-white/10 bg-[#18181B] px-5 pb-[max(1.25rem,env(safe-area-inset-bottom))] pt-4 shadow-2xl"
            role="dialog" aria-modal="true" aria-label="Выбор валюты">
            <div class="mx-auto mb-4 h-1 w-10 rounded-full bg-zinc-600"></div>
            <div class="mb-4 flex items-center justify-between"><h2 class="text-lg font-bold" x-text="pickerTitle"></h2>
                <button class="grid h-9 w-9 place-items-center rounded-full bg-[#27272A] text-zinc-300"
                        @click="closePicker" aria-label="Закрыть">×
                </button>
            </div>
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
                                @click="chooseCurrency(currency)" :disabled="!canChoose(currency)"><span
                                    class="grid h-11 w-11 shrink-0 place-items-center rounded-2xl border border-white/15 text-base font-bold shadow-md shadow-black/20"
                                    :style="{ background: meta[currency].color }"><img x-show="currency === 'BYN'"
                                                                                       x-cloak
                                                                                       src="/images/byn-symbol.svg"
                                                                                       class="h-7 w-7" alt=""><span
                                        x-show="currency !== 'BYN'" x-text="meta[currency].mark"></span></span><span
                                    class="flex-1 font-semibold" x-text="meta[currency].label"></span><span
                                    class="text-sm text-fuchsia-300" x-text="isSelected(currency) ? '✓' : ''"></span>
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
