<div>
    {{-- 入庫数量情報 --}}
    <h4 class="text-base font-medium text-gray-900 dark:text-white mb-3">入庫数量</h4>

    <div class="grid grid-cols-4 gap-4 mb-6">
        <div class="modal-info-card">
            <dt class="modal-label">予定数量</dt>
            <dd class="modal-value-large">{{ number_format($expectedQuantity) }}</dd>
        </div>

        <div class="modal-info-card">
            <dt class="modal-label">入庫済</dt>
            <dd class="modal-value-large">{{ number_format($receivedQuantity) }}</dd>
        </div>

        <div class="modal-info-card">
            <dt class="modal-label">残数量</dt>
            <dd class="{{ $remainingQuantity > 0 ? 'modal-value-danger' : 'modal-value-large text-success-600' }}">{{ number_format($remainingQuantity) }}</dd>
        </div>

        <div class="modal-info-card">
            <dt class="modal-label">ステータス</dt>
            <dd>
                <span class="px-2 py-1 rounded text-sm font-medium bg-{{ $statusColor }}-100 text-{{ $statusColor }}-700 dark:bg-{{ $statusColor }}-900 dark:text-{{ $statusColor }}-300">
                    {{ $status }}
                </span>
            </dd>
        </div>
    </div>

    {{-- 現在の在庫情報 --}}
    <h4 class="text-base font-medium text-gray-900 dark:text-white mb-3">現在の倉庫内在庫</h4>

    <div class="grid grid-cols-2 gap-4 mb-6">
        <div class="modal-info-card">
            <dt class="modal-label">現在庫（総数）</dt>
            <dd class="modal-value-large">{{ number_format($currentStock) }}</dd>
        </div>

        <div class="modal-info-card">
            <dt class="modal-label">有効在庫（利用可能）</dt>
            <dd class="modal-value-primary">{{ number_format($availableStock) }}</dd>
        </div>
    </div>

    {{-- 発注時の計算情報（発注候補がある場合のみ） --}}
    @if($hasOrderCandidate)
    <h4 class="text-base font-medium text-gray-900 dark:text-white mb-3">発注時の計算情報</h4>

    <div class="p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg border border-blue-200 dark:border-blue-800 mb-4">
        <div class="flex items-center gap-4 text-sm">
            <div>
                <span class="text-gray-500 dark:text-gray-400">発注候補ID:</span>
                <span class="font-medium ml-1">{{ $orderCandidateId }}</span>
            </div>
            <div>
                <span class="text-gray-500 dark:text-gray-400">計算時刻:</span>
                <span class="font-medium ml-1">{{ $batchCodeFormatted }}</span>
            </div>
        </div>
    </div>

    @if($hasCalculationLog)
    <div class="modal-calc-section">
        <div class="mb-3">
            <dt class="modal-label">計算式</dt>
            <dd class="modal-value font-mono">{{ $formula }}</dd>
        </div>

        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <div>
                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">有効在庫（当時）</dt>
                <dd class="modal-value">{{ number_format($effectiveStock) }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">入庫予定（当時）</dt>
                <dd class="modal-value">{{ number_format($incomingStock) }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">発注点</dt>
                <dd class="modal-value">{{ number_format($safetyStock) }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-gray-500 dark:text-gray-400">不足数</dt>
                <dd class="modal-value-danger">{{ number_format($shortageQty) }}</dd>
            </div>
        </div>

        <div class="mt-4 pt-4 border-t border-gray-200 dark:border-gray-700">
            <div class="flex items-center justify-between">
                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">発注数量</span>
                <span class="text-xl font-bold text-primary-600 dark:text-primary-400">{{ number_format($orderQuantity) }}</span>
            </div>
        </div>
    </div>
    @else
    <div class="modal-warning-box">
        <p class="text-sm text-yellow-800 dark:text-yellow-200">計算ログが見つかりません</p>
    </div>
    @endif
    @else
    <div class="p-4 bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
        <p class="text-sm text-gray-500 dark:text-gray-400">
            この入庫予定は手動登録または移動からのものです。発注計算情報はありません。
        </p>
    </div>
    @endif
</div>
