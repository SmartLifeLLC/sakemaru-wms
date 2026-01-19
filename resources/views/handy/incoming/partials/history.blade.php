{{-- History Screen - 480x800 optimized --}}
<template x-if="currentScreen === 'history'">
    <div class="flex flex-col h-full">
        <div class="p-2 bg-gray-100 border-b border-gray-200">
            <h2 class="font-bold text-gray-700 text-handy-sm">本日の入庫履歴</h2>
        </div>
        <div class="flex-1 overflow-y-auto p-2 space-y-1">
            <template x-for="hist in history" :key="hist.id">
                <div class="handy-card bg-white rounded border-l-4 border-green-500 shadow-sm flex justify-between items-center">
                    <div class="flex-1 min-w-0">
                        <div class="text-handy-xs text-gray-500" x-text="formatTime(hist.started_at)"></div>
                        <div class="font-bold text-gray-800 truncate text-handy-sm" x-text="hist.schedule?.item_name"></div>
                        <div class="flex gap-2 text-handy-xs mt-0.5">
                            <span>数: <b class="text-blue-700" x-text="hist.work_quantity"></b></span>
                            <span>Loc: <b class="text-orange-700" x-text="hist.location?.display_name || '-'"></b></span>
                        </div>
                    </div>
                    <button @click="editHistory(hist)"
                            x-show="hist.status === 'WORKING'"
                            class="bg-gray-100 text-gray-600 px-2 py-1 rounded border border-gray-300 active:bg-gray-200 text-handy-xs font-bold flex flex-col items-center shrink-0 ml-2">
                        <i class="ph ph-pencil-simple text-handy-base"></i>
                        修正
                    </button>
                    <span x-show="hist.status === 'COMPLETED'"
                          class="text-green-600 text-handy-xs font-bold flex flex-col items-center shrink-0 ml-2">
                        <i class="ph ph-check-circle text-handy-base"></i>
                        完了
                    </span>
                </div>
            </template>
            <div x-show="history.length === 0 && !isLoading" class="text-center py-8 text-gray-400">
                <i class="ph ph-clock-counter-clockwise text-handy-2xl mb-2"></i>
                <p class="text-handy-sm">履歴がありません</p>
            </div>
        </div>
    </div>
</template>
