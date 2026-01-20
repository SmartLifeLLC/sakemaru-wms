{{-- Warehouse Selection Screen - 480x800 optimized --}}
<template x-if="currentScreen === 'warehouse'">
    <div class="px-3 flex flex-col gap-2 h-full">
        <div class="flex-1 overflow-y-auto space-y-1">
            <template x-for="wh in warehouses" :key="wh.id">
                <button @click="selectWarehouse(wh)"
                        class="handy-btn-sm w-full bg-white border-2 border-blue-100 text-blue-900 font-bold rounded-sm shadow-sm active:bg-blue-50 active:border-blue-500">
                    <span class="text-handy-base truncate" x-text="wh.name"></span>
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
