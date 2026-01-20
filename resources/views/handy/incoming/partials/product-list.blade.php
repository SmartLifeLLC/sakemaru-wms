{{-- Product List Screen - 480x800 optimized with infinite scroll --}}
<template x-if="currentScreen === 'list'">
    <div class="flex flex-col h-full"
         @keydown.up.prevent="moveProductSelection(-1)"
         @keydown.down.prevent="moveProductSelection(1)"
         @keydown.enter.prevent="selectProductByKeyboard()">
        {{-- Search Bar --}}
        <div class="px-1 py-1 bg-white border-b border-gray-200 sticky top-0 z-10">
            <input type="text"
                   x-model="searchQuery"
                   @input.debounce.300ms="searchProducts()"
                   placeholder="JAN/商品コード/商品名"
                   class="handy-input-sm w-full px-2 bg-gray-100 border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-blue-500 focus:bg-white text-center placeholder:text-center"
                   autofocus>
        </div>

        {{-- Product List with infinite scroll --}}
        <div class="flex-1 overflow-y-auto" @scroll="handleScroll($event)" x-ref="productList">
            {{-- Show list only when search query exists --}}
            <template x-if="searchQuery">
            <div>
                <table class="w-full text-handy-xs">
                    <template x-for="(item, index) in products" :key="item.item_id">
                        <tr @click="selectedProductIndex = index; selectProduct(item)"
                            class="border-b border-gray-200 cursor-pointer"
                            :class="selectedProductIndex === index ? 'bg-blue-100' : 'hover:bg-gray-50'"
                            :data-product-index="index">
                            <td class="px-1 py-1">
                                <div class="flex justify-between">
                                    <span class="font-mono font-bold text-gray-900" x-text="item.jan_codes?.[0] || '-'"></span>
                                    <span class="font-mono text-gray-500" x-text="item.item_code"></span>
                                </div>
                                <div class="text-blue-900 leading-tight text-handy-sm" x-text="item.item_name"></div>
                            </td>
                        </tr>
                    </template>
                </table>

                {{-- Load More Indicator --}}
                <div x-show="hasMore" class="text-center py-1 text-gray-400 text-handy-xs">
                    読み込み中...
                </div>

                {{-- No Results --}}
                <div x-show="totalProducts === 0 && !isLoading" class="text-center py-4 text-gray-400 text-handy-xs">
                    該当する商品がありません
                </div>
            </div>
            </template>

            {{-- Default state: show search prompt --}}
            <template x-if="!searchQuery">
                <div class="text-center py-4 text-gray-400 text-handy-xs">
                    商品を検索してください
                </div>
            </template>
        </div>
    </div>
</template>
