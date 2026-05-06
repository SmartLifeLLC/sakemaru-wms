<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        x-data="{
            state: $wire.entangle('{{ $getStatePath() }}').live,
            options: [
                { value: 'primary', label: '1次（波動集約）', icon: 'fa-layer-group', desc: '商品別の総数量一覧', color: 'blue' },
                { value: 'shortage', label: '1次欠品', icon: 'fa-exclamation-triangle', desc: '引当欠品リスト', color: 'red' },
                { value: 'secondary', label: '2次（作業者別）', icon: 'fa-user-hard-hat', desc: '棚番順＋納品先内訳', color: 'blue' },
                { value: 'tertiary', label: '3次（納品先別仕分け）', icon: 'fa-store', desc: '配送コース別→納品先別', color: 'blue' },
            ],
        }"
        class="grid grid-cols-4 gap-3"
    >
        <template x-for="opt in options" :key="opt.value">
            <button
                type="button"
                @click="state = opt.value"
                :class="{
                    'border-blue-400 bg-blue-50 dark:border-blue-500 dark:bg-blue-900/30 ring-1 ring-blue-400': state === opt.value && opt.color === 'blue',
                    'border-red-400 bg-red-50 dark:border-red-500 dark:bg-red-900/30 ring-1 ring-red-400': state === opt.value && opt.color === 'red',
                    'border-slate-200 dark:border-gray-700 hover:bg-slate-50 dark:hover:bg-gray-800': state !== opt.value,
                }"
                class="flex flex-col items-center gap-1.5 p-3 rounded-lg border-2 transition-all cursor-pointer text-center"
            >
                <i
                    :class="{
                        'text-blue-600 dark:text-blue-400': state === opt.value && opt.color === 'blue',
                        'text-red-600 dark:text-red-400': state === opt.value && opt.color === 'red',
                        'text-slate-400 dark:text-gray-500': state !== opt.value,
                    }"
                    class="fa text-lg"
                    :class="'fa ' + opt.icon"
                ></i>
                <span
                    :class="{
                        'text-blue-700 dark:text-blue-300 font-bold': state === opt.value && opt.color === 'blue',
                        'text-red-700 dark:text-red-300 font-bold': state === opt.value && opt.color === 'red',
                        'text-slate-700 dark:text-gray-300 font-medium': state !== opt.value,
                    }"
                    class="text-sm"
                    x-text="opt.label"
                ></span>
                <span class="text-xs text-slate-400 dark:text-gray-500" x-text="opt.desc"></span>
            </button>
        </template>
    </div>
</x-dynamic-component>
