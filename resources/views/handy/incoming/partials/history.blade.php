{{-- History Screen - 480x800 optimized --}}
<template x-if="currentScreen === 'history'">
    <div class="flex flex-col h-full bg-white"
         @keydown.up.prevent="moveHistorySelection(-1)"
         @keydown.down.prevent="moveHistorySelection(1)"
         @keydown.enter.prevent="editHistoryByKeyboard()"
         tabindex="0"
         x-init="$el.focus(); selectedHistoryIndex = 0">
        <div class="px-2 py-0.5 bg-gray-100 border-b border-gray-200">
            <h2 class="font-bold text-gray-700 text-handy-xs">本日の入庫履歴</h2>
        </div>
        <div class="flex-1 overflow-y-auto" x-ref="historyList">
            {{-- Card format with minimal spacing --}}
            <div class="divide-y divide-gray-200" x-show="history.length > 0">
                <template x-for="(hist, index) in history" :key="hist.id">
                    <div class="px-2 py-1 cursor-pointer"
                         :class="selectedHistoryIndex === index ? 'bg-blue-100' : 'hover:bg-gray-50'"
                         :data-history-index="index"
                         @click="selectedHistoryIndex = index; editHistory(hist)">
                        {{-- JAN CODE  商品コード（右整列） --}}
                        <div class="flex justify-between text-handy-xs">
                            <span class="font-mono text-gray-900" x-text="hist.schedule?.jan_codes?.[0]"></span>
                            <span class="font-mono text-gray-500" x-text="hist.schedule?.item_code"></span>
                        </div>
                        {{-- 商品名 --}}
                        <div class="text-handy-xs text-gray-800 leading-tight" x-text="hist.schedule?.item_name"></div>
                        {{-- 入荷倉庫 --}}
                        <div class="text-handy-xs text-gray-600" x-text="hist.schedule?.warehouse_name"></div>
                        {{-- 入庫予定日 / 入庫日 --}}
                        <div class="flex gap-2 text-handy-xs text-gray-600">
                            <span>予定:<span x-text="formatDateMMDD(hist.schedule?.expected_arrival_date)"></span></span>
                            <span>入庫:<span x-text="formatDateMMDD(hist.work_arrival_date)"></span></span>
                        </div>
                        {{-- 入荷数（右整列） --}}
                        <div class="text-handy-xs text-right font-bold text-blue-700" x-text="hist.work_quantity"></div>
                    </div>
                </template>
            </div>

            {{-- Empty state --}}
            <div x-show="history.length === 0 && !isLoading" class="text-center py-8 text-gray-400">
                <i class="ph ph-clock-counter-clockwise text-handy-2xl mb-2"></i>
                <p class="text-handy-sm">履歴がありません</p>
            </div>
        </div>
    </div>
</template>
