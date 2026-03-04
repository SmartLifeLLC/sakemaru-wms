<div class="space-y-4">
    {{-- サマリー --}}
    <div class="grid grid-cols-3 gap-4 rounded-lg bg-gray-50 p-4 dark:bg-white/5">
        <div>
            <div class="text-sm text-gray-500 dark:text-gray-400">仕入先</div>
            <div class="font-medium">{{ $record->contractor->code ?? '' }} {{ $record->contractor->name ?? '-' }}</div>
        </div>
        <div>
            <div class="text-sm text-gray-500 dark:text-gray-400">実行日</div>
            <div class="font-medium">{{ $record->executed_date->format('Y-m-d') }}</div>
        </div>
        <div>
            <div class="text-sm text-gray-500 dark:text-gray-400">送信ステータス</div>
            <div>
                @php
                    $statusColor = match($record->transmission_status) {
                        'RUNNING' => 'info',
                        'SUCCESS' => 'success',
                        'FAILED' => 'danger',
                        default => 'gray',
                    };
                    $statusLabel = match($record->transmission_status) {
                        'RUNNING' => '送信中',
                        'SUCCESS' => '送信済',
                        'FAILED' => '送信失敗',
                        default => '-',
                    };
                @endphp
                <x-filament::badge :color="$statusColor">{{ $statusLabel }}</x-filament::badge>
            </div>
        </div>
    </div>

    {{-- ドキュメント一覧 --}}
    @if($documents->isEmpty())
        <div class="rounded-lg border border-gray-200 p-4 text-center text-sm text-gray-500 dark:border-gray-700 dark:text-gray-400">
            送信ドキュメントはありません
        </div>
    @else
        <div class="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-700">
            <table class="w-full text-sm">
                <thead class="bg-gray-50 dark:bg-white/5">
                    <tr>
                        <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">ステータス</th>
                        <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">倉庫</th>
                        <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">発注先</th>
                        <th class="px-3 py-2 text-right font-medium text-gray-600 dark:text-gray-300">件数</th>
                        <th class="px-3 py-2 text-right font-medium text-gray-600 dark:text-gray-300">数量</th>
                        <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">送信日時</th>
                        <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">エラー</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @foreach($documents as $doc)
                        <tr>
                            <td class="px-3 py-2">
                                <x-filament::badge :color="$doc->status->color()">{{ $doc->status->getLabel() }}</x-filament::badge>
                            </td>
                            <td class="px-3 py-2">{{ $doc->warehouse->name ?? '-' }}</td>
                            <td class="px-3 py-2">{{ $doc->contractor->name ?? '-' }}</td>
                            <td class="px-3 py-2 text-right">{{ number_format($doc->record_count ?? 0) }}</td>
                            <td class="px-3 py-2 text-right">{{ number_format($doc->total_quantity ?? 0) }}</td>
                            <td class="px-3 py-2">{{ $doc->transmitted_at?->format('H:i:s') ?? '-' }}</td>
                            <td class="px-3 py-2 text-danger-600 dark:text-danger-400">{{ \Illuminate\Support\Str::limit($doc->error_message, 40) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="text-xs text-gray-400">合計 {{ $documents->count() }} 件</div>
    @endif
</div>
