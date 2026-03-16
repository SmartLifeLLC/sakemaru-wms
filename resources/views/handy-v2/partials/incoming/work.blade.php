<div class="flex flex-col h-full">
    {{-- Sub Header --}}
    <div class="p-3 bg-white border-b border-gray-200 flex items-center gap-2">
        <button @click="backFromIncomingWork()" class="wms-touch-target flex items-center text-gray-500">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
            </svg>
        </button>
        <h2 class="text-sm font-bold text-gray-800" x-text="incoming.isEditing ? '入荷編集' : '入荷作業'"></h2>
    </div>

    {{-- 2-Column Landscape Layout --}}
    <div class="flex-1 overflow-y-auto p-3">
        <div class="flex gap-3 h-full">
            {{-- Left Column: Product Info --}}
            <div class="w-1/3 flex-shrink-0">
                <div class="wms-card p-3 h-full">
                    {{-- Product Image --}}
                    <div class="w-full aspect-square bg-gray-100 rounded-lg overflow-hidden flex items-center justify-center mb-3">
                        <template x-if="incoming.currentScheduleItem?.images?.length > 0">
                            <img :src="incoming.currentScheduleItem.images[0]" class="w-full h-full object-cover" alt="">
                        </template>
                        <template x-if="!incoming.currentScheduleItem?.images?.length">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-12 h-12 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                            </svg>
                        </template>
                    </div>
                    <div class="text-xs text-gray-500" x-text="incoming.currentScheduleItem?.item_code"></div>
                    <div class="text-sm font-medium text-gray-800" x-text="incoming.currentScheduleItem?.item_name"></div>
                    <template x-if="incoming.currentScheduleItem?.jan_codes?.length > 0">
                        <div class="text-xs text-gray-400 mt-1" x-text="'JAN: ' + incoming.currentScheduleItem.jan_codes[0]"></div>
                    </template>

                    {{-- Schedule Info --}}
                    <div class="mt-3 pt-3 border-t border-gray-200 space-y-1 text-xs">
                        <div class="flex justify-between">
                            <span class="text-gray-500">予定数</span>
                            <span class="font-medium" x-text="incoming.workItem?.schedule?.expected_quantity || '-'"></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500">入荷済</span>
                            <span class="font-medium" x-text="incoming.workItem?.schedule?.received_quantity || 0"></span>
                        </div>
                        <div class="flex justify-between">
                            <span class="text-gray-500">残数</span>
                            <span class="font-medium text-orange-600" x-text="incoming.workItem?.schedule?.remaining_quantity || '-'"></span>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Right Column: Input Form --}}
            <div class="flex-1 space-y-3">
                {{-- Arrival Date --}}
                <div class="wms-card p-3">
                    <label class="block text-xs font-medium text-gray-600 mb-1">入荷日</label>
                    <input
                        type="date"
                        class="wms-input text-sm"
                        x-model="incoming.workForm.work_arrival_date"
                    >
                </div>

                {{-- Expiration Date --}}
                <div class="wms-card p-3">
                    <label class="block text-xs font-medium text-gray-600 mb-1">賞味期限</label>
                    <input
                        type="date"
                        class="wms-input text-sm"
                        x-model="incoming.workForm.work_expiration_date"
                    >
                </div>

                {{-- Location --}}
                <div class="wms-card p-3 relative">
                    <label class="block text-xs font-medium text-gray-600 mb-1">ロケーション</label>
                    <div class="relative">
                        <input
                            type="text"
                            class="wms-input text-sm pl-9"
                            placeholder="ロケーションを検索"
                            :value="incoming.locationSearch || incoming.selectedLocation?.display_name || ''"
                            @input="onLocationSearch($event.target.value)"
                            @focus="incoming.showLocationDropdown = true; onLocationSearch($event.target.value)"
                        >
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                            <path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                        </svg>
                    </div>
                    {{-- Location Dropdown --}}
                    <div x-show="incoming.showLocationDropdown && incoming.locations.length > 0"
                         @click.outside="incoming.showLocationDropdown = false"
                         class="absolute left-3 right-3 top-full mt-1 bg-white border border-gray-200 rounded-lg shadow-lg max-h-40 overflow-y-auto z-20"
                         style="display: none;">
                        <template x-for="loc in incoming.locations" :key="loc.id">
                            <button
                                class="w-full text-left px-3 py-2 text-sm hover:bg-blue-50 border-b border-gray-100 last:border-0"
                                @click="incoming.selectLocation(loc)"
                                x-text="loc.display_name"
                            ></button>
                        </template>
                    </div>
                    <template x-if="incoming.selectedLocation">
                        <div class="mt-1 text-xs text-green-600 flex items-center gap-1">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                            </svg>
                            <span x-text="incoming.selectedLocation.display_name"></span>
                        </div>
                    </template>
                </div>

                {{-- Quantity --}}
                <div class="wms-card p-3">
                    <label class="block text-xs font-medium text-gray-600 mb-1">入荷数量</label>
                    <div class="flex items-center gap-2">
                        <button
                            class="wms-btn bg-gray-200 text-gray-700 text-lg px-4"
                            style="min-height: 48px;"
                            @click="incoming.decrementQty()"
                        >−</button>
                        <input
                            type="number"
                            class="wms-input text-center text-lg font-bold"
                            style="min-height: 48px;"
                            x-model.number="incoming.workForm.work_quantity"
                            min="0"
                            inputmode="numeric"
                        >
                        <button
                            class="wms-btn bg-gray-200 text-gray-700 text-lg px-4"
                            style="min-height: 48px;"
                            @click="incoming.incrementQty()"
                        >+</button>
                    </div>
                </div>

                {{-- Submit Button --}}
                <button
                    class="wms-btn wms-btn-success w-full text-base"
                    style="min-height: 48px;"
                    @click="completeIncomingWork()"
                    :disabled="isLoading || !incoming.workForm.work_quantity || !incoming.workForm.location_id"
                >
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                    </svg>
                    入荷確定
                </button>
            </div>
        </div>
    </div>
</div>
