<div class="p-4">
    <h2 class="text-lg font-bold text-gray-800 mb-4">設定</h2>

    <div class="wms-card">
        {{-- Picker Info --}}
        <div class="wms-settings-item">
            <div>
                <div class="text-xs text-gray-500">ピッカー</div>
                <div class="font-medium text-sm" x-text="pickerName || '-'"></div>
                <div class="text-xs text-gray-400" x-text="'コード: ' + pickerCode"></div>
            </div>
            <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
            </svg>
        </div>

        {{-- Warehouse --}}
        <div class="wms-settings-item">
            <div>
                <div class="text-xs text-gray-500">選択中の倉庫</div>
                <div class="font-medium text-sm" x-text="warehouseName || '未選択'"></div>
            </div>
            <button
                class="wms-btn wms-btn-primary text-xs px-3 py-1"
                style="min-height: 32px;"
                @click="openWarehouseModal()"
            >
                変更
            </button>
        </div>

        {{-- Logout --}}
        <div class="wms-settings-item">
            <div>
                <div class="text-xs text-gray-500">セッション</div>
                <div class="font-medium text-sm text-gray-600">ログアウト</div>
            </div>
            <button
                class="wms-btn wms-btn-danger text-xs px-3 py-1"
                style="min-height: 32px;"
                @click="logout()"
            >
                ログアウト
            </button>
        </div>
    </div>

    <div class="mt-4 text-center text-xs text-gray-400">
        WMS Handy V2
    </div>
</div>
