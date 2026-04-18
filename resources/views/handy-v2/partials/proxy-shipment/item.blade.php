<div class="flex flex-col h-full">
    {{-- Sub Header --}}
    <div class="p-3 bg-white border-b border-gray-200 flex items-center gap-2">
        <button
            class="wms-btn text-xs px-2 bg-gray-100 text-gray-700 border border-gray-300"
            style="min-height: 32px;"
            @click="backFromProxyShipmentItem()"
        >
            <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
            </svg>
        </button>
        <h2 class="text-sm font-bold text-gray-800 flex-1">横持ちピッキング</h2>
        <span
            class="text-xs px-2 py-0.5 rounded-full font-medium"
            :class="{
                'bg-blue-100 text-blue-700': proxyShipment.currentAllocation?.status === 'RESERVED',
                'bg-orange-100 text-orange-700': proxyShipment.currentAllocation?.status === 'PICKING',
            }"
            x-text="proxyShipment.currentAllocation?.status === 'RESERVED' ? '未着手' : 'ピッキング中'"
        ></span>
    </div>

    {{-- Content --}}
    <div class="flex-1 overflow-y-auto p-3 space-y-3">
        {{-- Item Info --}}
        <div class="wms-card p-3">
            {{-- Image --}}
            <template x-if="proxyShipment.currentAllocation?.item?.images?.length > 0">
                <div class="mb-2 flex justify-center">
                    <img
                        :src="proxyShipment.currentAllocation.item.images[0]"
                        class="max-h-24 object-contain rounded"
                        alt="商品画像"
                    />
                </div>
            </template>

            <div class="text-xs text-gray-400 mb-0.5" x-text="proxyShipment.currentAllocation?.item?.code || ''"></div>
            <div class="text-sm font-bold text-gray-800 mb-1" x-text="proxyShipment.currentAllocation?.item?.name || ''"></div>

            <div class="flex flex-wrap gap-2 text-xs text-gray-500">
                <span x-show="proxyShipment.currentAllocation?.item?.volume" x-text="proxyShipment.currentAllocation?.item?.volume"></span>
                <span x-show="proxyShipment.currentAllocation?.item?.capacity_case" x-text="'入数:' + proxyShipment.currentAllocation?.item?.capacity_case"></span>
                <span x-show="proxyShipment.currentAllocation?.item?.temperature_type" x-text="proxyShipment.currentAllocation?.item?.temperature_type"></span>
            </div>

            {{-- JAN Codes --}}
            <template x-if="proxyShipment.currentAllocation?.item?.jan_codes?.length > 0">
                <div class="mt-1 text-xs text-gray-400">
                    JAN:
                    <template x-for="jan in proxyShipment.currentAllocation.item.jan_codes" :key="jan">
                        <span class="inline-block bg-gray-100 px-1 py-0.5 rounded mr-1" x-text="jan"></span>
                    </template>
                </div>
            </template>
        </div>

        {{-- Warehouse Info --}}
        <div class="wms-card p-3">
            <div class="text-xs text-gray-500 mb-1">出荷ルート</div>
            <div class="flex items-center gap-2 text-sm">
                <span class="font-medium text-gray-700" x-text="proxyShipment.currentAllocation?.pickup_warehouse?.name || ''"></span>
                <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M14 5l7 7m0 0l-7 7m7-7H3" />
                </svg>
                <span class="font-medium text-gray-700" x-text="proxyShipment.currentAllocation?.destination_warehouse?.name || ''"></span>
            </div>
            <div class="text-xs text-gray-400 mt-1">
                得意先: <span x-text="proxyShipment.currentAllocation?.customer?.name || '-'"></span>
            </div>
        </div>

        {{-- Candidate Locations --}}
        <div class="wms-card p-3">
            <div class="text-xs text-gray-500 mb-2">候補ロケーション</div>
            <template x-if="proxyShipment.candidateLocations.length === 0">
                <div class="text-xs text-gray-400">候補ロケーションなし</div>
            </template>
            <div class="space-y-1">
                <template x-for="loc in proxyShipment.candidateLocations" :key="loc.location_id">
                    <div class="flex items-center justify-between text-xs bg-gray-50 px-2 py-1.5 rounded">
                        <span class="font-mono font-medium text-gray-700" x-text="loc.code"></span>
                        <span class="text-gray-500" x-text="loc.available_qty + ' 在庫'"></span>
                    </div>
                </template>
            </div>
        </div>

        {{-- Quantity Input --}}
        <div class="wms-card p-3">
            <div class="flex items-center justify-between mb-2">
                <div class="text-xs text-gray-500">
                    指示数: <span class="font-bold text-gray-800" x-text="proxyShipment.currentAllocation?.assign_qty || 0"></span>
                    <span class="ml-1" x-text="proxyShipment.currentAllocation?.assign_qty_type === 'CASE' ? 'ケース' : (proxyShipment.currentAllocation?.assign_qty_type === 'PIECE' ? 'バラ' : proxyShipment.currentAllocation?.assign_qty_type)"></span>
                </div>
            </div>

            <div class="flex items-center justify-center gap-4">
                <button
                    class="wms-btn w-12 h-12 text-xl bg-gray-200 text-gray-700 rounded-full flex items-center justify-center"
                    @click="proxyShipment.decrementQty()"
                >-</button>
                <input
                    type="number"
                    class="wms-input text-center text-2xl font-bold w-20"
                    x-model.number="proxyShipment.pickedQty"
                    min="0"
                    :max="proxyShipment.currentAllocation?.assign_qty || 0"
                />
                <button
                    class="wms-btn w-12 h-12 text-xl bg-gray-200 text-gray-700 rounded-full flex items-center justify-center"
                    @click="proxyShipment.incrementQty()"
                >+</button>
            </div>
        </div>
    </div>

    {{-- Bottom Actions --}}
    <div class="p-3 bg-white border-t border-gray-200 flex gap-2">
        <button
            class="wms-btn flex-1 bg-blue-500 text-white text-sm"
            @click="updateProxyShipment()"
        >
            更新
        </button>
        <button
            class="wms-btn flex-1 bg-orange-600 text-white text-sm font-bold"
            @click="completeProxyShipment()"
        >
            完了
        </button>
    </div>
</div>
