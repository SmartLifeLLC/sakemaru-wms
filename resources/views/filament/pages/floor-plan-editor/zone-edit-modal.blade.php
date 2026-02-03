{{-- Zone Edit Modal --}}
<div x-show="showEditModal" x-cloak
     class="fixed inset-0 flex items-center justify-center"
     style="z-index: 10000;"
     @click.self="showEditModal = false">
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl w-full max-w-6xl h-[800px] flex flex-col" @click.stop>

        {{-- Modal Header - 2 Columns (Navy background) --}}
        <div class="bg-[#1e3a5f] border-b border-gray-700 px-6 py-4 flex justify-between items-center rounded-t-lg flex-shrink-0">
            {{-- Column 1: Zone Info (Read-only labels) --}}
            <div class="flex items-center gap-6">
                {{-- Zone Code + Name --}}
                <div class="flex items-center gap-2">
                    <span class="text-2xl font-bold text-white" x-text="editingZone.code1 + editingZone.code2"></span>
                    <span class="text-base text-gray-300" x-text="editingZone.name ? '(' + editingZone.name + ')' : ''"></span>
                </div>
                {{-- Picking Area Badge --}}
                <template x-if="getPickingAreaForZone(editingZone)">
                    <div class="flex items-center gap-2 bg-white/20 text-white px-3 py-1 rounded border border-white/30">
                        <div class="w-3 h-3 rounded-full" :style="{ backgroundColor: getPickingAreaForZone(editingZone)?.color || '#8B5CF6' }"></div>
                        <span class="text-sm font-medium" x-text="getPickingAreaForZone(editingZone)?.name"></span>
                    </div>
                </template>
                {{-- 通路 (Label) --}}
                <div class="flex items-center gap-1">
                    <span class="text-xs text-gray-400">通路:</span>
                    <span class="text-sm font-medium text-white" x-text="editingZone.code1 || '-'"></span>
                </div>
                {{-- 棚番号 (Label) --}}
                <div class="flex items-center gap-1">
                    <span class="text-xs text-gray-400">棚番号:</span>
                    <span class="text-sm font-medium text-white" x-text="editingZone.code2 || '-'"></span>
                </div>
            </div>

            {{-- Column 2: Settings (Editable) --}}
            <div class="flex items-center gap-4">
                {{-- 温度帯 --}}
                <div class="flex items-center gap-2">
                    <span class="text-xs text-gray-300">温度帯:</span>
                    <select x-model="editingZone.temperature_type"
                            class="rounded border border-gray-500 bg-white/10 text-white px-2 py-1 text-sm focus:border-blue-400 focus:ring-1 focus:ring-blue-400">
                        <option value="NORMAL" class="text-gray-900">常温</option>
                        <option value="CONSTANT" class="text-gray-900">定温</option>
                        <option value="CHILLED" class="text-gray-900">冷蔵</option>
                        <option value="FROZEN" class="text-gray-900">冷凍</option>
                    </select>
                </div>
                {{-- 引当可能単位 --}}
                <div class="flex items-center gap-2">
                    <span class="text-xs text-gray-300">引当可能単位:</span>
                    <select x-model.number="editingZone.available_quantity_flags"
                            class="rounded border border-gray-500 bg-white/10 text-white px-2 py-1 text-sm focus:border-blue-400 focus:ring-1 focus:ring-blue-400">
                        <option value="1" class="text-gray-900">ケース</option>
                        <option value="2" class="text-gray-900">バラ</option>
                        <option value="3" class="text-gray-900">ケース+バラ</option>
                        <option value="4" class="text-gray-900">ボール</option>
                    </select>
                </div>
                {{-- 制限エリア Toggle --}}
                <div class="flex items-center gap-2">
                    <span class="text-xs text-gray-300">制限エリア:</span>
                    <label class="relative inline-flex items-center cursor-pointer">
                        <input type="checkbox" x-model="editingZone.is_restricted_area" class="sr-only peer">
                        <div class="w-9 h-5 bg-gray-500 peer-focus:outline-none peer-focus:ring-2 peer-focus:ring-red-300 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-4 after:w-4 after:transition-all peer-checked:bg-red-500"></div>
                    </label>
                </div>
                {{-- Save Button --}}
                <button @click="saveEditedZone()"
                    class="px-4 py-1.5 bg-green-500 hover:bg-green-600 text-white rounded font-medium text-sm">
                    保存
                </button>
            </div>
        </div>

        {{-- Stock Table Section --}}
        <div class="flex-1 overflow-hidden flex flex-col px-6 py-4">
            {{-- Table Header with search and actions --}}
            <div class="flex justify-between items-center mb-3 flex-shrink-0">
                {{-- Left side: Title, Count, Transfer Button --}}
                <div class="flex items-center gap-4">
                    <h3 class="font-bold text-gray-700 dark:text-gray-300">在庫リスト</h3>
                    <span class="text-sm text-gray-500" x-text="'該当 ' + filteredItems.length + '件'"></span>
                    {{-- Transfer Button --}}
                    <button @click="openTransferModal()"
                            :disabled="selectedStocksForTransfer.length === 0"
                            class="px-3 py-1.5 text-sm bg-blue-600 hover:bg-blue-700 disabled:bg-gray-300 disabled:cursor-not-allowed text-white rounded flex items-center gap-1 font-medium">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4m0 6H4m0 0l4 4m-4-4l4-4"></path>
                        </svg>
                        ロケ移動
                        <span x-show="selectedStocksForTransfer.length > 0" x-text="'(' + selectedStocksForTransfer.length + ')'"></span>
                    </button>
                </div>
                {{-- Right side: Search --}}
                <div class="flex items-center gap-2">
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                            <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                            </svg>
                        </div>
                        <input type="text"
                               x-model="stockSearchQuery"
                               @input.debounce.300ms="updateFilteredItems()"
                               class="w-48 pl-9 pr-8 py-1.5 text-sm border border-gray-300 dark:border-gray-600 rounded bg-gray-50 dark:bg-gray-800 focus:ring-blue-500 focus:border-blue-500"
                               placeholder="商品コード / 商品名">
                        {{-- Clear button --}}
                        <button x-show="stockSearchQuery"
                                @click="stockSearchQuery = ''; updateFilteredItems()"
                                class="absolute inset-y-0 right-0 flex items-center pr-2 text-gray-400 hover:text-gray-600">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                    </div>
                </div>
            </div>

            {{-- Stock Table --}}
            <div class="flex-1 overflow-auto border border-gray-200 dark:border-gray-700 rounded-lg">
                <table class="w-full text-sm text-left whitespace-nowrap">
                    <thead class="text-xs text-gray-500 dark:text-gray-400 uppercase bg-gray-100 dark:bg-gray-700 border-b sticky top-0 z-10">
                        <tr>
                            <th class="px-3 py-2.5 w-10 bg-gray-100 dark:bg-gray-700">
                                <input type="checkbox"
                                       @change="toggleAllStocks($event.target.checked)"
                                       :checked="filteredItems.length > 0 && selectedStocksForTransfer.length === filteredItems.length"
                                       class="rounded border-gray-300 dark:border-gray-500">
                            </th>
                            <th class="px-3 py-2.5 w-12 bg-gray-100 dark:bg-gray-700">No</th>
                            <th class="px-3 py-2.5 font-bold text-gray-700 dark:text-gray-300 bg-yellow-50/80 dark:bg-yellow-900/20 border-l border-gray-200 dark:border-gray-600">棚番</th>
                            <th class="px-3 py-2.5 border-l border-gray-200 dark:border-gray-600">商品コード</th>
                            <th class="px-3 py-2.5">商品名</th>
                            <th class="px-3 py-2.5 text-right">入り数</th>
                            <th class="px-3 py-2.5 text-right">容量</th>
                            <th class="px-3 py-2.5 text-center">単位</th>
                            <th class="px-3 py-2.5 text-center">賞味期限</th>
                            <th class="px-3 py-2.5 text-right font-bold bg-blue-50/50 dark:bg-blue-900/20">総バラ数</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                        <template x-for="(item, index) in filteredItems" :key="item.real_stock_id">
                            <tr class="hover:bg-blue-50 dark:hover:bg-blue-900/20 transition-colors"
                                :class="isStockSelected(item.real_stock_id) ? 'bg-blue-100 dark:bg-blue-900/40' : ''">
                                <td class="px-3 py-2.5">
                                    <input type="checkbox"
                                           :value="item.real_stock_id"
                                           :checked="isStockSelected(item.real_stock_id)"
                                           @change="toggleStockSelection(item)"
                                           class="rounded border-gray-300 dark:border-gray-500">
                                </td>
                                <td class="px-3 py-2.5 font-medium text-gray-500" x-text="index + 1"></td>
                                <td class="px-3 py-2.5 font-mono font-bold text-gray-900 dark:text-gray-100 bg-yellow-50/30 dark:bg-yellow-900/10 border-l border-gray-200 dark:border-gray-600" x-text="item.shelf_name"></td>
                                <td class="px-3 py-2.5 font-mono border-l border-gray-200 dark:border-gray-600" x-text="item.item_code || '-'"></td>
                                <td class="px-3 py-2.5 max-w-xs truncate" :title="item.item_name" x-text="item.item_name"></td>
                                <td class="px-3 py-2.5 text-right" x-text="item.capacity_case || '-'"></td>
                                <td class="px-3 py-2.5 text-right" x-text="item.volume ? (item.volume + (item.volume_unit_name || '')) : '-'"></td>
                                <td class="px-3 py-2.5 text-center" x-text="item.volume_unit_name || '-'"></td>
                                <td class="px-3 py-2.5 text-center"
                                    :class="isExpirationNear(item.expiration_date) ? 'text-red-600 font-bold' : 'text-gray-500'"
                                    x-text="item.expiration_date || '-'"></td>
                                <td class="px-3 py-2.5 text-right font-bold text-lg bg-blue-50/30 dark:bg-blue-900/10" x-text="item.total_qty"></td>
                            </tr>
                        </template>
                        <tr x-show="filteredItems.length === 0">
                            <td colspan="10" class="text-center py-8 text-gray-500 dark:text-gray-400">
                                <div class="flex flex-col items-center gap-2">
                                    <svg class="w-12 h-12 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"></path>
                                    </svg>
                                    <span x-text="stockSearchQuery ? '検索結果がありません' : '在庫がありません'"></span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        {{-- Footer with Close Button --}}
        <div class="border-t border-gray-200 dark:border-gray-700 px-6 py-3 flex justify-end flex-shrink-0">
            <button @click="showEditModal = false"
                class="px-4 py-1.5 bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-300 rounded font-medium text-sm">
                閉じる
            </button>
        </div>
    </div>
</div>
