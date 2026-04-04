<div x-data="{
    rows: Array.from({ length: 10 }, (_, i) => ({ id: i + 1, itemId: null, itemCode: '', searchCode: '', itemName: '', searchQuery: '', quantity: 1, stock: null, showDropdown: false, searchResults: [], loading: false })),
    nextId: 11,

    addRow() {
        this.rows.push({ id: this.nextId++, itemId: null, itemCode: '', searchCode: '', itemName: '', searchQuery: '', quantity: 1, stock: null, showDropdown: false, searchResults: [], loading: false });
    },

    removeRow(index) {
        if (this.rows.length > 1) {
            this.rows.splice(index, 1);
            this.syncToWire();
        }
    },

    async search(index) {
        const query = this.rows[index].searchQuery;
        if (query.length < 2) {
            this.rows[index].searchResults = [];
            this.rows[index].showDropdown = false;
            return;
        }
        this.rows[index].loading = true;
        try {
            const results = await $wire.searchItemsForCreate(query);
            this.rows[index].searchResults = results;
            this.rows[index].showDropdown = true;
        } finally {
            this.rows[index].loading = false;
        }
    },

    async selectItem(index, item) {
        this.rows[index].itemId = item.id;
        this.rows[index].itemCode = item.code;
        this.rows[index].searchCode = item.search_code || '';
        this.rows[index].itemName = item.name;
        this.rows[index].searchQuery = item.code;
        this.rows[index].showDropdown = false;
        this.rows[index].searchResults = [];
        this.syncToWire();
        // 現在庫を取得（依頼倉庫のselect値をDOMから読み取る）
        const whSelect = this.$root.closest('.fi-modal-content')?.querySelector('[wire\\:model\\.live\\.debounce\\.250ms]');
        const warehouseId = whSelect?.value || $wire.get('mountedActions.0.data.satellite_warehouse_id');
        if (warehouseId) {
            const stock = await $wire.getItemStockForCreate(parseInt(warehouseId), item.id);
            this.rows[index].stock = stock;
        }
        // 次の空行がなければ自動追加
        const hasEmpty = this.rows.some(r => !r.itemId);
        if (!hasEmpty) this.addRow();
    },

    clearItem(index) {
        this.rows[index].itemId = null;
        this.rows[index].itemCode = '';
        this.rows[index].searchCode = '';
        this.rows[index].itemName = '';
        this.rows[index].searchQuery = '';
        this.rows[index].stock = null;
        this.syncToWire();
    },

    syncToWire() {
        const items = this.rows
            .filter(r => r.itemId)
            .map(r => ({ item_id: r.itemId, item_code: r.itemCode, search_code: r.searchCode, quantity: r.quantity }));
        $wire.set('transferOrderItems', items);
    },

    get validCount() {
        return this.rows.filter(r => r.itemId && r.quantity > 0).length;
    }
}" x-init="$wire.set('transferOrderItems', [])" class="space-y-2">

    {{-- テーブル --}}
    <div class="border border-gray-200 dark:border-white/10 rounded-lg overflow-y-auto"
         :style="rows.length > 10 ? 'max-height: 352px' : ''">
        <table class="w-full text-sm table-fixed">
            <colgroup>
                <col style="width: 110px" />
                <col style="width: 80px" />
                <col />
                <col style="width: 50px" />
                <col style="width: 65px" />
                <col style="width: 24px" />
            </colgroup>
            <thead class="sticky top-0 z-10 bg-gray-100 dark:bg-white/10">
                <tr class="divide-x divide-gray-200 dark:divide-white/10">
                    <th class="px-2 py-1.5 text-left text-xs font-medium text-gray-500 dark:text-gray-400">商品CD</th>
                    <th class="px-2 py-1.5 text-left text-xs font-medium text-gray-500 dark:text-gray-400">検索CD</th>
                    <th class="px-2 py-1.5 text-left text-xs font-medium text-gray-500 dark:text-gray-400">商品名</th>
                    <th class="px-2 py-1.5 text-right text-xs font-medium text-gray-500 dark:text-gray-400">現在庫</th>
                    <th class="px-2 py-1.5 text-right text-xs font-medium text-gray-500 dark:text-gray-400">発注数</th>
                    <th class="px-2 py-1.5"></th>
                </tr>
            </thead>
            <tbody>
                <template x-for="(row, index) in rows" :key="row.id">
                    <tr :class="index % 2 === 0 ? 'bg-white dark:bg-gray-900' : 'bg-blue-50/30 dark:bg-blue-950/20'"
                        class="divide-x divide-gray-200 dark:divide-white/10 border-t border-gray-200 dark:border-white/10">
                        {{-- 商品検索 --}}
                        <td class="px-1.5 py-1 relative overflow-visible">
                            <div class="relative">
                                <input type="text"
                                    x-model="row.searchQuery"
                                    @input.debounce.300ms="search(index)"
                                    @focus="if (row.searchResults.length) row.showDropdown = true"
                                    placeholder="コード or 名前"
                                    class="w-full border border-gray-300 dark:border-gray-600 rounded px-1.5 py-1 text-xs font-mono bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-1 focus:ring-primary-500 focus:border-primary-500"
                                />
                                <template x-if="row.loading">
                                    <div class="absolute right-2 top-1/2 -translate-y-1/2">
                                        <svg class="animate-spin h-3 w-3 text-gray-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                                        </svg>
                                    </div>
                                </template>
                                {{-- 検索結果ドロップダウン --}}
                                <div x-show="row.showDropdown && row.searchResults.length > 0"
                                     x-cloak
                                     @click.outside="row.showDropdown = false"
                                     class="absolute z-[100] left-0 mt-1 w-[400px] bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-600 rounded-lg shadow-xl max-h-48 overflow-y-auto">
                                    <template x-for="item in row.searchResults" :key="item.id">
                                        <button type="button"
                                            @click="selectItem(index, item)"
                                            class="w-full text-left px-3 py-1.5 hover:bg-primary-50 dark:hover:bg-primary-900/30 text-xs border-b border-gray-100 dark:border-gray-700 last:border-0 flex items-center gap-2">
                                            <span class="font-mono font-medium text-gray-900 dark:text-white shrink-0" x-text="item.code"></span>
                                            <span class="text-gray-500 dark:text-gray-400 shrink-0" x-show="item.search_code" x-text="'[' + item.search_code + ']'"></span>
                                            <span class="text-gray-700 dark:text-gray-300 truncate" x-text="item.name"></span>
                                        </button>
                                    </template>
                                </div>
                            </div>
                        </td>
                        {{-- 検索CD --}}
                        <td class="px-1.5 py-1">
                            <span class="text-xs text-gray-500 dark:text-gray-400 font-mono" x-text="row.searchCode || '-'"></span>
                        </td>
                        {{-- 商品名 --}}
                        <td class="px-1.5 py-1">
                            <span class="text-xs text-gray-700 dark:text-gray-300" x-text="row.itemName || '-'"></span>
                        </td>
                        {{-- 現在庫 --}}
                        <td class="px-1.5 py-1 text-right">
                            <span class="text-xs font-mono"
                                :class="row.stock !== null ? 'text-gray-900 dark:text-white' : 'text-gray-400 dark:text-gray-500'"
                                x-text="row.stock !== null ? Number(row.stock).toLocaleString() : '-'"></span>
                        </td>
                        {{-- 発注数 --}}
                        <td class="px-1.5 py-1">
                            <input type="number"
                                x-model.number="row.quantity"
                                @input="syncToWire()"
                                min="1"
                                :disabled="!row.itemId"
                                class="w-full border border-gray-300 dark:border-gray-600 rounded px-1.5 py-1 text-xs text-right font-mono bg-white dark:bg-gray-800 text-gray-900 dark:text-white disabled:opacity-40 focus:ring-1 focus:ring-primary-500 focus:border-primary-500"
                            />
                        </td>
                        {{-- 削除 --}}
                        <td class="px-0.5 py-1 text-center">
                            <button type="button"
                                @click="removeRow(index)"
                                x-show="rows.length > 1"
                                class="text-gray-400 hover:text-red-500 transition-colors p-0.5">
                                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                                </svg>
                            </button>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>

    {{-- フッター: 行追加 + 件数 --}}
    <div class="flex items-center justify-between">
        <button type="button" @click="addRow()"
                class="flex items-center gap-1 text-xs text-primary-600 hover:text-primary-700 dark:text-primary-400 dark:hover:text-primary-300 font-medium">
            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
            </svg>
            行を追加
        </button>
        <div class="text-xs text-gray-500 dark:text-gray-400">
            <span x-text="validCount"></span>件の商品を追加
        </div>
    </div>
</div>
