<div class="space-y-4">
    <div class="grid grid-cols-2 gap-4">
        <div>
            <span class="text-sm text-gray-500 dark:text-gray-400">発注数</span>
            <p class="text-lg font-semibold">{{ number_format($orderQuantity) }}</p>
        </div>
        <div>
            <span class="text-sm text-gray-500 dark:text-gray-400">ステータス</span>
            <p>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-{{ $statusColor }}-100 text-{{ $statusColor }}-800 dark:bg-{{ $statusColor }}-800 dark:text-{{ $statusColor }}-100">
                    {{ $status }}
                </span>
            </p>
        </div>
    </div>

    @if($transmittedAt)
    <div>
        <span class="text-sm text-gray-500 dark:text-gray-400">送信日時</span>
        <p class="font-medium">{{ $transmittedAt }}</p>
    </div>
    @endif
</div>
