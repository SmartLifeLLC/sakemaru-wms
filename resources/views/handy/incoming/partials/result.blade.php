{{-- Result Screen - 480x800 optimized --}}
<template x-if="currentScreen === 'result'">
    <div class="flex flex-col items-center justify-center h-full p-4 text-center bg-green-50">
        <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mb-3 animate-bounce">
            <i class="ph ph-check text-handy-3xl text-green-600"></i>
        </div>
        <h2 class="text-handy-xl font-bold text-green-800 mb-1">入庫完了</h2>
        <p class="text-handy-sm text-gray-600 mb-4">入庫処理が完了しました</p>

        {{-- Result Summary --}}
        <div class="bg-white border-2 border-gray-300 p-3 rounded shadow-sm w-full max-w-xs mb-6">
            <div class="text-left">
                <div class="text-handy-xs text-gray-500">品名</div>
                <div class="font-bold text-handy-sm truncate" x-text="lastResult?.item_name"></div>
                <div class="flex justify-between mt-2">
                    <div>
                        <div class="text-handy-xs text-gray-500">ロケーション</div>
                        <div class="font-bold text-handy-lg" x-text="lastResult?.location_display || '-'"></div>
                    </div>
                    <div>
                        <div class="text-handy-xs text-gray-500">数量</div>
                        <div class="font-bold text-handy-lg" x-text="lastResult?.quantity"></div>
                    </div>
                </div>
                <template x-if="lastResult?.expiration_date">
                    <div class="mt-2">
                        <div class="text-handy-xs text-gray-500">賞味期限</div>
                        <div class="font-bold text-handy-sm" x-text="lastResult?.expiration_date"></div>
                    </div>
                </template>
            </div>
        </div>

        <button @click="finishProcess()" class="handy-btn-lg w-full max-w-xs bg-green-600 text-white font-bold rounded shadow active:bg-green-700">
            次の商品を処理
        </button>
    </div>
</template>
