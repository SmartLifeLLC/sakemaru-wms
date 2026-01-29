<div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4 h-full">
    <h3 class="modal-section-title">基本情報</h3>

    <div class="space-y-3">
        <div>
            <dt class="modal-label">入庫区分</dt>
            <dd class="modal-value">
                <span @class([
                    'px-2 py-0.5 rounded text-xs font-medium',
                    'bg-info-100 text-info-700 dark:bg-info-900 dark:text-info-300' => $orderSource === '発注',
                    'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300' => $orderSource === '手動',
                    'bg-warning-100 text-warning-700 dark:bg-warning-900 dark:text-warning-300' => $orderSource === '移動',
                ])>{{ $orderSource }}</span>
            </dd>
        </div>

        <div>
            <dt class="modal-label">倉庫</dt>
            <dd class="modal-value">{{ $warehouseName }}</dd>
        </div>

        <div>
            <dt class="modal-label">発注先</dt>
            <dd class="modal-value">{{ $contractorName }}</dd>
        </div>

        <div>
            <dt class="modal-label">発注日</dt>
            <dd class="modal-value">{{ $orderDate }}</dd>
        </div>

        <div>
            <dt class="modal-label">入荷予定日</dt>
            <dd class="modal-value font-bold text-primary-600 dark:text-primary-400">{{ $expectedArrivalDate }}</dd>
        </div>

        <div>
            <dt class="modal-label">ロケーション</dt>
            <dd class="modal-value">
                @if($locationText !== '-')
                    <span class="font-mono bg-gray-100 dark:bg-gray-700 px-2 py-0.5 rounded">{{ $locationText }}</span>
                @else
                    <span class="text-gray-400">未設定</span>
                @endif
            </dd>
        </div>
    </div>

    <h3 class="modal-section-title mt-6">商品情報</h3>

    <div class="space-y-3">
        <div>
            <dt class="modal-label">商品コード</dt>
            <dd class="modal-value">{{ $itemCode }}</dd>
        </div>

        <div>
            <dt class="modal-label">商品名</dt>
            <dd class="modal-value">{{ $itemName }}</dd>
        </div>

        <div>
            <dt class="modal-label">規格</dt>
            <dd class="modal-value">{{ $packaging }}</dd>
        </div>

        <div>
            <dt class="modal-label">入数</dt>
            <dd class="modal-value">{{ $capacityText }}</dd>
        </div>
    </div>
</div>
