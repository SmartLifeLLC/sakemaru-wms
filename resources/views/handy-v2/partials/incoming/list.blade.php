<div class="flex flex-col h-full">
    {{-- Search Bar + History Button --}}
    <div class="p-3 bg-white border-b border-gray-200 flex gap-2 items-center">
        <div class="flex-1 relative">
            <input
                type="text"
                class="wms-input pl-9 text-sm"
                placeholder="商品コード・名前・JANで検索"
                :value="incoming.searchQuery"
                @input="onIncomingSearch($event.target.value)"
                inputmode="search"
            >
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
            </svg>
            <button
                x-show="incoming.searchQuery"
                @click="incoming.searchQuery = ''; loadIncomingSchedules()"
                class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400"
            >
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>
        <button
            class="wms-btn text-xs px-3 bg-gray-100 text-gray-700 border border-gray-300"
            style="min-height: 38px;"
            @click="showIncomingHistory()"
        >
            履歴
        </button>
    </div>

    {{-- Schedule List --}}
    <div class="flex-1 overflow-y-auto p-3 space-y-2">
        <template x-if="incoming.schedules.length === 0 && !incoming.isSearching">
            <div class="text-center text-gray-400 py-8 text-sm">
                入荷予定がありません
            </div>
        </template>

        <template x-for="item in incoming.schedules" :key="item.item_id">
            <div class="wms-card p-3 cursor-pointer active:bg-gray-50 transition-colors"
                 @click="startIncomingWork(item, item.schedules[0]?.id)">
                <div class="flex gap-3">
                    {{-- Product Image --}}
                    <div class="w-14 h-14 flex-shrink-0 bg-gray-100 rounded-lg overflow-hidden flex items-center justify-center">
                        <template x-if="item.images && item.images.length > 0">
                            <img :src="item.images[0]" class="w-full h-full object-cover" alt="">
                        </template>
                        <template x-if="!item.images || item.images.length === 0">
                            <svg xmlns="http://www.w3.org/2000/svg" class="w-6 h-6 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
                            </svg>
                        </template>
                    </div>

                    {{-- Product Info --}}
                    <div class="flex-1 min-w-0">
                        <div class="text-xs text-gray-500" x-text="item.item_code"></div>
                        <div class="text-sm font-medium text-gray-800 truncate" x-text="item.item_name"></div>
                        <div class="flex items-center gap-2 mt-1">
                            <span class="text-xs text-gray-400" x-text="(item.volume || '') + (item.volume_unit || '')"></span>
                            <template x-if="item.temperature_type">
                                <span class="text-xs px-1.5 py-0.5 rounded"
                                      :class="{
                                          'bg-blue-100 text-blue-700': item.temperature_type === '冷蔵',
                                          'bg-cyan-100 text-cyan-700': item.temperature_type === '冷凍',
                                          'bg-gray-100 text-gray-600': item.temperature_type === '常温',
                                      }"
                                      x-text="item.temperature_type"></span>
                            </template>
                        </div>
                    </div>

                    {{-- Quantity --}}
                    <div class="flex-shrink-0 text-right">
                        <div class="text-xs text-gray-500">残 / 予定</div>
                        <div class="text-sm font-bold"
                             :class="item.total_remaining_quantity > 0 ? 'text-orange-600' : 'text-green-600'">
                            <span x-text="item.total_remaining_quantity"></span>
                            <span class="text-gray-400 font-normal">/</span>
                            <span class="text-gray-600 font-normal" x-text="item.total_expected_quantity"></span>
                        </div>
                        {{-- Progress Bar --}}
                        <div class="w-16 h-1.5 bg-gray-200 rounded-full mt-1 ml-auto">
                            <div class="h-full rounded-full transition-all"
                                 :class="item.total_remaining_quantity > 0 ? 'bg-orange-500' : 'bg-green-500'"
                                 :style="'width: ' + Math.min(100, Math.round((item.total_received_quantity / Math.max(1, item.total_expected_quantity)) * 100)) + '%'"></div>
                        </div>
                    </div>
                </div>

                {{-- Warehouse badges (if multiple) --}}
                <template x-if="item.warehouses && item.warehouses.length > 1">
                    <div class="flex gap-1 mt-2 flex-wrap">
                        <template x-for="wh in item.warehouses" :key="wh.warehouse_id">
                            <span class="text-xs px-1.5 py-0.5 bg-gray-100 rounded text-gray-600"
                                  x-text="wh.warehouse_name + ' ' + wh.remaining_quantity + '/' + wh.expected_quantity"></span>
                        </template>
                    </div>
                </template>
            </div>
        </template>

        {{-- Infinite Scroll Sentinel --}}
        <div x-show="incoming.hasMore"
             x-intersect:enter="loadMoreSchedules()"
             class="py-4 text-center">
            <template x-if="incoming.isSearching">
                <div class="text-sm text-gray-400">読み込み中...</div>
            </template>
        </div>
    </div>
</div>
