<div class="space-y-4">
    <div class="grid grid-cols-2 gap-4">
        <div class="modal-info-card">
            <dt class="modal-label">算出数</dt>
            <dd class="modal-value-large">{{ number_format($suggestedQuantity) }}</dd>
        </div>
        <div class="modal-info-card">
            <dt class="modal-label">発注数</dt>
            <dd class="modal-value-primary">{{ number_format($orderQuantity) }}</dd>
        </div>
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div class="modal-info-card">
            <dt class="modal-label">ステータス</dt>
            <dd>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-{{ $statusColor }}-100 text-{{ $statusColor }}-800 dark:bg-{{ $statusColor }}-800 dark:text-{{ $statusColor }}-100">
                    {{ $status }}
                </span>
            </dd>
        </div>
        @if($transmittedAt)
        <div class="modal-info-card">
            <dt class="modal-label">送信日時</dt>
            <dd class="modal-value">{{ $transmittedAt }}</dd>
        </div>
        @endif
    </div>
</div>
