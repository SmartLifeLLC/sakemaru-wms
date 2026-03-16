<div class="flex flex-col h-full">
    {{-- Sub Header --}}
    <div class="p-3 bg-white border-b border-gray-200 flex items-center gap-2">
        <h2 class="text-sm font-bold text-gray-800">ピッキング完了確認</h2>
    </div>

    {{-- Picked Items Summary --}}
    <div class="flex-1 overflow-y-auto p-3">
        <div class="wms-card p-3 mb-3">
            <h3 class="text-sm font-bold text-gray-700 mb-2">ピッキング結果</h3>
            <div class="flex gap-4 text-sm">
                <div>
                    <span class="text-gray-500">合計:</span>
                    <span class="font-bold" x-text="picking.pickedItems.length + '品'"></span>
                </div>
                <div>
                    <span class="text-gray-500">欠品:</span>
                    <span class="font-bold text-red-600"
                          x-text="picking.pickedItems.filter(i => i.isShortage).length + '品'"></span>
                </div>
            </div>
        </div>

        <div class="space-y-2">
            <template x-for="(item, idx) in picking.pickedItems" :key="idx">
                <div class="wms-card px-3 py-2 flex items-center gap-3"
                     :class="{ 'border-l-4 border-red-500': item.isShortage }">
                    {{-- Item Info --}}
                    <div class="flex-1 min-w-0">
                        <div class="text-sm font-medium text-gray-800 truncate" x-text="item.item_name"></div>
                        <div class="text-xs text-gray-500" x-text="item.jan_code || ''"></div>
                    </div>

                    {{-- Quantity --}}
                    <div class="flex-shrink-0 text-right">
                        <template x-if="item.isShortage">
                            <span class="text-sm font-bold text-red-600">欠品</span>
                        </template>
                        <template x-if="!item.isShortage">
                            <div>
                                <span class="text-sm font-bold" x-text="item.picked_qty"></span>
                                <span class="text-xs text-gray-400" x-text="' / ' + item.planned_qty"></span>
                                <span class="text-xs font-medium ml-0.5"
                                      :class="item.planned_qty_type === 'CASE' ? 'text-blue-600' : 'text-gray-400'"
                                      x-text="picking.getQtyTypeLabel(item.planned_qty_type)"></span>
                            </div>
                        </template>
                    </div>
                </div>
            </template>
        </div>

        {{-- Unprocessed items warning --}}
        <template x-if="picking.pickedItems.length < picking.totalItems">
            <div class="mt-3 p-3 bg-yellow-50 border border-yellow-200 rounded-lg text-sm text-yellow-700">
                <span x-text="(picking.totalItems - picking.pickedItems.length) + '品が未処理です。スキップされたアイテムは処理されません。'"></span>
            </div>
        </template>
    </div>

    {{-- Footer Actions --}}
    <div class="p-3 bg-white border-t border-gray-200 flex gap-2">
        <button
            class="wms-btn bg-gray-100 text-gray-700 flex-1"
            @click="backFromPickingItem()"
        >
            戻る
        </button>
        <button
            class="wms-btn wms-btn-success flex-1"
            @click="completePickingTask()"
            :disabled="isLoading"
        >
            タスク完了
        </button>
    </div>
</div>
