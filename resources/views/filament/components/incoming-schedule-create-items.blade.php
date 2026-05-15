<div x-data="{
    filters: {
        itemCode: '',
        janCode: '',
        itemName: '',
    },
    results: [],
    quantities: {},
    totalCount: 0,
    currentPage: 1,
    lastPage: 1,
    perPage: 25,
    loading: false,
    searched: false,

    async search(page = 1) {
        const warehouseId = $wire.get('mountedActions.0.data.warehouse_id');
        if (!warehouseId) {
            alert('入荷倉庫を選択してください');
            return;
        }
        this.loading = true;
        this.currentPage = page;
        try {
            const result = await $wire.searchItemsForIncomingModal(
                parseInt(warehouseId),
                this.filters.itemCode || null,
                this.filters.janCode || null,
                this.filters.itemName || null,
                page,
                this.perPage
            );
            this.results = result.data;
            this.totalCount = result.total;
            this.currentPage = result.current_page;
            this.lastPage = result.last_page;
            this.searched = true;

            this.results.forEach(item => {
                const key = String(item.id);
                if (!(key in this.quantities)) {
                    this.quantities[key] = {
                        caseQty: item.pending_case_qty || null,
                        pieceQty: item.pending_piece_qty || null,
                    };
                }
            });
        } finally {
            this.loading = false;
        }
    },

    resetFilters() {
        this.filters = { itemCode: '', janCode: '', itemName: '' };
        this.results = [];
        this.searched = false;
        this.totalCount = 0;
    },

    onQtyChange() {
        this.syncToWire();
    },

    syncToWire() {
        const items = [];
        for (const [itemId, qty] of Object.entries(this.quantities)) {
            const item = this.results.find(r => String(r.id) === itemId);
            if (!item) continue;
            if ((qty.caseQty > 0) || (qty.pieceQty > 0)) {
                items.push({
                    item_id: parseInt(itemId),
                    item_code: item.code,
                    capacity_case: item.capacity_case || 1,
                    case_qty: qty.caseQty || 0,
                    piece_qty: qty.pieceQty || 0,
                });
            }
        }
        $wire.set('incomingItems', items);
    },

    get validCount() {
        let count = 0;
        for (const qty of Object.values(this.quantities)) {
            if ((qty.caseQty > 0) || (qty.pieceQty > 0)) count++;
        }
        return count;
    },

    getQty(itemId) {
        const key = String(itemId);
        if (!(key in this.quantities)) {
            this.quantities[key] = { caseQty: null, pieceQty: null };
        }
        return this.quantities[key];
    }
}" x-init="$wire.set('incomingItems', [])" class="space-y-3">

    {{-- 検索フィルタ --}}
    <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3 space-y-2">
        <div class="text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">商品検索フィルタ</div>
        <div class="grid grid-cols-4 gap-2">
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-0.5">商品CD</label>
                <input type="text" x-model="filters.itemCode"
                       @keydown.enter.prevent="search(1)"
                       class="w-full rounded border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                       placeholder="商品CD...">
            </div>
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-0.5">JANコード</label>
                <input type="text" x-model="filters.janCode"
                       @keydown.enter.prevent="search(1)"
                       class="w-full rounded border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                       placeholder="JANコード...">
            </div>
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-0.5">商品名</label>
                <input type="text" x-model="filters.itemName"
                       @keydown.enter.prevent="search(1)"
                       class="w-full rounded border-gray-300 text-sm shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                       placeholder="商品名...">
            </div>
            <div class="flex items-end gap-1">
                <button type="button" @click="search(1)"
                        class="flex-1 inline-flex items-center justify-center gap-1 rounded-md bg-primary-600 px-3 py-1.5 text-xs font-medium text-white shadow-sm hover:bg-primary-500 transition">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    検索
                </button>
                <button type="button" @click="resetFilters()"
                        class="inline-flex items-center justify-center rounded-md bg-gray-200 px-2 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-300 dark:bg-gray-600 dark:text-gray-300 dark:hover:bg-gray-500 transition">
                    リセット
                </button>
            </div>
        </div>
    </div>

    {{-- 件数表示 --}}
    <div class="flex items-center justify-between text-xs text-gray-500 dark:text-gray-400">
        <div>
            <span x-show="searched">
                検索結果: <span class="font-semibold text-gray-700 dark:text-gray-300" x-text="totalCount"></span>件
                <span x-show="validCount > 0" class="ml-2 text-primary-600 dark:text-primary-400 font-semibold">
                    (<span x-text="validCount"></span>件入力済み)
                </span>
            </span>
        </div>
        <div x-show="loading" class="flex items-center gap-1 text-primary-600">
            <svg class="animate-spin h-3.5 w-3.5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
            <span>検索中...</span>
        </div>
    </div>

    {{-- 検索結果テーブル --}}
    <div class="border rounded-lg dark:border-gray-600 overflow-x-auto max-h-[400px] overflow-y-auto">
        <table class="w-full text-left divide-y divide-gray-200 dark:divide-white/10">
            <thead class="bg-gray-50 dark:bg-gray-800 sticky top-0 z-10">
                <tr class="divide-x divide-gray-200 dark:divide-white/10">
                    <th class="px-1.5 py-1 text-left text-xs font-medium text-gray-500 dark:text-gray-400">商品CD</th>
                    <th class="px-1.5 py-1 text-left text-xs font-medium text-gray-500 dark:text-gray-400">商品名</th>
                    <th class="px-1.5 py-1 text-left text-xs font-medium text-gray-500 dark:text-gray-400">規格</th>
                    <th class="px-1.5 py-1 text-right text-xs font-medium text-gray-500 dark:text-gray-400">入数</th>
                    <th class="px-1.5 py-1 text-left text-xs font-medium text-gray-500 dark:text-gray-400">発注先</th>
                    <th class="px-1.5 py-1 text-right text-xs font-medium text-gray-500 dark:text-gray-400">当日</th>
                    <th class="px-1.5 py-1 text-right text-xs font-medium text-gray-500 dark:text-gray-400">前日</th>
                    <th class="px-1.5 py-1 text-right text-xs font-medium text-gray-500 dark:text-gray-400">前々日</th>
                    <th class="px-1.5 py-1 text-right text-xs font-medium text-gray-500 dark:text-gray-400">3日</th>
                    <th class="px-1.5 py-1 text-right text-xs font-medium text-gray-500 dark:text-gray-400">5日</th>
                    <th class="px-1.5 py-1 text-right text-xs font-medium text-gray-500 dark:text-gray-400">7日</th>
                    <th class="px-1.5 py-1 text-right text-xs font-medium text-gray-500 dark:text-gray-400">30日</th>
                    <th class="px-1 py-1 text-right text-xs font-medium text-gray-500 dark:text-gray-400">ケース</th>
                    <th class="px-1 py-1 text-right text-xs font-medium text-gray-500 dark:text-gray-400">バラ</th>
                    <th class="px-1 py-1 text-right text-xs font-medium text-gray-500 dark:text-gray-400">総バラ</th>
                </tr>
            </thead>
            <tbody>
                <template x-for="(item, index) in results" :key="item.id">
                    <tr :class="index % 2 === 0 ? 'bg-white dark:bg-gray-900' : 'bg-blue-50/30 dark:bg-blue-950/20'"
                        class="divide-x divide-gray-200 dark:divide-white/10 border-t border-gray-200 dark:border-white/10">
                        <td class="px-1.5 py-0.5">
                            <span class="text-xs font-mono text-gray-900 dark:text-white" x-text="item.code"></span>
                        </td>
                        <td class="px-1.5 py-0.5">
                            <span class="text-xs text-gray-700 dark:text-gray-300 truncate block" x-text="item.name"></span>
                        </td>
                        <td class="px-1.5 py-0.5">
                            <span class="text-xs text-gray-500 dark:text-gray-400" x-text="item.packaging || '-'"></span>
                        </td>
                        <td class="px-1.5 py-0.5 text-right">
                            <span class="text-xs font-mono text-gray-500 dark:text-gray-400" x-text="item.capacity_case"></span>
                        </td>
                        <td class="px-1.5 py-0.5">
                            <span class="text-xs text-gray-500 dark:text-gray-400 truncate block" x-text="item.contractor_name || '-'"></span>
                        </td>
                        <td class="px-1.5 py-0.5 text-right">
                            <span class="text-xs font-mono" :class="item.sales_today_qty > 0 ? 'text-gray-900 dark:text-white' : 'text-gray-400'" x-text="item.sales_today_qty || 0"></span>
                        </td>
                        <td class="px-1.5 py-0.5 text-right">
                            <span class="text-xs font-mono" :class="item.sales_yesterday_qty > 0 ? 'text-gray-900 dark:text-white' : 'text-gray-400'" x-text="item.sales_yesterday_qty || 0"></span>
                        </td>
                        <td class="px-1.5 py-0.5 text-right">
                            <span class="text-xs font-mono" :class="item.sales_2days_ago_qty > 0 ? 'text-gray-900 dark:text-white' : 'text-gray-400'" x-text="item.sales_2days_ago_qty || 0"></span>
                        </td>
                        <td class="px-1.5 py-0.5 text-right">
                            <span class="text-xs font-mono" :class="item.last_3d_qty > 0 ? 'text-gray-900 dark:text-white' : 'text-gray-400'" x-text="item.last_3d_qty || 0"></span>
                        </td>
                        <td class="px-1.5 py-0.5 text-right">
                            <span class="text-xs font-mono" :class="item.last_5d_qty > 0 ? 'text-gray-900 dark:text-white' : 'text-gray-400'" x-text="item.last_5d_qty || 0"></span>
                        </td>
                        <td class="px-1.5 py-0.5 text-right">
                            <span class="text-xs font-mono" :class="item.last_7d_qty > 0 ? 'text-gray-900 dark:text-white' : 'text-gray-400'" x-text="item.last_7d_qty || 0"></span>
                        </td>
                        <td class="px-1.5 py-0.5 text-right">
                            <span class="text-xs font-mono" :class="item.last_30d_qty > 0 ? 'text-gray-900 dark:text-white' : 'text-gray-400'" x-text="item.last_30d_qty || 0"></span>
                        </td>
                        <td class="px-0.5 py-0.5">
                            <input type="number"
                                   :value="getQty(item.id).caseQty"
                                   @input="getQty(item.id).caseQty = $event.target.value ? parseInt($event.target.value) : null; onQtyChange()"
                                   min="0"
                                   class="w-14 rounded border-gray-300 text-xs text-right py-0.5 px-1 shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                                   placeholder="0">
                        </td>
                        <td class="px-0.5 py-0.5">
                            <input type="number"
                                   :value="getQty(item.id).pieceQty"
                                   @input="getQty(item.id).pieceQty = $event.target.value ? parseInt($event.target.value) : null; onQtyChange()"
                                   min="0"
                                   class="w-14 rounded border-gray-300 text-xs text-right py-0.5 px-1 shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white"
                                   placeholder="0">
                        </td>
                        <td class="px-1 py-0.5 text-right">
                            <span class="text-xs font-mono font-semibold"
                                  :class="((getQty(item.id).caseQty || 0) * (item.capacity_case || 1) + (getQty(item.id).pieceQty || 0)) > 0 ? 'text-primary-600 dark:text-primary-400' : 'text-gray-400'"
                                  x-text="(getQty(item.id).caseQty || 0) * (item.capacity_case || 1) + (getQty(item.id).pieceQty || 0) || ''"></span>
                        </td>
                    </tr>
                </template>

                <template x-if="searched && results.length === 0 && !loading">
                    <tr>
                        <td colspan="11" class="px-4 py-6 text-center text-sm text-gray-400 dark:text-gray-500">
                            該当する商品が見つかりません
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>

    {{-- ページネーション --}}
    <div x-show="lastPage > 1" class="flex items-center justify-between text-xs">
        <div class="text-gray-500 dark:text-gray-400">
            <span x-text="currentPage"></span> / <span x-text="lastPage"></span> ページ
        </div>
        <div class="flex gap-1">
            <button type="button" @click="search(1)" :disabled="currentPage <= 1"
                    class="px-2 py-1 rounded border border-gray-300 dark:border-gray-600 hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-30 transition">
                &laquo;
            </button>
            <button type="button" @click="search(currentPage - 1)" :disabled="currentPage <= 1"
                    class="px-2 py-1 rounded border border-gray-300 dark:border-gray-600 hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-30 transition">
                &lsaquo;
            </button>
            <button type="button" @click="search(currentPage + 1)" :disabled="currentPage >= lastPage"
                    class="px-2 py-1 rounded border border-gray-300 dark:border-gray-600 hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-30 transition">
                &rsaquo;
            </button>
            <button type="button" @click="search(lastPage)" :disabled="currentPage >= lastPage"
                    class="px-2 py-1 rounded border border-gray-300 dark:border-gray-600 hover:bg-gray-100 dark:hover:bg-gray-700 disabled:opacity-30 transition">
                &raquo;
            </button>
        </div>
    </div>
</div>
