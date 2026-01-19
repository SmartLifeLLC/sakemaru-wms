{{-- Product List Screen - 480x800 optimized with infinite scroll --}}
<template x-if="currentScreen === 'list'">
    <div class="flex flex-col h-full">
        {{-- Search Bar --}}
        <div class="p-2 bg-white border-b border-gray-200 sticky top-0 z-10">
            <div class="relative">
                <input type="text"
                       x-model="searchQuery"
                       @input.debounce.300ms="searchProducts()"
                       placeholder="JAN/商品コード/商品名"
                       class="handy-input w-full px-3 bg-gray-100 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 focus:bg-white text-center placeholder:text-center"
                       autofocus>
            </div>
        </div>

        {{-- Product List with infinite scroll --}}
        <div class="flex-1 p-2 space-y-2 overflow-y-auto" @scroll="handleScroll($event)">
            {{-- Show list only when search query exists --}}
            <template x-if="searchQuery">
            <div>
            <template x-for="item in products" :key="item.item_id">
                <div @click="selectProduct(item)"
                     class="handy-card bg-white rounded-lg border border-gray-200 shadow-sm active:bg-blue-50 active:border-blue-400 flex justify-between items-start cursor-pointer">

                    <div class="flex-1 min-w-0">
                        {{-- JAN Code --}}
                        <div class="flex items-center gap-2 mb-1">
                            <i class="ph ph-barcode text-gray-400 text-handy-sm"></i>
                            <span class="text-handy-base font-bold font-mono text-gray-900" x-text="item.jan_codes?.[0] || '-'"></span>
                        </div>

                        {{-- Product Name --}}
                        <h3 class="font-bold text-handy-base leading-tight text-blue-900 line-clamp-2" x-text="item.item_name"></h3>

                        {{-- Expected Quantity: received/total --}}
                        <div class="text-handy-sm text-gray-600 flex items-center gap-2 mt-1">
                            <span>合計入荷予定: <b class="text-blue-700 text-handy-lg"><span x-text="item.total_received_quantity || 0"></span>/<span x-text="item.total_expected_quantity"></span></b></span>
                        </div>
                    </div>

                    {{-- Right Side --}}
                    <div class="ml-2 flex items-center shrink-0">
                        <i class="ph ph-caret-right text-handy-2xl text-gray-300"></i>
                    </div>
                </div>
            </template>

            {{-- Load More Indicator --}}
            <div x-show="hasMore" class="text-center py-3 text-gray-400">
                <i class="ph ph-spinner text-handy-xl animate-spin"></i>
                <p class="text-handy-sm mt-1">スクロールで続きを表示</p>
            </div>

            {{-- No Results --}}
            <div x-show="totalProducts === 0 && !isLoading" class="text-center py-8 text-gray-400">
                <i class="ph ph-magnifying-glass text-handy-2xl mb-2"></i>
                <p class="text-handy-sm">該当する商品がありません</p>
            </div>
            </div>
            </template>

            {{-- Default state: show search prompt --}}
            <template x-if="!searchQuery">
                <div class="text-center py-8 text-gray-400">
                    <i class="ph ph-magnifying-glass text-handy-2xl mb-2"></i>
                    <p class="text-handy-sm">商品を検索してください</p>
                </div>
            </template>
        </div>
    </div>
</template>
