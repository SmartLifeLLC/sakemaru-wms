@php
    $isLarge = ($size ?? null) === 'large';
@endphp

<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    <div
        x-data="{
            state: $wire.entangle('{{ $getStatePath() }}').live,
            displayValue: '',
            previousValidValue: '',

            init() {
                this.displayValue = this.state || '';
                this.previousValidValue = this.state || '';

                this.$watch('state', value => {
                    const normalized = value || '';
                    this.displayValue = normalized;
                    this.previousValidValue = normalized;
                });
            },

            syncFromPicker(event) {
                this.setState(event.target.value || null);
            },

            openPicker() {
                const picker = this.$refs.nativeDatePicker;
                if (!picker) return;

                picker.value = this.state || '';

                if (typeof picker.showPicker === 'function') {
                    picker.showPicker();
                    return;
                }

                picker.click();
            },

            cleanInput() {
                if (!this.displayValue) return;

                let value = this.displayValue;
                value = value.replace(/[０-９]/g, char => String.fromCharCode(char.charCodeAt(0) - 0xFEE0));
                value = value.replace(/[^0-9\-\/]/g, '');
                this.displayValue = value;
            },

            formatDate() {
                this.cleanInput();

                const input = (this.displayValue || '').trim();
                if (!input) {
                    this.setState(null);
                    return;
                }

                const fullDateMatch = input.match(/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})$/);
                if (fullDateMatch) {
                    this.applyDate(
                        parseInt(fullDateMatch[1], 10),
                        parseInt(fullDateMatch[2], 10),
                        parseInt(fullDateMatch[3], 10),
                    );
                    return;
                }

                const digits = input.replace(/\D/g, '');
                if (digits.length === 0) return;

                const now = new Date();
                let year = now.getFullYear();
                let month = now.getMonth() + 1;
                let day = now.getDate();

                if (digits.length === 1 || digits.length === 2) {
                    day = parseInt(digits, 10);
                } else if (digits.length === 3) {
                    month = parseInt(digits.substring(0, 1), 10);
                    day = parseInt(digits.substring(1, 3), 10);
                } else if (digits.length === 4) {
                    month = parseInt(digits.substring(0, 2), 10);
                    day = parseInt(digits.substring(2, 4), 10);
                } else if (digits.length === 6) {
                    year = 2000 + parseInt(digits.substring(0, 2), 10);
                    month = parseInt(digits.substring(2, 4), 10);
                    day = parseInt(digits.substring(4, 6), 10);
                } else if (digits.length === 8) {
                    year = parseInt(digits.substring(0, 4), 10);
                    month = parseInt(digits.substring(4, 6), 10);
                    day = parseInt(digits.substring(6, 8), 10);
                } else {
                    this.restorePreviousValue();
                    return;
                }

                this.applyDate(year, month, day);
            },

            applyDate(year, month, day) {
                const parsed = new Date(year, month - 1, day);

                if (
                    parsed.getFullYear() !== year ||
                    parsed.getMonth() !== month - 1 ||
                    parsed.getDate() !== day
                ) {
                    this.restorePreviousValue();
                    return;
                }

                const formatted = [
                    parsed.getFullYear(),
                    String(parsed.getMonth() + 1).padStart(2, '0'),
                    String(parsed.getDate()).padStart(2, '0'),
                ].join('-');

                this.setState(formatted);
            },

            setState(value) {
                this.state = value;
                this.displayValue = value || '';
                this.previousValidValue = value || '';
            },

            restorePreviousValue() {
                this.displayValue = this.previousValidValue || '';
            },
        }"
        x-init="init()"
        @class([
            'relative',
            'smart-date-input-lg' => $isLarge,
        ])
    >
        <x-filament::input.wrapper>
            <x-filament::input
                type="text"
                inputmode="numeric"
                x-model="displayValue"
                @focus="$event.target.select()"
                @input="cleanInput"
                @blur="formatDate"
                @keyup.enter.prevent="formatDate"
                placeholder="YYYY-MM-DD または 数字"
                @class([
                    'text-lg font-semibold' => $isLarge,
                ])
            />

            <x-slot name="suffix">
                <button
                    type="button"
                    @click="openPicker()"
                    class="flex items-center text-gray-400 transition hover:text-primary-600 focus:outline-none dark:text-gray-500 dark:hover:text-primary-400"
                    tabindex="-1"
                    aria-label="カレンダーを開く"
                >
                    <x-filament::icon
                        icon="heroicon-m-calendar"
                        @class([
                            'h-5 w-5' => ! $isLarge,
                            'h-6 w-6' => $isLarge,
                        ])
                    />
                </button>
            </x-slot>
        </x-filament::input.wrapper>

        <input
            type="date"
            x-ref="nativeDatePicker"
            :value="state || ''"
            @change="syncFromPicker"
            class="pointer-events-none absolute bottom-0 right-0 h-px w-px opacity-0"
            tabindex="-1"
            aria-hidden="true"
        >
    </div>
</x-dynamic-component>
