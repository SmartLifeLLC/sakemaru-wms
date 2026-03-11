{{-- Zone Edit Modal --}}
<x-modal.container size="6xl" alpine-var="showEditModal" z-index="100">
    {{-- Modal Header --}}
    <div class="flex items-center justify-between px-4 py-3 border-b border-slate-200 dark:border-gray-700 bg-slate-50 dark:bg-gray-900 rounded-t-lg">
        <div class="flex items-center gap-4 flex-wrap">
            {{-- Zone Code + Name --}}
            <h3 class="flex items-center gap-2 text-sm font-bold text-slate-700 dark:text-gray-200">
                <i class="fa fa-map-marker-alt"></i>
                <span x-text="editingZone.code1 + editingZone.code2"></span>
                <span class="text-slate-500 dark:text-gray-400 font-normal" x-text="editingZone.name ? '(' + editingZone.name + ')' : ''"></span>
            </h3>
            {{-- Picking Area Badge --}}
            <template x-if="getPickingAreaForZone(editingZone)">
                <div class="flex items-center gap-2 bg-blue-50 dark:bg-blue-900/30 text-blue-700 dark:text-blue-400 px-2.5 py-1 rounded border border-blue-200 dark:border-blue-700">
                    <div class="w-3 h-3 rounded-full" :style="{ backgroundColor: getPickingAreaForZone(editingZone)?.color || '#8B5CF6' }"></div>
                    <span class="text-xs font-medium" x-text="getPickingAreaForZone(editingZone)?.name"></span>
                </div>
            </template>
            {{-- 通路 --}}
            <div class="flex items-center gap-1">
                <span class="text-xs text-slate-400 dark:text-gray-500">通路:</span>
                <span class="text-sm font-medium text-slate-700 dark:text-gray-200" x-text="editingZone.code1 || '-'"></span>
            </div>
            {{-- 棚番号 --}}
            <div class="flex items-center gap-1">
                <span class="text-xs text-slate-400 dark:text-gray-500">棚番号:</span>
                <span class="text-sm font-medium text-slate-700 dark:text-gray-200" x-text="editingZone.code2 || '-'"></span>
            </div>
        </div>

        <div class="flex items-center gap-3">
            {{-- 温度帯 --}}
            <div class="flex items-center gap-1.5">
                <span class="text-xs text-slate-500 dark:text-gray-400">温度帯:</span>
                <select x-model="editingZone.temperature_type"
                        class="rounded-lg border border-slate-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 px-2 py-1 text-xs focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="NORMAL">常温</option>
                    <option value="CONSTANT">定温</option>
                    <option value="CHILLED">冷蔵</option>
                    <option value="FROZEN">冷凍</option>
                </select>
            </div>
            {{-- 引当可能単位 --}}
            <div class="flex items-center gap-1.5">
                <span class="text-xs text-slate-500 dark:text-gray-400">引当可能単位:</span>
                <select x-model.number="editingZone.available_quantity_flags"
                        class="rounded-lg border border-slate-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 px-2 py-1 text-xs focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="1">ケース</option>
                    <option value="2">バラ</option>
                    <option value="3">ケース+バラ</option>
                    <option value="4">ボール</option>
                </select>
            </div>
            {{-- 制限エリア Toggle --}}
            <div class="flex items-center gap-1.5">
                <span class="text-xs text-slate-500 dark:text-gray-400">制限エリア:</span>
                <label class="relative inline-flex items-center cursor-pointer">
                    <input type="checkbox" x-model="editingZone.is_restricted_area" class="sr-only peer">
                    <div class="w-9 h-5 bg-slate-300 dark:bg-gray-600 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-red-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-red-500"></div>
                </label>
            </div>
            {{-- Save Button --}}
            <button @click="saveEditedZone()"
                class="px-3 py-1.5 text-xs font-medium bg-blue-600 hover:bg-blue-700 text-white rounded">
                <i class="fa fa-save mr-1"></i> 保存
            </button>
            {{-- Close Button --}}
            <button @click="showEditModal = false" class="text-slate-400 dark:text-gray-500 hover:text-slate-600 dark:hover:text-gray-300">
                <i class="fa fa-times"></i>
            </button>
        </div>
    </div>

    {{-- Stock Table Section --}}
    <div class="flex-1 overflow-hidden flex flex-col px-4 py-3" style="max-height: calc(80vh - 120px);">
        {{-- Table Header with search and actions --}}
        <div class="flex justify-between items-center mb-3 flex-shrink-0">
            {{-- Left side: Title, Count, Transfer Button --}}
            <div class="flex items-center gap-4">
                <h3 class="font-bold text-sm text-slate-700 dark:text-gray-300">在庫リスト</h3>
                <span class="text-xs text-slate-500 dark:text-gray-400" x-text="'該当 ' + filteredItems.length + '件'"></span>
                {{-- Transfer Button --}}
                <button @click="openTransferModal()"
                        :disabled="selectedStocksForTransfer.length === 0"
                        class="px-3 py-1.5 text-xs font-medium bg-blue-600 hover:bg-blue-700 disabled:bg-slate-200 dark:disabled:bg-gray-700 disabled:text-slate-400 disabled:cursor-not-allowed text-white rounded flex items-center gap-1">
                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path>
                    </svg>
                    ロケ移動
                    <span x-show="selectedStocksForTransfer.length > 0" x-text="'(' + selectedStocksForTransfer.length + ')'"></span>
                </button>
            </div>
            {{-- Right side: Search --}}
            <div class="relative">
                <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                    <svg class="w-4 h-4 text-slate-400 dark:text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                </div>
                <input type="text"
                       x-model="stockSearchQuery"
                       @input.debounce.300ms="updateFilteredItems()"
                       class="w-48 pl-9 pr-8 py-1.5 text-sm border border-slate-200 dark:border-gray-700 rounded-lg bg-white dark:bg-gray-800 dark:text-gray-200 focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                       placeholder="商品コード / 商品名">
                <button x-show="stockSearchQuery"
                        @click="stockSearchQuery = ''; updateFilteredItems()"
                        class="absolute inset-y-0 right-0 flex items-center pr-2 text-slate-400 hover:text-slate-600 dark:hover:text-gray-300">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
        </div>

        {{-- Stock Table --}}
        <div class="flex-1 overflow-auto border border-slate-200 dark:border-gray-700 rounded-lg">
            <table class="w-full text-sm text-left whitespace-nowrap">
                <thead class="text-xs text-slate-600 dark:text-gray-400 bg-slate-50 dark:bg-gray-900 border-b border-slate-200 dark:border-gray-700 sticky top-0 z-10">
                    <tr>
                        <th class="px-3 py-2.5 w-10 bg-slate-50 dark:bg-gray-900">
                            <input type="checkbox"
                                   @change="toggleAllStocks($event.target.checked)"
                                   :checked="filteredItems.length > 0 && selectedStocksForTransfer.length === filteredItems.length"
                                   class="rounded border-slate-300 dark:border-gray-600">
                        </th>
                        <th class="px-3 py-2.5 w-12 bg-slate-50 dark:bg-gray-900">No</th>
                        <th class="px-3 py-2.5 font-bold text-slate-700 dark:text-gray-300 bg-yellow-50/80 dark:bg-yellow-900/20 border-l border-slate-200 dark:border-gray-700">棚番</th>
                        <th class="px-3 py-2.5 border-l border-slate-200 dark:border-gray-700">商品コード</th>
                        <th class="px-3 py-2.5">商品名</th>
                        <th class="px-3 py-2.5 text-right">入り数</th>
                        <th class="px-3 py-2.5 text-right">容量</th>
                        <th class="px-3 py-2.5 text-center">単位</th>
                        <th class="px-3 py-2.5 text-center">賞味期限</th>
                        <th class="px-3 py-2.5 text-right font-bold bg-blue-50/50 dark:bg-blue-900/20">総バラ数</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-gray-700">
                    <template x-for="(item, index) in filteredItems" :key="item.real_stock_id">
                        <tr class="hover:bg-slate-50 dark:hover:bg-gray-700 transition-colors"
                            :class="isStockSelected(item.real_stock_id) ? 'bg-blue-50 dark:bg-blue-900/30' : ''">
                            <td class="px-3 py-2.5">
                                <input type="checkbox"
                                       :value="item.real_stock_id"
                                       :checked="isStockSelected(item.real_stock_id)"
                                       @change="toggleStockSelection(item)"
                                       class="rounded border-slate-300 dark:border-gray-600">
                            </td>
                            <td class="px-3 py-2.5 font-medium text-slate-500 dark:text-gray-400" x-text="index + 1"></td>
                            <td class="px-3 py-2.5 font-mono font-bold text-slate-800 dark:text-gray-100 bg-yellow-50/30 dark:bg-yellow-900/10 border-l border-slate-200 dark:border-gray-700" x-text="item.shelf_name"></td>
                            <td class="px-3 py-2.5 font-mono border-l border-slate-200 dark:border-gray-700" x-text="item.item_code || '-'"></td>
                            <td class="px-3 py-2.5 max-w-xs truncate" :title="item.item_name" x-text="item.item_name"></td>
                            <td class="px-3 py-2.5 text-right" x-text="item.capacity_case || '-'"></td>
                            <td class="px-3 py-2.5 text-right" x-text="item.volume ? (item.volume + (item.volume_unit_name || '')) : '-'"></td>
                            <td class="px-3 py-2.5 text-center" x-text="item.volume_unit_name || '-'"></td>
                            <td class="px-3 py-2.5 text-center"
                                :class="isExpired(item.expiration_date) ? 'text-red-600 bg-red-50 dark:bg-red-900/20 font-bold' :
                                        isExpirationNear(item.alert_date) ? 'text-amber-600 font-bold' : 'text-slate-500 dark:text-gray-400'"
                                x-text="item.expiration_date || '-'"></td>
                            <td class="px-3 py-2.5 text-right font-bold text-lg bg-blue-50/30 dark:bg-blue-900/10" x-text="item.total_qty"></td>
                        </tr>
                    </template>
                    <tr x-show="filteredItems.length === 0">
                        <td colspan="10" class="text-center py-8 text-slate-500 dark:text-gray-400">
                            <div class="flex flex-col items-center gap-2">
                                <i class="fa fa-inbox text-3xl text-slate-300 dark:text-gray-600"></i>
                                <span x-text="stockSearchQuery ? '検索結果がありません' : '在庫がありません'"></span>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    {{-- Footer --}}
    <x-modal.footer>
        <button @click="showEditModal = false"
            class="px-3 py-1.5 text-xs font-medium text-slate-600 dark:text-gray-400 bg-slate-100 dark:bg-gray-700 rounded hover:bg-slate-200 dark:hover:bg-gray-600">
            閉じる
        </button>
    </x-modal.footer>
</x-modal.container>
