<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    <div wire:key="checkbox-grid-{{ $getStatePath() }}-{{ md5(json_encode($options)) }}"
         x-data="{
        state: $wire.entangle('{{ $getStatePath() }}').live,
        options: @js($options),
        search: '',

        init() {
            if (!Array.isArray(this.state)) this.state = [];
        },

        get filtered() {
            if (!this.search.trim()) return this.options;
            const q = this.normalize(this.search);
            return this.options.filter(o => this.normalize(o.label).includes(q));
        },

        normalize(str) {
            return str.normalize('NFKC').toLowerCase().replace(/[\u3000]/g, ' ');
        },

        isChecked(id) {
            return this.state.includes(id);
        },

        toggle(id) {
            if (this.isChecked(id)) {
                this.state = this.state.filter(v => v !== id);
            } else {
                this.state = [...this.state, id];
            }
        },

        get allSelected() {
            return this.filtered.length > 0 && this.filtered.every(o => this.isChecked(o.id));
        },

        toggleAll() {
            const ids = this.filtered.map(o => o.id);
            if (this.allSelected) {
                this.state = this.state.filter(v => !ids.includes(v));
            } else {
                const current = new Set(this.state);
                ids.forEach(id => current.add(id));
                this.state = [...current];
            }
        },

        get selectedCount() {
            return this.state.length;
        },
    }"
    x-init="init()"
    class="space-y-2"
    >
        {{-- ヘッダ: 検索 + 全選択 --}}
        <div class="flex items-center gap-2">
            <div class="relative flex-1">
                <i class="fa fa-search absolute left-2.5 top-1/2 -translate-y-1/2 text-xs text-slate-400 dark:text-gray-500"></i>
                <input
                    x-model.debounce.150ms="search"
                    type="text"
                    placeholder="{{ $searchPlaceholder ?? '検索...' }}"
                    class="w-full pl-7 pr-3 py-1.5 text-xs rounded-md border border-slate-200 dark:border-gray-600 bg-slate-50 dark:bg-gray-800 text-slate-800 dark:text-gray-200 placeholder-slate-400 dark:placeholder-gray-500 focus:outline-none focus:ring-1 focus:ring-blue-500 focus:border-blue-500"
                >
            </div>
            <label class="flex items-center gap-1.5 cursor-pointer text-xs text-blue-600 dark:text-blue-400 hover:text-blue-700 dark:hover:text-blue-300 whitespace-nowrap select-none">
                <input
                    type="checkbox"
                    :checked="allSelected"
                    @click="toggleAll()"
                    class="w-3.5 h-3.5 rounded text-blue-600 border-slate-300 dark:border-gray-500 focus:ring-blue-500 focus:ring-1 cursor-pointer"
                >
                全選択
            </label>
            <span class="text-xs text-slate-400 dark:text-gray-500 whitespace-nowrap" x-text="selectedCount + '件選択'"></span>
        </div>

        {{-- チェックボックスグリッド --}}
        <div class="max-h-64 overflow-y-auto rounded-lg border border-slate-200 dark:border-gray-700 bg-white dark:bg-gray-900 p-2">
            <div class="grid grid-cols-2 gap-1.5">
                <template x-for="o in filtered" :key="o.id">
                    <label
                        class="flex items-center gap-2 px-2.5 py-2 rounded-md border cursor-pointer transition-colors text-xs select-none"
                        :class="isChecked(o.id)
                            ? 'border-blue-400 dark:border-blue-500 bg-blue-50 dark:bg-blue-900/30'
                            : 'border-slate-200 dark:border-gray-700 hover:bg-slate-50 dark:hover:bg-gray-800'"
                        @click.prevent="toggle(o.id)"
                    >
                        <input
                            type="checkbox"
                            :checked="isChecked(o.id)"
                            class="w-4 h-4 rounded text-blue-600 border-slate-300 dark:border-gray-500 focus:ring-blue-500 focus:ring-1 cursor-pointer pointer-events-none"
                        >
                        <span class="text-slate-700 dark:text-gray-300 truncate" x-text="o.label"></span>
                    </label>
                </template>
            </div>
            <div x-show="filtered.length === 0" class="py-6 text-center text-xs text-slate-400 dark:text-gray-500">
                <i class="fa fa-search mb-1 block text-lg"></i>
                {{ $emptyMessage ?? '該当する項目がありません' }}
            </div>
        </div>
    </div>
</x-dynamic-component>
