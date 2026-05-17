<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        x-data="{
            state: $wire.entangle('{{ $getStatePath() }}').live,
            enabledTypes: @js($enabledTypes ?? null),
            options: [
                { value: 'primary', label: '1次（波動集約）', icon: 'fa-layer-group', desc: '商品別の総数量一覧', color: 'blue' },
                { value: 'primary_total', label: '1次リスト(一括)', icon: 'fa-calculator', desc: '波動をまたいだ商品別合計', color: 'blue' },
                { value: 'shortage', label: '1次欠品', icon: 'fa-exclamation-triangle', desc: '引当欠品リスト', color: 'red' },
                { value: 'secondary', label: '2次（配送コース別）', icon: 'fa-user-hard-hat', desc: '配送者別、フロア別', color: 'blue' },
                { value: 'secondary_v2', label: '2次リスト2', icon: 'fa-user-hard-hat', desc: '1F→2F→YX順', color: 'blue' },
                { value: 'tertiary', label: '3次（得意先別）', icon: 'fa-store', desc: '配送者別、得意先別', color: 'blue' },
            ],
            get visibleOptions() {
                if (!Array.isArray(this.enabledTypes) || this.enabledTypes.length === 0) return this.options;
                return this.options.filter(opt => this.enabledTypes.includes(opt.value));
            },
        }"
        class="grid grid-cols-6 gap-3"
    >
        <template x-for="opt in visibleOptions" :key="opt.value">
            <button
                type="button"
                @click="state = opt.value"
                :class="{
                    'border-blue-400 bg-blue-50 dark:border-blue-500 dark:bg-blue-900/30 ring-1 ring-blue-400': state === opt.value && opt.color === 'blue',
                    'border-red-400 bg-red-50 dark:border-red-500 dark:bg-red-900/30 ring-1 ring-red-400': state === opt.value && opt.color === 'red',
                    'border-slate-200 dark:border-gray-700 hover:bg-slate-50 dark:hover:bg-gray-800': state !== opt.value,
                }"
                class="flex flex-col items-center p-3 rounded-lg border-2 transition-all cursor-pointer text-center"
            >
                <span class="h-5 w-full flex justify-center items-center mb-1">
                    <span
                        x-show="state === opt.value"
                        :class="{
                            'bg-blue-500': opt.color === 'blue',
                            'bg-red-500': opt.color === 'red',
                        }"
                        class="w-5 h-5 rounded-full flex items-center justify-center shadow"
                    >
                        <i class="fa fa-check text-white text-[10px]"></i>
                    </span>
                </span>

                <i
                    :class="[opt.icon, {
                        'text-blue-600 dark:text-blue-400': state === opt.value && opt.color === 'blue',
                        'text-red-600 dark:text-red-400': state === opt.value && opt.color === 'red',
                        'text-slate-400 dark:text-gray-500': state !== opt.value,
                    }]"
                    class="fa text-lg mb-1.5"
                ></i>
                <span
                    :class="{
                        'text-blue-700 dark:text-blue-300 font-bold': state === opt.value && opt.color === 'blue',
                        'text-red-700 dark:text-red-300 font-bold': state === opt.value && opt.color === 'red',
                        'text-slate-700 dark:text-gray-300 font-medium': state !== opt.value,
                    }"
                    class="text-sm mb-1"
                    x-text="opt.label"
                ></span>
                <span class="text-xs text-slate-400 dark:text-gray-500" x-text="opt.desc"></span>
            </button>
        </template>
    </div>
</x-dynamic-component>
