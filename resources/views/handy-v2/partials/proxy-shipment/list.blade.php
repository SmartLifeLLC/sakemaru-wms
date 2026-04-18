<div class="flex flex-col h-full">
    {{-- Sub Header --}}
    <div class="p-3 bg-white border-b border-gray-200">
        <div class="flex items-center justify-between mb-2">
            <h2 class="text-sm font-bold text-gray-800">横持ち出荷</h2>
            <button
                class="wms-btn text-xs px-3 bg-gray-100 text-gray-700 border border-gray-300"
                style="min-height: 32px;"
                @click="loadProxyShipments()"
            >
                更新
            </button>
        </div>

        {{-- Filters --}}
        <div class="flex gap-2">
            <input
                type="date"
                class="wms-input text-xs flex-1"
                x-model="proxyShipment.shipmentDateFilter"
                @change="loadProxyShipments()"
            />
            <select
                class="wms-input text-xs flex-1"
                x-model="proxyShipment.deliveryCourseFilter"
                @change="loadProxyShipments()"
            >
                <option value="">全配送コース</option>
                <template x-for="course in (proxyShipment.summary?.by_delivery_course || [])" :key="course.id">
                    <option :value="course.id" x-text="`${course.name} (${course.count})`"></option>
                </template>
            </select>
        </div>

        {{-- Summary --}}
        <template x-if="proxyShipment.summary">
            <div class="mt-2 text-xs text-gray-500">
                <span x-text="proxyShipment.summary.total_count + '件'"></span>
            </div>
        </template>
    </div>

    {{-- Allocation List --}}
    <div class="flex-1 overflow-y-auto p-3 space-y-2">
        <template x-if="proxyShipment.allocations.length === 0 && !proxyShipment.isSearching">
            <div class="text-center text-gray-400 py-8 text-sm">
                横持ち出荷データがありません
            </div>
        </template>

        <template x-for="alloc in proxyShipment.allocations" :key="alloc.allocation_id">
            <div
                class="wms-card p-3 cursor-pointer active:bg-gray-50"
                @click="openProxyShipmentItem(alloc)"
            >
                {{-- Status Badge + Shipment Date --}}
                <div class="flex items-center justify-between mb-1">
                    <span
                        class="text-xs px-2 py-0.5 rounded-full font-medium"
                        :class="{
                            'bg-blue-100 text-blue-700': alloc.status === 'RESERVED',
                            'bg-orange-100 text-orange-700': alloc.status === 'PICKING',
                        }"
                        x-text="alloc.status === 'RESERVED' ? '未着手' : 'ピッキング中'"
                    ></span>
                    <span class="text-xs text-gray-400" x-text="alloc.shipment_date"></span>
                </div>

                {{-- Delivery Course --}}
                <div class="text-xs text-gray-500 mb-1">
                    <span x-text="alloc.delivery_course?.name || '-'"></span>
                </div>

                {{-- Customer --}}
                <div class="text-xs text-gray-600 mb-1" x-show="alloc.customer">
                    <span class="text-gray-400">得意先:</span>
                    <span x-text="alloc.customer?.name || '-'"></span>
                </div>

                {{-- Item --}}
                <div class="flex items-center gap-2 mb-1">
                    <span class="text-xs text-gray-400 flex-shrink-0" x-text="alloc.item?.code || ''"></span>
                    <span class="text-sm font-medium text-gray-800 truncate" x-text="alloc.item?.name || ''"></span>
                </div>

                {{-- Quantities --}}
                <div class="flex items-center gap-3 text-xs">
                    <span class="text-gray-500">
                        指示: <span class="font-medium" x-text="alloc.assign_qty"></span>
                    </span>
                    <span class="text-gray-500">
                        実績: <span class="font-medium" :class="alloc.picked_qty > 0 ? 'text-green-600' : ''" x-text="alloc.picked_qty"></span>
                    </span>
                    <span class="text-gray-500">
                        残: <span class="font-medium" :class="alloc.remaining_qty > 0 ? 'text-orange-600' : 'text-green-600'" x-text="alloc.remaining_qty"></span>
                    </span>
                </div>

                {{-- Warehouse Info --}}
                <div class="flex items-center gap-1 mt-1 text-xs text-gray-400">
                    <span x-text="alloc.pickup_warehouse?.name || ''"></span>
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                    </svg>
                    <span x-text="alloc.destination_warehouse?.name || ''"></span>
                </div>
            </div>
        </template>
    </div>
</div>
