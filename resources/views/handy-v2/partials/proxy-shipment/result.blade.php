<div class="flex flex-col h-full">
    {{-- Sub Header --}}
    <div class="p-3 bg-white border-b border-gray-200">
        <h2 class="text-sm font-bold text-gray-800">横持ち出荷 完了</h2>
    </div>

    {{-- Content --}}
    <div class="flex-1 overflow-y-auto p-3 flex flex-col items-center justify-center">
        <div class="wms-card p-6 text-center max-w-sm w-full">
            {{-- Success Icon --}}
            <div class="mb-4">
                <svg xmlns="http://www.w3.org/2000/svg" class="w-16 h-16 mx-auto text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>

            <h3 class="text-lg font-bold text-gray-800 mb-2" x-text="proxyShipment.lastResult?.message || '完了しました'"></h3>

            {{-- Result Details --}}
            <div class="text-sm text-gray-600 space-y-2 mt-4">
                <div class="flex justify-between">
                    <span class="text-gray-500">商品</span>
                    <span class="font-medium" x-text="proxyShipment.lastResult?.allocation?.item?.name || '-'"></span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500">ステータス</span>
                    <span
                        class="font-medium"
                        :class="{
                            'text-green-600': proxyShipment.lastResult?.allocation?.status === 'FULFILLED',
                            'text-orange-600': proxyShipment.lastResult?.allocation?.status === 'SHORTAGE',
                        }"
                        x-text="proxyShipment.lastResult?.allocation?.status === 'FULFILLED' ? '充足' : '欠品'"
                    ></span>
                </div>
                <div class="flex justify-between">
                    <span class="text-gray-500">実績数</span>
                    <span class="font-medium" x-text="proxyShipment.lastResult?.allocation?.picked_qty || 0"></span>
                </div>
                <template x-if="proxyShipment.lastResult?.stockTransferQueueId">
                    <div class="flex justify-between">
                        <span class="text-gray-500">移動伝票Queue ID</span>
                        <span class="font-medium" x-text="proxyShipment.lastResult.stockTransferQueueId"></span>
                    </div>
                </template>
            </div>
        </div>
    </div>

    {{-- Bottom Action --}}
    <div class="p-3 bg-white border-t border-gray-200">
        <button
            class="wms-btn wms-btn-primary w-full text-sm"
            @click="backToProxyShipmentList()"
        >
            一覧へ戻る
        </button>
    </div>
</div>
