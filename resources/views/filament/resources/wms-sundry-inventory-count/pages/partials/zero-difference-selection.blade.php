<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div x-data="{
        state: $wire.entangle('{{ $getStatePath() }}').live,
        options: @js($options), search: '',
        init() { if (!Array.isArray(this.state)) this.state = []; },
        get filtered() {
            const query = this.search.normalize('NFKC').toLowerCase().trim();
            return this.options.filter(row => (row.item_code + ' ' + row.item_name).normalize('NFKC').toLowerCase().includes(query));
        }
    }" class="space-y-2">
        <div class="flex items-center gap-2">
            <input type="search" x-model.debounce.150ms="search" aria-label="差異0指定の商品検索" placeholder="商品CD・商品名"
                class="min-w-0 flex-1 rounded border border-gray-300 bg-white px-2 py-1 text-sm text-gray-900 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
            <span class="whitespace-nowrap text-sm text-gray-700 dark:text-gray-200" x-text="state.length + '件指定'"></span>
        </div>
        <div class="overflow-auto border border-gray-200 dark:border-gray-700" style="max-height: 16rem;">
            <table class="w-full text-sm text-gray-900 dark:text-gray-100">
                <thead class="sticky top-0 bg-gray-100 dark:bg-gray-800"><tr>
                    <th class="p-2 whitespace-nowrap">差異0</th><th class="p-2 whitespace-nowrap">商品CD</th>
                    <th class="p-2 whitespace-nowrap">商品名</th><th class="p-2 whitespace-nowrap">保存理論</th><th class="p-2 whitespace-nowrap">入力実棚</th>
                </tr></thead>
                <tbody><template x-for="row in filtered" :key="row.id">
                    <tr class="border-t border-gray-200 dark:border-gray-700">
                        <td class="p-2 text-center"><input type="checkbox" x-model="state" :value="String(row.id)"
                            :aria-label="row.item_code + ' ' + row.item_name + 'を差異0指定'"
                            class="rounded border-gray-300 dark:border-gray-600"></td>
                        <td class="p-2 whitespace-nowrap" x-text="row.item_code"></td>
                        <td class="p-2 whitespace-nowrap" x-text="row.item_name"></td>
                        <td class="p-2 text-right" x-text="row.system_quantity"></td>
                        <td class="p-2 text-right" x-text="row.counted_quantity"></td>
                    </tr>
                </template></tbody>
            </table>
            <p x-show="filtered.length === 0" class="p-3 text-center text-sm text-gray-600 dark:text-gray-300">対象商品はありません。</p>
        </div>
    </div>
</x-dynamic-component>
