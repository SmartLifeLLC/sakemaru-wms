<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    <div x-data="{
        state: $wire.entangle('{{ $getStatePath() }}').live,
        open: false,
        year: null,
        month: null,
        days: [],
        weekdays: ['日', '月', '火', '水', '木', '金', '土'],
        months: ['1月', '2月', '3月', '4月', '5月', '6月', '7月', '8月', '9月', '10月', '11月', '12月'],

        init() {
            const d = this.state ? new Date(this.state) : new Date();
            this.year = d.getFullYear();
            this.month = d.getMonth();
            this.buildDays();
        },

        get displayDate() {
            if (!this.state) return '';
            const d = new Date(this.state);
            const y = d.getFullYear();
            const m = String(d.getMonth() + 1).padStart(2, '0');
            const day = String(d.getDate()).padStart(2, '0');
            const wd = this.weekdays[d.getDay()];
            return `${y}-${m}-${day}（${wd}）`;
        },

        buildDays() {
            const first = new Date(this.year, this.month, 1);
            const last = new Date(this.year, this.month + 1, 0);
            const startDay = first.getDay();
            const totalDays = last.getDate();
            const rows = [];
            let day = 1;

            // fill leading blanks
            const firstRow = [];
            for (let i = 0; i < startDay; i++) firstRow.push(null);
            while (firstRow.length < 7 && day <= totalDays) firstRow.push(day++);
            rows.push(firstRow);

            while (day <= totalDays) {
                const row = [];
                for (let i = 0; i < 7 && day <= totalDays; i++) row.push(day++);
                while (row.length < 7) row.push(null);
                rows.push(row);
            }
            this.days = rows;
        },

        prevMonth() {
            if (this.month === 0) { this.year--; this.month = 11; }
            else this.month--;
            this.buildDays();
        },

        nextMonth() {
            if (this.month === 11) { this.year++; this.month = 0; }
            else this.month++;
            this.buildDays();
        },

        selectDay(day) {
            if (!day) return;
            const m = String(this.month + 1).padStart(2, '0');
            const d = String(day).padStart(2, '0');
            this.state = `${this.year}-${m}-${d}`;
            this.open = false;
        },

        isSelected(day) {
            if (!day || !this.state) return false;
            const m = String(this.month + 1).padStart(2, '0');
            const d = String(day).padStart(2, '0');
            return this.state === `${this.year}-${m}-${d}`;
        },

        isToday(day) {
            if (!day) return false;
            const t = new Date();
            return t.getFullYear() === this.year && t.getMonth() === this.month && t.getDate() === day;
        },

        goToday() {
            const t = new Date();
            this.year = t.getFullYear();
            this.month = t.getMonth();
            this.buildDays();
            this.selectDay(t.getDate());
        },
    }"
    x-init="init()"
    class="relative"
    >
        {{-- 選択表示ボタン --}}
        <button
            type="button"
            @click="open = !open"
            class="w-full flex items-center justify-between gap-2 rounded-lg border border-slate-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-sm text-slate-800 dark:text-gray-200 hover:border-blue-400 dark:hover:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 transition-colors"
        >
            <div class="flex items-center gap-2">
                <i class="fa fa-calendar text-slate-400 dark:text-gray-500 text-xs"></i>
                <span x-text="displayDate || '日付を選択...'" :class="!displayDate && 'text-slate-400 dark:text-gray-500'"></span>
            </div>
            <i class="fa fa-chevron-down text-xs text-slate-400 dark:text-gray-500 transition-transform" :class="open && 'rotate-180'"></i>
        </button>

        {{-- カレンダードロップダウン --}}
        <div
            x-show="open"
            x-cloak
            @click.outside="open = false"
            @keydown.escape.window="open = false"
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0 -translate-y-1"
            x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="transition ease-in duration-100"
            x-transition:leave-start="opacity-100 translate-y-0"
            x-transition:leave-end="opacity-0 -translate-y-1"
            class="absolute z-50 mt-1 w-72 rounded-lg border border-slate-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-lg overflow-hidden"
        >
            {{-- ヘッダ: 年月ナビゲーション --}}
            <div class="flex items-center justify-between px-3 py-2 border-b border-slate-200 dark:border-gray-700 bg-slate-50 dark:bg-gray-800">
                <button type="button" @click="prevMonth()" class="p-1 rounded hover:bg-slate-200 dark:hover:bg-gray-700 text-slate-600 dark:text-gray-400 transition-colors">
                    <i class="fa fa-chevron-left text-xs"></i>
                </button>
                <span class="text-sm font-bold text-slate-700 dark:text-gray-200" x-text="`${year}年 ${months[month]}`"></span>
                <button type="button" @click="nextMonth()" class="p-1 rounded hover:bg-slate-200 dark:hover:bg-gray-700 text-slate-600 dark:text-gray-400 transition-colors">
                    <i class="fa fa-chevron-right text-xs"></i>
                </button>
            </div>

            {{-- 曜日ヘッダ --}}
            <div class="grid grid-cols-7 border-b border-slate-200 dark:border-gray-700">
                <template x-for="wd in weekdays" :key="wd">
                    <div class="py-1.5 text-center text-xs font-medium"
                         :class="wd === '日' ? 'text-red-500 dark:text-red-400' : (wd === '土' ? 'text-blue-500 dark:text-blue-400' : 'text-slate-500 dark:text-gray-400')"
                         x-text="wd"></div>
                </template>
            </div>

            {{-- 日付グリッド --}}
            <div class="p-1.5">
                <template x-for="(row, ri) in days" :key="ri">
                    <div class="grid grid-cols-7">
                        <template x-for="(day, di) in row" :key="ri + '-' + di">
                            <button
                                type="button"
                                @click="selectDay(day)"
                                :disabled="!day"
                                class="h-8 w-full rounded-md text-sm transition-colors disabled:cursor-default"
                                :class="{
                                    'bg-blue-600 text-white font-bold hover:bg-blue-700': isSelected(day),
                                    'ring-1 ring-blue-400 dark:ring-blue-500 font-bold': isToday(day) && !isSelected(day),
                                    'text-red-500 dark:text-red-400 hover:bg-red-50 dark:hover:bg-red-900/20': !isSelected(day) && day && di === 0,
                                    'text-blue-500 dark:text-blue-400 hover:bg-blue-50 dark:hover:bg-blue-900/20': !isSelected(day) && day && di === 6,
                                    'text-slate-700 dark:text-gray-300 hover:bg-slate-100 dark:hover:bg-gray-800': !isSelected(day) && day && di > 0 && di < 6,
                                }"
                                x-text="day"
                            ></button>
                        </template>
                    </div>
                </template>
            </div>

            {{-- フッタ --}}
            <div class="flex items-center justify-between px-3 py-1.5 border-t border-slate-200 dark:border-gray-700 bg-slate-50 dark:bg-gray-800">
                <button type="button" @click="goToday()" class="text-xs text-blue-600 dark:text-blue-400 hover:underline font-medium">
                    今日
                </button>
                <span class="text-xs text-slate-400 dark:text-gray-500" x-text="state || '-'"></span>
            </div>
        </div>
    </div>
</x-dynamic-component>
