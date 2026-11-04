<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        x-data="{
            selected: $wire.entangle(@js($getStatePath())).live,
            warehouses: @js($warehouses),
        }"
        class="space-y-2"
    >
        <div class="flex flex-wrap items-center justify-end gap-2 text-xs">
            <span class="text-slate-500 dark:text-gray-400" x-text="selected.length + '件選択'"></span>
            <button type="button" @click="selected = warehouses.map(warehouse => warehouse.id)" class="text-blue-600 hover:underline dark:text-blue-400">すべて選択</button>
            <button type="button" @click="selected = []" class="text-slate-500 hover:underline dark:text-gray-400">すべて解除</button>
        </div>
        <div class="grid max-h-64 grid-cols-2 gap-2 overflow-y-auto rounded-lg border border-slate-200 p-2 md:grid-cols-4 dark:border-gray-700">
            <template x-for="warehouse in warehouses" :key="warehouse.id">
                <button
                    type="button"
                    @click="selected = selected.includes(warehouse.id) ? selected.filter(id => id !== warehouse.id) : [...selected, warehouse.id]"
                    :aria-pressed="selected.includes(warehouse.id)"
                    :class="selected.includes(warehouse.id)
                        ? 'border-blue-400 bg-blue-50 dark:border-blue-500 dark:bg-blue-900/20'
                        : 'border-slate-200 hover:bg-slate-50 dark:border-gray-700 dark:hover:bg-gray-800'"
                    class="flex items-center gap-2 rounded-md border px-2.5 py-2 text-left text-xs"
                >
                    <span class="font-mono text-slate-500 dark:text-gray-400" x-text="warehouse.code"></span>
                    <span class="truncate text-slate-800 dark:text-gray-200" x-text="warehouse.name" :title="warehouse.name"></span>
                </button>
            </template>
            <p x-show="warehouses.length === 0" class="col-span-full p-2 text-xs text-slate-500 dark:text-gray-400">対象となる倉庫がありません。</p>
        </div>
    </div>
</x-dynamic-component>
