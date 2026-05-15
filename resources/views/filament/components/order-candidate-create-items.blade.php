<div x-data="{
    filters: {
        itemCode: '',
        janCode: '',
        itemName: '',
        contractorId: '',
        category1Id: '',
        category2Id: '',
        category3Id: '',
        lastShippedFrom: '',
        lastShippedTo: '',
    },
    results: [],
    quantities: {},
    pinnedItems: {},
    totalCount: 0,
    currentPage: 1,
    lastPage: 1,
    perPage: 25,
    loading: false,
    searched: false,
    categories2: [],
    categories3: [],

    async search(page = 1) {
        const warehouseId = $wire.get('mountedActions.0.data.warehouse_id');
        if (!warehouseId) {
            alert('発注倉庫を選択してください');
            return;
        }
        this.loading = true;
        this.currentPage = page;
        this.updatePinnedItems();
        try {
            const result = await $wire.searchItemsForModal(
                parseInt(warehouseId),
                this.filters.itemCode || null,
                this.filters.janCode || null,
                this.filters.itemName || null,
                this.filters.contractorId ? parseInt(this.filters.contractorId) : null,
                this.filters.category1Id ? parseInt(this.filters.category1Id) : null,
                this.filters.category2Id ? parseInt(this.filters.category2Id) : null,
                this.filters.category3Id ? parseInt(this.filters.category3Id) : null,
                this.filters.lastShippedFrom || null,
                this.filters.lastShippedTo || null,
                page,
                this.perPage
            );
            this.totalCount = result.total;
            this.currentPage = result.current_page;
            this.lastPage = result.last_page;
            this.searched = true;

            const newIds = new Set(result.data.map(r => String(r.id)));
            const pinned = Object.values(this.pinnedItems).filter(p => !newIds.has(String(p.id)));
            this.results = [...pinned, ...result.data];

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

    updatePinnedItems() {
        this.results.forEach(item => {
            const key = String(item.id);
            const qty = this.quantities[key];
            if (qty && ((qty.caseQty > 0) || (qty.pieceQty > 0))) {
                this.pinnedItems[key] = { ...item };
            } else {
                delete this.pinnedItems[key];
            }
        });
    },

    resetFilters() {
        this.filters = { itemCode: '', janCode: '', itemName: '', contractorId: '', category1Id: '', category2Id: '', category3Id: '', lastShippedFrom: '', lastShippedTo: '' };
        this.categories2 = [];
        this.categories3 = [];
        this.results = [];
        this.pinnedItems = {};
        this.searched = false;
        this.totalCount = 0;
    },

    async loadCategories2() {
        this.filters.category2Id = '';
        this.filters.category3Id = '';
        this.categories3 = [];
        if (this.filters.category1Id) {
            this.categories2 = await $wire.getSubCategories(parseInt(this.filters.category1Id));
        } else {
            this.categories2 = [];
        }
    },

    async loadCategories3() {
        this.filters.category3Id = '';
        if (this.filters.category2Id) {
            this.categories3 = await $wire.getSubCategories(parseInt(this.filters.category2Id));
        } else {
            this.categories3 = [];
        }
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
                if (qty.caseQty > 0) {
                    items.push({
                        item_id: parseInt(itemId),
                        item_code: item.code,
                        search_code: item.search_code || '',
                        ordering_code: item.ordering_code || '',
                        capacity_case: item.capacity_case || 1,
                        quantity_type: 'CASE',
                        case_qty: qty.caseQty,
                        piece_qty: 0,
                        order_quantity: qty.caseQty,
                    });
                }
                if (qty.pieceQty > 0) {
                    items.push({
                        item_id: parseInt(itemId),
                        item_code: item.code,
                        search_code: item.search_code || '',
                        ordering_code: item.ordering_code || '',
                        capacity_case: item.capacity_case || 1,
                        quantity_type: 'PIECE',
                        case_qty: 0,
                        piece_qty: qty.pieceQty,
                        order_quantity: qty.pieceQty,
                    });
                }
            }
        }
        $wire.set('orderCandidateItems', items);
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
}" x-init="$wire.set('orderCandidateItems', [])" class="space-y-3">

    {{-- 検索フィルタ --}}
    <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-3 space-y-2">
        <div class="text-xs font-semibold text-gray-700 dark:text-gray-300 mb-1">商品検索フィルタ</div>
        <div class="grid grid-cols-5 gap-2">
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-0.5">商品CD</label>
                <input type="text" x-model="filters.itemCode" @keydown.enter.prevent="search(1)"
                    class="w-full border border-gray-300 dark:border-gray-600 rounded px-2 py-1 text-xs bg-white dark:bg-gray-900 text-gray-900 dark:text-white" />
            </div>
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-0.5">JANコード</label>
                <input type="text" x-model="filters.janCode" @keydown.enter.prevent="search(1)"
                    class="w-full border border-gray-300 dark:border-gray-600 rounded px-2 py-1 text-xs bg-white dark:bg-gray-900 text-gray-900 dark:text-white" />
            </div>
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-0.5">商品名</label>
                <input type="text" x-model="filters.itemName" @keydown.enter.prevent="search(1)"
                    placeholder="2文字以上"
                    class="w-full border border-gray-300 dark:border-gray-600 rounded px-2 py-1 text-xs bg-white dark:bg-gray-900 text-gray-900 dark:text-white" />
            </div>
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-0.5">発注先</label>
                <select x-model="filters.contractorId"
                    class="w-full border border-gray-300 dark:border-gray-600 rounded px-2 py-1 text-xs bg-white dark:bg-gray-900 text-gray-900 dark:text-white">
                    <option value="">全て</option>
                    @foreach(\App\Models\Sakemaru\Contractor::orderBy('code')->get() as $contractor)
                        <option value="{{ $contractor->id }}">[{{ $contractor->code }}]{{ $contractor->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-0.5">大分類</label>
                <select x-model="filters.category1Id" @change="loadCategories2()"
                    class="w-full border border-gray-300 dark:border-gray-600 rounded px-2 py-1 text-xs bg-white dark:bg-gray-900 text-gray-900 dark:text-white">
                    <option value="">全て</option>
                    @foreach(\App\Models\Sakemaru\ItemCategory::where('depth', 1)->where('is_active', true)->orderBy('code')->get() as $cat)
                        <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <div class="grid grid-cols-5 gap-2">
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-0.5">中分類</label>
                <select x-model="filters.category2Id" @change="loadCategories3()"
                    class="w-full border border-gray-300 dark:border-gray-600 rounded px-2 py-1 text-xs bg-white dark:bg-gray-900 text-gray-900 dark:text-white">
                    <option value="">全て</option>
                    <template x-for="cat in categories2" :key="cat.id">
                        <option :value="cat.id" x-text="cat.name"></option>
                    </template>
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-0.5">小分類</label>
                <select x-model="filters.category3Id"
                    class="w-full border border-gray-300 dark:border-gray-600 rounded px-2 py-1 text-xs bg-white dark:bg-gray-900 text-gray-900 dark:text-white">
                    <option value="">全て</option>
                    <template x-for="cat in categories3" :key="cat.id">
                        <option :value="cat.id" x-text="cat.name"></option>
                    </template>
                </select>
            </div>
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-0.5">最終出荷日(から)</label>
                <input type="date" x-model="filters.lastShippedFrom"
                    class="w-full border border-gray-300 dark:border-gray-600 rounded px-2 py-1 text-xs bg-white dark:bg-gray-900 text-gray-900 dark:text-white" />
            </div>
            <div>
                <label class="block text-xs text-gray-500 dark:text-gray-400 mb-0.5">最終出荷日(まで)</label>
                <input type="date" x-model="filters.lastShippedTo"
                    class="w-full border border-gray-300 dark:border-gray-600 rounded px-2 py-1 text-xs bg-white dark:bg-gray-900 text-gray-900 dark:text-white" />
            </div>
            <div class="flex items-end gap-1">
                <button type="button" @click="search(1)"
                    class="px-3 py-1 bg-primary-600 text-white rounded text-xs font-medium hover:bg-primary-700">
                    検索
                </button>
                <button type="button" @click="resetFilters()"
                    class="px-3 py-1 bg-gray-200 dark:bg-gray-700 text-gray-700 dark:text-gray-300 rounded text-xs font-medium hover:bg-gray-300 dark:hover:bg-gray-600">
                    リセット
                </button>
            </div>
        </div>
    </div>

    {{-- ローディング --}}
    <div x-show="loading" class="flex items-center justify-center py-6">
        <svg class="animate-spin h-5 w-5 text-primary-500 mr-2" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
        </svg>
        <span class="text-sm text-gray-500">検索中...</span>
    </div>

    {{-- 検索結果テーブル --}}
    <div x-show="searched && !loading" x-cloak>
        <div class="flex items-center justify-between mb-1">
            <div class="text-xs text-gray-500 dark:text-gray-400">
                検索結果: <span class="font-bold" x-text="totalCount"></span>件
                <span x-show="validCount > 0" class="ml-2 text-primary-600 dark:text-primary-400 font-bold">
                    （<span x-text="validCount"></span>件入力済み）
                </span>
            </div>
        </div>

        <div class="border border-gray-200 dark:border-white/10 rounded-lg overflow-auto" style="max-height: 320px">
            <table class="w-full min-w-[1280px] text-sm table-fixed">
                <colgroup>
                    <col style="width: 70px" />
                    <col />
                    <col style="width: 60px" />
                    <col style="width: 38px" />
                    <col style="width: 150px" />
                    <col style="width: 65px" />
                    <col style="width: 40px" />
                    <col style="width: 40px" />
                    <col style="width: 48px" />
                    <col style="width: 40px" />
                    <col style="width: 40px" />
                    <col style="width: 40px" />
                    <col style="width: 55px" />
                    <col style="width: 55px" />
                    <col style="width: 50px" />
                </colgroup>
                <thead class="sticky top-0 z-10 bg-gray-100 dark:bg-white/10">
                    <tr class="divide-x divide-gray-200 dark:divide-white/10">
                        <th class="px-1.5 py-1 text-left text-xs font-medium text-gray-500 dark:text-gray-400">商品CD</th>
                        <th class="px-1.5 py-1 text-left text-xs font-medium text-gray-500 dark:text-gray-400">商品名</th>
                        <th class="px-1.5 py-1 text-left text-xs font-medium text-gray-500 dark:text-gray-400">規格</th>
                        <th class="px-1.5 py-1 text-right text-xs font-medium text-gray-500 dark:text-gray-400">入数</th>
                        <th class="px-1.5 py-1 text-left text-xs font-medium text-gray-500 dark:text-gray-400">発注先</th>
                        <th class="px-1.5 py-1 text-center text-xs font-medium text-gray-500 dark:text-gray-400">最終出荷</th>
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
                        <tr :class="String(item.contractor_code) === '9012'
                                ? 'bg-red-50 dark:bg-red-950/30'
                                : (String(item.id) in pinnedItems)
                                    ? 'bg-green-50 dark:bg-green-950/30'
                                    : (index % 2 === 0 ? 'bg-white dark:bg-gray-900' : 'bg-blue-50/30 dark:bg-blue-950/20')"
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
                                <span x-show="String(item.contractor_code) === '9012'" class="text-[10px] font-bold text-red-600 dark:text-red-400">移動発注対象です</span>
                                <span class="text-xs whitespace-normal break-words"
                                    :class="String(item.contractor_code) === '9012' ? 'text-red-600 dark:text-red-400 font-bold' : 'text-gray-500 dark:text-gray-400'"
                                    x-text="item.contractor_name || '-'"></span>
                            </td>
                            <td class="px-1.5 py-0.5 text-center">
                                <span class="text-xs text-gray-500 dark:text-gray-400" x-text="item.last_shipped_at || '-'"></span>
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
                                    @keydown.enter.prevent
                                    min="0"
                                    placeholder=""
                                    class="w-full border border-gray-300 dark:border-gray-600 rounded px-1 py-0.5 text-xs text-right font-mono bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-1 focus:ring-primary-500 focus:border-primary-500"
                                />
                            </td>
                            <td class="px-0.5 py-0.5">
                                <input type="number"
                                    :value="getQty(item.id).pieceQty"
                                    @input="getQty(item.id).pieceQty = $event.target.value ? parseInt($event.target.value) : null; onQtyChange()"
                                    @keydown.enter.prevent
                                    min="0"
                                    placeholder=""
                                    class="w-full border border-gray-300 dark:border-gray-600 rounded px-1 py-0.5 text-xs text-right font-mono bg-white dark:bg-gray-800 text-gray-900 dark:text-white focus:ring-1 focus:ring-primary-500 focus:border-primary-500"
                                />
                            </td>
                            <td class="px-1 py-0.5 text-right">
                                <span class="text-xs font-mono font-semibold"
                                    :class="((getQty(item.id).caseQty || 0) * (item.capacity_case || 1) + (getQty(item.id).pieceQty || 0)) > 0 ? 'text-primary-600 dark:text-primary-400' : 'text-gray-400'"
                                    x-text="(getQty(item.id).caseQty || 0) * (item.capacity_case || 1) + (getQty(item.id).pieceQty || 0) || ''"></span>
                            </td>
                        </tr>
                    </template>
                    <template x-if="results.length === 0">
                        <tr>
                            <td colspan="12" class="px-4 py-6 text-center text-sm text-gray-400 dark:text-gray-500">
                                検索結果がありません
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        {{-- ページネーション --}}
        <div x-show="lastPage > 1" class="flex items-center justify-center gap-1 mt-2">
            <button type="button" @click="search(currentPage - 1)" :disabled="currentPage <= 1"
                class="px-2 py-0.5 text-xs rounded border border-gray-300 dark:border-gray-600 disabled:opacity-40 hover:bg-gray-100 dark:hover:bg-gray-700">
                &lt;
            </button>
            <template x-for="p in lastPage" :key="p">
                <button type="button" @click="search(p)"
                    :class="p === currentPage ? 'bg-primary-600 text-white border-primary-600' : 'border-gray-300 dark:border-gray-600 hover:bg-gray-100 dark:hover:bg-gray-700'"
                    class="px-2 py-0.5 text-xs rounded border" x-text="p"
                    x-show="Math.abs(p - currentPage) <= 2 || p === 1 || p === lastPage">
                </button>
            </template>
            <button type="button" @click="search(currentPage + 1)" :disabled="currentPage >= lastPage"
                class="px-2 py-0.5 text-xs rounded border border-gray-300 dark:border-gray-600 disabled:opacity-40 hover:bg-gray-100 dark:hover:bg-gray-700">
                &gt;
            </button>
        </div>
    </div>

    {{-- 未検索時 --}}
    <div x-show="!searched && !loading" class="flex items-center justify-center py-8">
        <div class="text-center text-sm text-gray-400 dark:text-gray-500">
            <svg class="mx-auto h-8 w-8 mb-2 text-gray-300 dark:text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/>
            </svg>
            検索条件を入力して「検索」ボタンを押してください
        </div>
    </div>

    {{-- フッター情報 --}}
    <div class="flex items-center justify-end">
        <div class="text-xs text-gray-500 dark:text-gray-400">
            <span x-text="validCount"></span>件の商品を追加予定
        </div>
    </div>
</div>
