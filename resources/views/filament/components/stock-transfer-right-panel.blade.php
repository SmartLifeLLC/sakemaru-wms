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

        <div class="grid grid-cols-2 md:grid-cols-3 gap-4">
            <div>
                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">有効在庫</dt>
                <dd class="modal-value">{{ number_format($effectiveStock) }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">入庫予定</dt>
                <dd class="modal-value">{{ number_format($incomingStock) }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">発注点</dt>
                <dd class="modal-value">{{ number_format($safetyStock) }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">計算後在庫</dt>
                <dd class="modal-value">{{ number_format($calculatedAvailable) }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">不足数</dt>
                <dd class="modal-value-danger">{{ number_format($shortageQty) }}</dd>
            </div>
        </div>
    </div>
    @else
    <div class="modal-warning-box">
        <p class="text-sm text-yellow-800 dark:text-yellow-200">計算ログが見つかりません</p>
    </div>
    @endif
</div>
