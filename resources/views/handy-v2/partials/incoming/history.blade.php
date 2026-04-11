<div class="flex flex-col h-full">
    {{-- Sub Header --}}
    <div class="p-3 bg-white border-b border-gray-200 flex items-center gap-2">
        <button @click="backFromHistory()" class="wms-touch-target flex items-center text-gray-500">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
            </svg>
        </button>
        <h2 class="text-sm font-bold text-gray-800">入荷履歴</h2>
        <span class="text-xs text-gray-400 ml-auto" x-text="incoming.history.length + '件'"></span>
    </div>

    {{-- History List --}}
    <div class="flex-1 overflow-y-auto p-3 space-y-2">
        <template x-if="incoming.history.length === 0">
            <div class="text-center text-gray-400 py-8 text-sm">
                履歴がありません
            </div>
        </template>

        <template x-for="item in incoming.history" :key="item.id">
            <div class="wms-card p-3">
                <div class="flex gap-3 items-start">
                    {{-- Info --}}
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2">
                            <span class="text-xs px-1.5 py-0.5 rounded font-medium"
                                  :class="{
                                      'bg-yellow-100 text-yellow-700': item.status === 'WORKING',
                                      'bg-green-100 text-green-700': item.status === 'COMPLETED',
                                      'bg-gray-100 text-gray-500': item.status === 'CANCELLED',
                                  }"
                                  x-text="item.status === 'WORKING' ? '作業中' : item.status === 'COMPLETED' ? '完了' : 'キャンセル'"></span>
                            <span class="text-xs text-gray-400" x-text="item.started_at ? new Date(item.started_at).toLocaleString('ja-JP', {month:'numeric', day:'numeric', hour:'2-digit', minute:'2-digit'}) : ''"></span>
                        </div>
                        <div class="text-sm font-medium text-gray-800 truncate mt-1" x-text="item.schedule?.item_name || '-'"></div>
                        <div class="text-xs text-gray-500" x-text="item.schedule?.item_code || ''"></div>
                        <div class="flex gap-3 mt-1 text-xs text-gray-500">
                            <span x-text="'数量: ' + (item.work_quantity || 0)"></span>
                            <template x-if="item.location">
                                <span x-text="'場所: ' + item.location.display_name"></span>
                            </template>
                        </div>
                    </div>

                    {{-- Actions --}}
                    <div class="flex gap-1 flex-shrink-0">
                        <template x-if="item.status === 'WORKING' || item.status === 'COMPLETED'">
                            <button
                                class="wms-btn text-xs px-2 py-1 bg-blue-50 text-blue-600 border border-blue-200"
                                style="min-height: 32px;"
                                @click="editHistoryItem(item)"
                            >
                                編集
                            </button>
                        </template>
                        <template x-if="item.status === 'WORKING'">
                            <button
                                class="wms-btn text-xs px-2 py-1 bg-red-50 text-red-600 border border-red-200"
                                style="min-height: 32px;"
                                @click="confirmCancelWork(item.id)"
                            >
                                取消
                            </button>
                        </template>
                    </div>
                </div>
            </div>
        </template>
    </div>

    {{-- Cancel Confirm Dialog --}}
    <template x-if="incoming.showCancelConfirm">
        <div class="wms-modal-overlay">
            <div class="wms-modal" style="max-width: 320px;">
                <h3 class="text-base font-bold text-center mb-3">キャンセル確認</h3>
                <p class="text-sm text-gray-600 text-center mb-4">この作業をキャンセルしますか？</p>
                <div class="flex gap-2">
                    <button
                        class="wms-btn flex-1 bg-gray-100 text-gray-700"
                        @click="incoming.showCancelConfirm = false; incoming.cancelTargetId = null"
                    >
                        いいえ
                    </button>
                    <button
                        class="wms-btn wms-btn-danger flex-1"
                        @click="executeCancelWork()"
                    >
                        キャンセル
                    </button>
                </div>
            </div>
        </div>
    </template>
</div>
