<div>
    <h4 class="text-base font-medium text-gray-900 dark:text-white mb-3">数量情報</h4>

    <div class="grid grid-cols-2 gap-4 mb-6">
        <div class="modal-info-card">
            <dt class="modal-label">算出数</dt>
            <dd class="modal-value-large">{{ number_format($suggestedQuantity) }}</dd>
        </div>

        <div class="modal-info-card">
            <dt class="modal-label">現在の移動数</dt>
            <dd class="modal-value-primary">{{ number_format($transferQuantity) }}</dd>
        </div>
    </div>

    @if($hasCalculationLog)
    <h4 class="text-base font-medium text-gray-900 dark:text-white mb-3">計算詳細</h4>

    <div class="modal-calc-section">
        <div class="mb-3">
            <dt class="modal-label">計算式</dt>
            <dd class="modal-value font-mono">{{ $formula }}</dd>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div>
                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">有効在庫</dt>
                <dd class="modal-value">{{ number_format($effectiveStock) }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">入荷予定</dt>
                <dd class="modal-value">{{ number_format($incomingStock) }}</dd>
            </div>
            @if($hasTransferIncoming)
            <div>
                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">移動入荷</dt>
                <dd class="modal-value">{{ number_format($transferIncoming) }}</dd>
            </div>
            @endif
            @if($hasTransferOutgoing)
            <div>
                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">移動出庫</dt>
                <dd class="modal-value">{{ number_format($transferOutgoing) }}</dd>
            </div>
            @endif
            <div>
                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">発注点</dt>
                <dd class="modal-value">{{ number_format($safetyStock) }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">見込在庫</dt>
                <dd class="modal-value">{{ number_format($calculatedAvailable) }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">不足数</dt>
                <dd class="modal-value-danger">{{ number_format($shortageQty) }}</dd>
            </div>
        </div>

        {{-- 入り数切り上げ情報 --}}
        @if(isset($purchaseUnit) && $purchaseUnit > 1)
        <div class="mt-4 p-3 bg-orange-50 dark:bg-orange-900/20 rounded-lg border border-orange-200 dark:border-orange-800">
            <h5 class="text-sm font-medium text-orange-700 dark:text-orange-300 mb-2">入り数による切り上げ</h5>
            <div class="grid grid-cols-3 gap-4 text-sm">
                <div>
                    <dt class="text-xs text-gray-500 dark:text-gray-400">不足数</dt>
                    <dd class="font-medium">{{ number_format($shortageQty) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500 dark:text-gray-400">仕入単位（入り数）</dt>
                    <dd class="font-medium">{{ number_format($purchaseUnit) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-gray-500 dark:text-gray-400">移動数（切り上げ後）</dt>
                    <dd class="font-bold text-orange-600 dark:text-orange-400">{{ number_format($transferQuantity) }}</dd>
                </div>
            </div>
            @if(isset($purchaseUnitAdjustment) && $purchaseUnitAdjustment)
            <p class="mt-2 text-xs text-gray-600 dark:text-gray-400">{{ $purchaseUnitAdjustment }}</p>
            @endif
        </div>
        @endif
    </div>
    @else
    <div class="modal-warning-box">
        <p class="text-sm text-yellow-800 dark:text-yellow-200">計算ログが見つかりません</p>
    </div>
    @endif
</div>