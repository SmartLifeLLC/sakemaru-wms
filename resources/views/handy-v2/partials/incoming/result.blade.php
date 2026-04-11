<div class="flex flex-col h-full items-center justify-center p-6">
    <div class="wms-card p-6 max-w-md w-full text-center">
        {{-- Success Icon --}}
        <div class="inline-flex items-center justify-center w-16 h-16 bg-green-100 rounded-full mb-4">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
            </svg>
        </div>

        <h3 class="text-lg font-bold text-gray-800 mb-1" x-text="incoming.lastResult?.message || '入荷を確定しました'"></h3>

        {{-- Summary --}}
        <div class="mt-4 bg-gray-50 rounded-lg p-3 text-left space-y-2 text-sm">
            <div class="flex justify-between">
                <span class="text-gray-500">商品</span>
                <span class="font-medium" x-text="incoming.lastResult?.scheduleItem?.item_name || '-'"></span>
            </div>
            <div class="flex justify-between">
                <span class="text-gray-500">数量</span>
                <span class="font-bold text-green-600" x-text="incoming.workItem?.work_quantity || '-'"></span>
            </div>
            <template x-if="incoming.selectedLocation">
                <div class="flex justify-between">
                    <span class="text-gray-500">ロケーション</span>
                    <span class="font-medium" x-text="incoming.selectedLocation.display_name"></span>
                </div>
            </template>
            <template x-if="incoming.workForm.work_expiration_date">
                <div class="flex justify-between">
                    <span class="text-gray-500">賞味期限</span>
                    <span class="font-medium" x-text="incoming.workForm.work_expiration_date"></span>
                </div>
            </template>
        </div>

        {{-- Back Button --}}
        <button
            class="wms-btn wms-btn-primary w-full mt-4"
            @click="backToIncomingList()"
        >
            一覧に戻る
        </button>
    </div>
</div>
