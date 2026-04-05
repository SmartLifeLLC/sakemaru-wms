<div x-data="{
    rows: Array.from({ length: 10 }, (_, i) => ({ id: i + 1, itemId: null, itemCode: '', searchCode: '', orderingCode: '', itemName: '', capacityCase: 1, searchQuery: '', caseQty: null, pieceQty: null, stock: null, incomingQty: null, showDropdown: false, searchResults: [], loading: false })),
    nextId: 11,

    addRow() {
        this.rows.push({ id: this.nextId++, itemId: null, itemCode: '', searchCode: '', orderingCode: '', itemName: '', capacityCase: 1, searchQuery: '', caseQty: null, pieceQty: null, stock: null, incomingQty: null, showDropdown: false, searchResults: [], loading: false });
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
            const results = await $wire.searchItemsForOrderCreate(query);
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
        this.rows[index].orderingCode = item.ordering_code || '';
        this.rows[index].itemName = item.name;
        this.rows[index].capacityCase = item.capacity_case || 1;
        this.rows[index].searchQuery = item.code;
        this.rows[index].showDropdown = false;
        this.rows[index].searchResults = [];
        this.syncToWire();
        // 現在庫・入荷予定数を取得
        const warehouseId = $wire.get('mountedActions.0.data.warehouse_id');
        if (warehouseId) {
            const [stock, incomingQty] = await Promise.all([
                $wire.getItemStockForOrderCreate(parseInt(warehouseId), item.id),
                $wire.getItemIncomingQuantityForOrderCreate(parseInt(warehouseId), item.id),
            ]);
            this.rows[index].stock = stock;
            this.rows[index].incomingQty = incomingQty;
        }
        // 次の空行がなければ自動追加
        if (!this.rows.some(r => !r.itemId)) this.addRow();
    },

    onCaseInput(index) {
        if (this.rows[index].caseQty !== null && this.rows[index].caseQty !== '') {
            this.rows[index].pieceQty = null;
        }
        this.syncToWire();
    },

    onPieceInput(index) {
        if (this.rows[index].pieceQty !== null && this.rows[index].pieceQty !== '') {
            this.rows[index].caseQty = null;
        }
        this.syncToWire();
    },

    orderQty(row) {
        const cap = row.capacityCase || 1;
        if (row.caseQty > 0) return row.caseQty * cap;
        if (row.pieceQty > 0) return Math.ceil(row.pieceQty / cap) * cap;
        return 0;
    },

    isRoundedUp(row) {
        if (!row.pieceQty || row.pieceQty <= 0) return false;
        const cap = row.capacityCase || 1;
        return row.pieceQty % cap !== 0;
    },

    syncToWire() {
        const items = this.rows
            .filter(r => r.itemId && this.orderQty(r) > 0)
            .map(r => ({
                item_id: r.itemId,
                item_code: r.itemCode,
                search_code: r.searchCode,
                ordering_code: r.orderingCode,
                capacity_case: r.capacityCase,
                case_qty: r.caseQty || 0,
                piece_qty: r.pieceQty || 0,
                order_quantity: this.orderQty(r),
            }));
        $wire.set('orderCandidateItems', items);
    },

    get validCount() {
        return this.rows.filter(r => r.itemId && this.orderQty(r) > 0).length;
    }
}" x-init="$wire.set('orderCandidateItems', [])" class="space-y-2">

    {{-- テーブル --}}
    <div class="border border-gray-200 dark:border-white/10 rounded-lg overflow-y-auto"
         :style="rows.length > 10 ? 'max-height: 352px' : ''">
        <table class="w-full text-sm table-fixed">
            <colgroup>
                <col style="width: 110px" />
                <col style="width: 90px" />
                <col />
                <col style="width: 38px" />
                <col style="width: 48px" />
                <col style="width: 48px" />
                <col style="width: 55px" />
                <col style="width: 55px" />
                <col style="width: 50px" />
                <col style="width: 24px" />
            </colgroup>
            <thead class="sticky top-0 z-10 bg-gray-100 dark:bg-white/10">
                <tr class="divide-x divide-gray-200 dark:divide-white/10">
                    <th class="px-2 py-1.5 text-left text-xs font-medium text-gray-500 dark:text-gray-400">商品CD</th>
                    <th class="px-2 py-1.5 text-left text-xs font-medium text-gray-500 dark:text-gray-400">発注CD</th>
                    <th class="px-2 py-1.5 text-left text-xs font-medium text-gray-500 dark:text-gray-400">商品名</th>
                    <th class="px-2 py-1.5 text-right text-xs font-medium text-gray-500 dark:text-gray-400">入数</th>
                    <th class="px-2 py-1.5 text-right text-xs font-medium text-gray-500 dark:text-gray-400">現在庫</th>
                    <th class="px-2 py-1.5 text-right text-xs font-medium text-gray-500 dark:text-gray-400">入荷予定</th>
                    <th class="px-2 py-1.5 text-right text-xs font-medium text-gray-500 dark:text-gray-400">ケース</th>
                    <th class="px-2 py-1.5 text-right text-xs font-medium text-gray-500 dark:text-gray-400">バラ</th>
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
                                     class="absolute z-[100] left-0 mt-1 w-[420px] bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-600 rounded-lg shadow-xl max-h-48 overflow-y-auto">
                                    <template x-for="item in row.searchResults" :key="item.id">
                                        <button type="button"
                                            @click="selectItem(index, item)"
                                            class="w-full text-left px-3 py-1.5 hover:bg-primary-50 dark:hover:bg-primary-900/30 text-xs border-b border-gray-100 dark:border-gray-700 last:border-0 flex items-center gap-2">
                                            <span class="font-mono font-medium text-gray-900 dark:text-white shrink-0" x-text="item.code"></span>
                                            <span class="text-gray-500 dark:text-gray-400 shrink-0" x-show="item.search_code" x-text="'[' + item.search_code + ']'"></span>
                                            <span class="text-gray-700 dark:text-gray-300 truncate" x-text="item.name"></span>
                                            <span class="ml-auto text-gray-400 shrink-0" x-text="'入数:' + item.capacity_case"></span>
                                        </button>
                                    </template>
                                </div>
                            </div>
                        </td>
                        {{-- 発注CD --}}
                        <td class="px-1.5 py-1">
                            <span class="text-xs text-gray-500 dark:text-gray-400 font-mono" x-text="row.orderingCode || '-'"></span>
                        </td>
                        {{-- 商品名 --}}
                        <td class="px-1.5 py-1">
                            <span class="text-xs text-gray-700 dark:text-gray-300" x-text="row.itemName || '-'"></span>
                        </td>
                        {{-- 入数 --}}
                        <td class="px-1.5 py-1 text-right">
                            <span class="text-xs font-mono text-gray-500 dark:text-gray-400" x-text="row.itemId ? row.capacityCase : '-'"></span>
                        </td>
                        {{-- 現在庫 --}}
                        <td class="px-1.5 py-1 text-right">
                            <span class="text-xs font-mono"
                                :class="row.stock !== null ? 'text-gray-900 dark:text-white' : 'text-gray-400 dark:text-gray-500'"
                                x-text="row.stock !== null ? Number(row.stock).toLocaleString() : '-'"></span>
                        </td>
                        {{-- 入荷予定 --}}
                        <td class="px-1.5 py-1 text-right">
                            <span class="text-xs font-mono"
                                :class="row.incomingQty > 0 ? 'text-blue-600 dark:text-blue-400' : 'text-gray-400 dark:text-gray-500'"
                                x-text="row.incomingQty !== null ? Number(row.incomingQty).toLocaleString() : '-'"></span>
                        </td>
                        {{-- ケース --}}
                        <td class="px-1 py-1">
                            <input type="number"
                                x-model.number="row.caseQty"
                                @input="onCaseInput(index)"
                                min="1"
                                :disabled="!row.itemId"
                                placeholder=""
                                class="w-full border border-gray-300 dark:border-gray-600 rounded px-1 py-1 text-xs text-right font-mono bg-white dark:bg-gray-800 text-gray-900 dark:text-white disabled:opacity-40 focus:ring-1 focus:ring-primary-500 focus:border-primary-500"
                            />
                        </td>
                        {{-- バラ --}}
                        <td class="px-1 py-1">
                            <input type="number"
                                x-model.number="row.pieceQty"
                                @input="onPieceInput(index)"
                                min="1"
                                :disabled="!row.itemId"
                                placeholder=""
                                class="w-full border border-gray-300 dark:border-gray-600 rounded px-1 py-1 text-xs text-right font-mono bg-white dark:bg-gray-800 text-gray-900 dark:text-white disabled:opacity-40 focus:ring-1 focus:ring-primary-500 focus:border-primary-500"
                            />
                        </td>
                        {{-- 発注数 --}}
                        <td class="px-1.5 py-1 text-right">
                            <template x-if="orderQty(row) > 0">
                                <div>
                                    <span class="text-xs font-mono font-bold text-primary-600 dark:text-primary-400" x-text="Number(orderQty(row)).toLocaleString()"></span>
                                    <template x-if="isRoundedUp(row)">
                                        <div class="text-[10px] text-amber-600 dark:text-amber-400">切上</div>
                                    </template>
                                </div>
                            </template>
                            <template x-if="orderQty(row) === 0">
                                <span class="text-xs text-gray-400">-</span>
                            </template>
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

    {{-- フッター --}}
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
