<div class="flex flex-col h-full" @keydown.window="handleBarcodeKeyDown($event)">
    {{-- Sub Header --}}
    <div class="p-3 bg-white border-b border-gray-200 flex items-center gap-2">
        <button @click="backFromPickingItem()" class="wms-touch-target flex items-center text-gray-500">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
            </svg>
        </button>
        <h2 class="text-sm font-bold text-gray-800">ピッキング</h2>
        <span class="ml-auto text-xs text-gray-500 font-medium" x-text="picking.progressText"></span>
    </div>

    {{-- 2-Column Landscape Layout --}}
    <div class="flex-1 overflow-y-auto p-3">
        <div class="flex gap-3 h-full">
            {{-- Left Column: Product Info --}}
            <div class="w-1/3 flex-shrink-0">
                <div class="wms-card p-3 h-full">
                    {{-- Product Image --}}
                    <div class="w-full aspect-square bg-gray-100 rounded-lg overflow-hidden flex items-center justify-center mb-3">
                        <template x-if="picking.currentItem?.images?.length > 0">
                            <img :src="picking.currentItem.images[0]" class="w-full h-full object-cover" alt="">
                        </template>
                        <template x-if="!picking.currentItem?.images?.length">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-12 h-12 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                            </svg>
                        </template>
                    </div>
                    <div class="text-sm font-medium text-gray-800" x-text="picking.currentItem?.item_name || '-'"></div>
                    <template x-if="picking.currentItem?.jan_code">
                        <div class="text-xs text-gray-400 mt-1" x-text="'JAN: ' + picking.currentItem.jan_code"></div>
                    </template>
                    <template x-if="picking.currentItem?.volume">
                        <div class="text-xs text-gray-400" x-text="picking.currentItem.volume"></div>
                    </template>

                    {{-- Slip & Destination --}}
                    <div class="mt-3 pt-3 border-t border-gray-200 space-y-1 text-xs">
                        <template x-if="picking.currentItem?.slip_number">
                            <div class="flex justify-between">
                                <span class="text-gray-500">伝票No</span>
                                <span class="font-medium" x-text="picking.currentItem.slip_number"></span>
                            </div>
                        </template>
                        <template x-if="picking.currentItem?.destination_warehouse">
                            <div class="flex justify-between">
                                <span class="text-gray-500">配送先</span>
                                <span class="font-medium" x-text="picking.currentItem.destination_warehouse"></span>
                            </div>
                        </template>
                        <div class="flex justify-between items-center">
                            <span class="text-gray-500">予定数</span>
                            <span class="font-bold text-lg">
                                <span x-text="picking.currentItem?.planned_qty || 0"></span>
                                <span class="text-sm font-medium ml-0.5"
                                      x-text="picking.getQtyTypeLabel(picking.currentItem?.planned_qty_type)"
                                      :class="picking.currentItem?.planned_qty_type === 'CASE' ? 'text-blue-600' : 'text-gray-500'"></span>
                            </span>
                        </div>
                        <template x-if="picking.currentItem?.capacity_case">
                            <div class="flex justify-between">
                                <span class="text-gray-500">入数</span>
                                <span class="font-medium" x-text="picking.currentItem.capacity_case + '入'"></span>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            {{-- Right Column: Input --}}
            <div class="flex-1 space-y-3">
                {{-- Barcode Scan Result --}}
                <div class="wms-card p-3">
                    <label class="block text-xs font-medium text-gray-600 mb-1">バーコードスキャン</label>
                    <div class="flex items-center gap-2">
                        <input
                            type="text"
                            class="wms-input text-sm"
                            placeholder="スキャンまたは手入力"
                            x-model="picking.scannedBarcode"
                            @change="picking.checkBarcode(picking.scannedBarcode)"
                            inputmode="numeric"
                        >
                    </div>
                    <template x-if="picking.barcodeMatch === 'match'">
                        <div class="mt-1 text-xs text-green-600 flex items-center gap-1">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                            </svg>
                            JAN一致
                        </div>
                    </template>
                    <template x-if="picking.barcodeMatch === 'mismatch'">
                        <div class="mt-1 text-xs text-red-600 flex items-center gap-1">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                            JAN不一致
                        </div>
                    </template>
                </div>

                {{-- Quantity Input --}}
                <div class="wms-card p-3">
                    <div class="flex items-center justify-between mb-1">
                        <label class="block text-xs font-medium text-gray-600">ピッキング数量</label>
                        <span class="text-xs font-bold px-2 py-0.5 rounded"
                              :class="picking.currentItem?.planned_qty_type === 'CASE' ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-600'"
                              x-text="picking.getQtyTypeLabel(picking.currentItem?.planned_qty_type)"></span>
                    </div>
                    <div class="flex items-center gap-2">
                        <button
                            class="wms-btn bg-gray-200 text-gray-700 text-lg px-4"
                            style="min-height: 48px;"
                            @click="picking.decrementQty()"
                        >−</button>
                        <input
                            type="number"
                            class="wms-input text-center text-lg font-bold"
                            style="min-height: 48px;"
                            x-model.number="picking.pickedQty"
                            min="0"
                            inputmode="numeric"
                        >
                        <button
                            class="wms-btn bg-gray-200 text-gray-700 text-lg px-4"
                            style="min-height: 48px;"
                            @click="picking.incrementQty()"
                        >+</button>
                    </div>
                </div>

                {{-- Action Buttons --}}
                <div class="flex gap-2">
                    <button
                        class="wms-btn wms-btn-danger flex-1 text-sm"
                        @click="markPickingShortage()"
                        :disabled="isLoading"
                    >
                        欠品
                    </button>
                    <button
                        class="wms-btn bg-gray-200 text-gray-700 flex-1 text-sm"
                        @click="skipPickingItem()"
                    >
                        スキップ
                    </button>
                    <button
                        class="wms-btn wms-btn-success flex-1 text-sm"
                        style="min-height: 48px;"
                        @click="submitPickingItem()"
                        :disabled="isLoading"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                        </svg>
                        確定
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
