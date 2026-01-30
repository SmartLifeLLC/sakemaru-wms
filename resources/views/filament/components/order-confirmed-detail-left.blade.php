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
    </div>
</div>
