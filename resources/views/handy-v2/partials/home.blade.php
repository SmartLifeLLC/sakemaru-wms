<div class="p-4 flex flex-col items-center justify-center h-full">
    <div class="wms-card p-6 text-center max-w-md">
        <div class="inline-flex items-center justify-center w-16 h-16 bg-blue-100 rounded-full mb-4">
            <svg xmlns="http://www.w3.org/2000/svg" class="w-8 h-8 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4" />
            </svg>
        </div>
        <h2 class="text-lg font-bold text-gray-800 mb-2">WMS Handy V2</h2>
        <p class="text-sm text-gray-500 mb-4">サイドナビから機能を選択してください</p>
        <div class="flex gap-2 justify-center">
            <button class="wms-btn wms-btn-primary text-sm" @click="switchTab(TABS.INCOMING)">
                入荷
            </button>
            <button class="wms-btn bg-orange-500 text-white text-sm" @click="switchTab(TABS.PICKING)">
                出荷
            </button>
        </div>
    </div>
</div>
