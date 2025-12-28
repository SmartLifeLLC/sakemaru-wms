<div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4 h-full">
    <h3 class="modal-section-title">基本情報</h3>

    <div class="space-y-3">
        <div>
            <dt class="modal-label">発注計算時刻</dt>
            <dd class="modal-value">{{ $batchCodeFormatted }}</dd>
        </div>

        <div>
            <dt class="modal-label">在庫依頼倉庫</dt>
            <dd class="modal-value">{{ $satelliteWarehouseName }}</dd>
        </div>

        <div>
            <dt class="modal-label">横持ち出荷倉庫</dt>
            <dd class="modal-value">{{ $hubWarehouseName }}</dd>
        </div>

        <div>
            <dt class="modal-label">入荷予定日</dt>
            <dd class="modal-value">{{ $expectedArrivalDate }}</dd>
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
