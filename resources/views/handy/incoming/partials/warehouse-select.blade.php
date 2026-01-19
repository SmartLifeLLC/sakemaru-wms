{{-- Warehouse Selection Screen - 480x800 optimized --}}
<template x-if="currentScreen === 'warehouse'">
    <div class="p-3 flex flex-col gap-2 h-full">
        <p class="text-center text-gray-600 font-bold text-handy-sm py-2">作業する倉庫を選択</p>
        <div class="flex-1 overflow-y-auto space-y-2">
            <template x-for="wh in warehouses" :key="wh.id">
                <button @click="selectWarehouse(wh)"
                        class="handy-btn-lg w-full bg-white border-2 border-blue-100 text-blue-900 font-bold rounded-lg shadow-sm active:bg-blue-50 active:border-blue-500 flex items-center justify-between">
                    <span class="text-handy-lg truncate" x-text="wh.name"></span>
                    <i class="ph ph-warehouse text-handy-2xl text-blue-300"></i>
                </button>
            </template>
        </div>
        {{-- No Warehouses --}}
        <div x-show="warehouses.length === 0 && !isLoading" class="text-center py-8 text-gray-400">
            <i class="ph ph-warning text-handy-2xl mb-2"></i>
            <p class="text-handy-sm">倉庫が見つかりません</p>
        </div>
    </div>
</template>
