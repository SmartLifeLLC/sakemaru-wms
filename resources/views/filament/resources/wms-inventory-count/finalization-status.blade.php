<div class="space-y-3 text-sm text-gray-900 dark:text-gray-100">
    <p>棚卸し伝票日付: {{ $count->inventory_adjustment_date?->format('Y/m/d') ?? '-' }}</p>
    <p>最終確定の対象回: {{ $count->inventory_adjustment_count_round ?? '-' }}回目</p>
    @if ($count->inventory_adjustment_error_message)
        <p class="text-red-700 dark:text-red-300">連携エラー: {{ $count->inventory_adjustment_error_message }}</p>
    @endif
    @if ($queues->isEmpty())
        <p>{{ (int) $count->inventory_adjustment_queue_count === 0 ? '調節対象の差異がないため伝票はありません。' : '連携データが見つかりません。管理者に確認してください。' }}</p>
    @else
        <p>完了 {{ $queues->where('status', 'FINISHED')->where('is_success', true)->count() }} / {{ $queues->count() }}件</p>
        <div class="overflow-x-auto">
            <table class="w-full text-left">
                <thead><tr><th class="p-2">連携ID</th><th class="p-2">状態</th><th class="p-2">伝票ID</th><th class="p-2">エラー</th></tr></thead>
                <tbody>
                @foreach ($queues as $queue)
                    <tr class="border-t border-gray-200 dark:border-gray-700">
                        <td class="p-2">{{ $queue->id }}</td>
                        <td class="p-2">{{ $queue->status === 'FINISHED' ? ($queue->is_success ? '反映完了' : '失敗（要確認）') : ($queue->status === 'PROCESSING' ? '処理中' : ($queue->retry_count > 0 ? '再試行待ち' : '連携待ち')) }}</td>
                        <td class="p-2">{{ $queue->inventory_adjustment_id ?? '-' }}</td>
                        <td class="p-2">{{ $queue->error_message ?? '-' }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
