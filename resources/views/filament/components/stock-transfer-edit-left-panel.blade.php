<div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4 h-full">
    <div class="space-y-3">
        <div>
            <dt class="modal-label">実行時刻</dt>
            <dd class="modal-value">{{ $batchCodeFormatted }}</dd>
        </div>

        <div class="grid grid-cols-2 gap-3">
            <div>
                <dt class="modal-label">依頼倉庫</dt>
                <dd class="modal-value text-sm">{{ $satelliteWarehouseName }}</dd>
            </div>
            <div>
                <dt class="modal-label">移動元</dt>
                <dd class="modal-value text-sm">{{ $hubWarehouseName }}</dd>
            </div>
        </div>

        <div>
            <dt class="modal-label">商品</dt>
            <dd class="modal-value">
                <span class="text-gray-500 dark:text-gray-400">{{ $itemCode }}</span>
                {{ $itemName }}
            </dd>
        </div>

        <div class="grid grid-cols-2 gap-3 pt-2 border-t border-gray-200 dark:border-gray-700">
            <div>
                <dt class="modal-label">算出数</dt>
                <dd class="modal-value-large">{{ number_format($suggestedQuantity) }}</dd>
            </div>
            <div>
                <dt class="modal-label">現在移動数</dt>
                <dd class="modal-value-primary">{{ number_format($transferQuantity) }}</dd>
            </div>
        </div>
    </div>
</div>
