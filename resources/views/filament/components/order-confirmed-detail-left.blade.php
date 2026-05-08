<div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4 h-full">
    <div class="space-y-3">
        <div>
            <dt class="modal-label">実行時刻</dt>
            <dd class="modal-value">{{ $batchCodeFormatted }}</dd>
        </div>

        <div class="grid grid-cols-2 gap-3">
            <div>
                <dt class="modal-label">倉庫</dt>
                <dd class="modal-value text-sm">{{ $warehouseName }}</dd>
            </div>
            <div>
                <dt class="modal-label">発注先</dt>
                <dd class="modal-value text-sm">{{ $contractorName }}</dd>
            </div>
        </div>

        <div>
            <dt class="modal-label">商品</dt>
            <dd class="modal-value">
                <span class="text-gray-500 dark:text-gray-400">{{ $itemCode }}</span>
                {{ $itemName }}
            </dd>
        </div>

        <div>
            <dt class="modal-label">入荷予定日</dt>
            <dd class="modal-value font-bold text-primary-600 dark:text-primary-400">{{ $expectedArrivalDate }}</dd>
        </div>

        <div class="grid grid-cols-2 gap-3 pt-2 border-t border-gray-200 dark:border-white/10">
            <div>
                <dt class="modal-label">発注点</dt>
                <dd class="modal-value">{{ number_format($safetyStock ?? 0) }}</dd>
            </div>
            <div>
                <dt class="modal-label">最大発注点</dt>
                <dd class="modal-value">{{ number_format($maxStock ?? 0) }}</dd>
            </div>
            <div>
                <dt class="modal-label">最低在庫数</dt>
                <dd class="modal-value">{{ number_format($minStock ?? 0) }}</dd>
            </div>
            <div>
                <dt class="modal-label">自動発注数</dt>
                <dd class="modal-value">{{ number_format($autoOrderQuantity ?? 0) }}</dd>
            </div>
            <div>
                <dt class="modal-label">自動発注</dt>
                <dd @class([
                    'modal-value font-semibold',
                    'text-green-700 dark:text-green-300' => $isAutoOrder ?? false,
                    'text-gray-500 dark:text-gray-400' => ! ($isAutoOrder ?? false),
                ])>{{ ($isAutoOrder ?? false) ? 'ON' : 'OFF' }}</dd>
            </div>
        </div>
    </div>
</div>
