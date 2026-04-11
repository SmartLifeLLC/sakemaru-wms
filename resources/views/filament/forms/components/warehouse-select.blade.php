<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    <div x-data="{
        state: $wire.entangle('{{ $getStatePath() }}').live,
        open: false,
        search: '',
        warehouses: @js($warehouses),
        get filtered() {
            if (!this.search.trim()) return this.warehouses;
            const q = this.normalize(this.search);
            return this.warehouses.filter(w => {
                const label = this.normalize(w.label);
                return label.includes(q);
            });
        },
        get selectedLabel() {
            const w = this.warehouses.find(w => w.id == this.state);
            return w ? w.label : '';
        },
        normalize(str) {
            return str
                .normalize('NFKC')
                .toLowerCase()
                .replace(/[\u3000]/g, ' ');
        },
        select(id) {
            this.state = id;
            this.open = false;
            this.search = '';
        },
    }"
    x-init="$watch('open', v => { if (v) $nextTick(() => $refs.searchInput.focus()) })"
    class="relative"
    >
        {{-- 選択表示ボタン --}}
        <button
            type="button"
            @click="open = !open"
            class="w-full flex items-center justify-between gap-2 rounded-lg border border-slate-300 dark:border-gray-600 bg-white dark:bg-gray-900 px-3 py-2 text-sm text-slate-800 dark:text-gray-200 hover:border-blue-400 dark:hover:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500 transition-colors"
        >
            <span x-text="selectedLabel || '倉庫を選択...'" :class="!selectedLabel && 'text-slate-400 dark:text-gray-500'"></span>
            <i class="fa fa-chevron-down text-xs text-slate-400 dark:text-gray-500 transition-transform" :class="open && 'rotate-180'"></i>
        </button>

        {{-- ドロップダウン --}}
        <div
            x-show="open"
            x-cloak
            @click.outside="open = false; search = ''"
            @keydown.escape.window="open = false; search = ''"
            x-transition:enter="transition ease-out duration-150"
            x-transition:enter-start="opacity-0 -translate-y-1"
            x-transition:enter-end="opacity-100 translate-y-0"
            x-transition:leave="transition ease-in duration-100"
            x-transition:leave-start="opacity-100 translate-y-0"
            x-transition:leave-end="opacity-0 -translate-y-1"
            class="absolute z-50 mt-1 w-full rounded-lg border border-slate-200 dark:border-gray-700 bg-white dark:bg-gray-900 shadow-lg overflow-hidden"
        >
            {{-- 検索入力 --}}
            <div class="p-2 border-b border-slate-200 dark:border-gray-700">
                <div class="relative">
                    <i class="fa fa-search absolute left-2.5 top-1/2 -translate-y-1/2 text-xs text-slate-400 dark:text-gray-500"></i>
                    <input
                        x-ref="searchInput"
                        x-model.debounce.150ms="search"
                        type="text"
                        placeholder="コード・名前で検索（全角半角対応）"
                        class="w-full pl-7 pr-3 py-1.5 text-sm rounded-md border border-slate-200 dark:border-gray-600 bg-slate-50 dark:bg-gray-800 text-slate-800 dark:text-gray-200 placeholder-slate-400 dark:placeholder-gray-500 focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500"
                    >
                </div>
            </div>

            {{-- リスト --}}
            <ul class="max-h-60 overflow-y-auto divide-y divide-slate-100 dark:divide-gray-800">
                <template x-for="w in filtered" :key="w.id">
                    <li
                        @click="select(w.id)"
                        class="flex items-center justify-between px-3 py-2 cursor-pointer hover:bg-blue-50 dark:hover:bg-blue-900/30 transition-colors"
                        :class="state == w.id && 'bg-blue-50 dark:bg-blue-900/20'"
                    >
                        <div class="flex items-center gap-2">
                            <span class="text-xs font-mono text-slate-500 dark:text-gray-400 min-w-[3rem]" x-text="w.code"></span>
                            <span class="text-sm text-slate-800 dark:text-gray-200" x-text="w.name"></span>
                        </div>
                        <i x-show="state == w.id" class="fa fa-check text-xs text-blue-600 dark:text-blue-400"></i>
                    </li>
                </template>
                <li x-show="filtered.length === 0" class="px-3 py-6 text-center text-sm text-slate-400 dark:text-gray-500">
                    <i class="fa fa-search mb-1 block text-lg"></i>
                    該当する倉庫がありません
                </li>
            </ul>

            {{-- フッタ件数 --}}
            <div class="px-3 py-1.5 text-xs text-slate-400 dark:text-gray-500 border-t border-slate-200 dark:border-gray-700 bg-slate-50 dark:bg-gray-800">
                <span x-text="filtered.length"></span> / <span x-text="warehouses.length"></span> 件
            </div>
        </div>
    </div>
</x-dynamic-component>
