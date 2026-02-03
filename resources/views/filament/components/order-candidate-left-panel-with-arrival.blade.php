<div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4 h-full">
    <h3 class="modal-section-title">基本情報</h3>

    <div class="space-y-3">
        <div>
            <dt class="modal-label">発注計算時刻</dt>
            <dd class="modal-value">{{ $batchCodeFormatted }}</dd>
        </div>

        <div>
            <dt class="modal-label">在庫拠点倉庫</dt>
            <dd class="modal-value">{{ $warehouseName }}</dd>
        </div>

        <div>
            <dt class="modal-label">発注先</dt>
            <dd class="modal-value">{{ $contractorName }}</dd>
        </div>

        <div>
            <dt class="modal-label">入荷予定日</dt>
            <dd class="modal-value font-bold text-primary-600 dark:text-primary-400">{{ $expectedArrivalDate }}</dd>
        </div>
    </div>

    {{-- 入荷予定日の算出理由 --}}
    <div class="mt-4 p-3 bg-blue-50 dark:bg-blue-900/20 rounded-lg border border-blue-200 dark:border-blue-800">
        <h4 class="text-sm font-medium text-blue-700 dark:text-blue-300 mb-2">入荷予定日の算出</h4>
        <div class="space-y-2 text-sm">
            <div class="flex justify-between">
                <span class="text-gray-500 dark:text-gray-400">リードタイム</span>
                <span class="font-medium">{{ $leadTimeDays }}日</span>
            </div>
            @if($arrivalDateAdjustment)
            <div class="flex justify-between">
                <span class="text-gray-500 dark:text-gray-400">到着日調整</span>
                <span class="font-medium">{{ $arrivalDateAdjustment }}日</span>
            </div>
            @endif
            @if($originalArrivalDate !== $expectedArrivalDate)
            <div class="flex justify-between">
                <span class="text-gray-500 dark:text-gray-400">当初予定</span>
                <span class="font-medium text-gray-400 line-through">{{ $originalArrivalDate }}</span>
            </div>
            <div class="text-xs text-blue-600 dark:text-blue-400 mt-1">
                ※ 手動で変更されています
            </div>
            @endif
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

        @if(!empty($itemKana))
        <div>
            <dt class="modal-label">カナ名</dt>
            <dd class="modal-value">{{ $itemKana }}</dd>
        </div>
        @endif

        @if(!empty($janCode))
        <div>
            <dt class="modal-label">JANコード</dt>
            <dd class="modal-value">{{ $janCode }}</dd>
        </div>
        @endif

        <div>
            <dt class="modal-label">規格</dt>
            <dd class="modal-value">{{ $packaging }}</dd>
        </div>

        <div>
            <dt class="modal-label">入数</dt>
            <dd class="modal-value">{{ $capacityText }}</dd>
        </div>
    </div>

    @if(!empty($lotConditions) && count(array_filter($lotConditions)) > 0)
    <h3 class="modal-section-title mt-6">発注先ロット条件</h3>

    <div class="space-y-2 p-3 bg-amber-50 dark:bg-amber-900/20 rounded-lg border border-amber-200 dark:border-amber-800">
        @foreach($lotConditions as $index => $condition)
            @if(!empty($condition))
            <div class="text-sm">
                <span class="text-amber-700 dark:text-amber-300 font-medium">条件{{ $index + 1 }}:</span>
                <span class="text-gray-700 dark:text-gray-300">{{ $condition }}</span>
            </div>
            @endif
        @endforeach
    </div>
    @endif
</div>
