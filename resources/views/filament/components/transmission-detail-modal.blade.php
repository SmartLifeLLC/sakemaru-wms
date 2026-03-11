<div class="space-y-4">
    {{-- サマリー --}}
    <div class="grid grid-cols-3 gap-4 rounded-lg bg-slate-50 dark:bg-gray-900 p-4 border border-slate-200 dark:border-gray-700">
        <div>
            <div class="text-xs font-medium text-slate-500 dark:text-gray-400 mb-1">仕入先</div>
            <div class="text-sm font-bold text-slate-800 dark:text-gray-200">{{ $record->contractor->code ?? '' }} {{ $record->contractor->name ?? '-' }}</div>
        </div>
        <div>
            <div class="text-xs font-medium text-slate-500 dark:text-gray-400 mb-1">実行日</div>
            <div class="text-sm font-bold text-slate-800 dark:text-gray-200">{{ $record->executed_date->format('Y-m-d') }}</div>
        </div>
        <div>
            <div class="text-xs font-medium text-slate-500 dark:text-gray-400 mb-1">送信ステータス</div>
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
        <div class="flex flex-col items-center justify-center py-12 text-slate-400 dark:text-gray-500">
            <i class="fa fa-file-alt text-3xl mb-3"></i>
            <p class="text-sm">送信ドキュメントはありません</p>
        </div>
    @else
        <div class="overflow-x-auto rounded-lg border border-slate-200 dark:border-gray-700">
            <table class="w-full text-sm">
                <thead class="bg-slate-50 dark:bg-gray-900">
                    <tr>
                        <th class="px-3 py-2 text-left text-xs font-medium text-slate-600 dark:text-gray-400">ステータス</th>
                        <th class="px-3 py-2 text-left text-xs font-medium text-slate-600 dark:text-gray-400">倉庫</th>
                        <th class="px-3 py-2 text-left text-xs font-medium text-slate-600 dark:text-gray-400">発注先</th>
                        <th class="px-3 py-2 text-right text-xs font-medium text-slate-600 dark:text-gray-400">件数</th>
                        <th class="px-3 py-2 text-right text-xs font-medium text-slate-600 dark:text-gray-400">数量</th>
                        <th class="px-3 py-2 text-left text-xs font-medium text-slate-600 dark:text-gray-400">送信日時</th>
                        <th class="px-3 py-2 text-left text-xs font-medium text-slate-600 dark:text-gray-400">エラー</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-gray-700">
                    @foreach($documents as $doc)
                        <tr class="hover:bg-slate-50 dark:hover:bg-gray-700 transition-colors">
                            <td class="px-3 py-2">
                                <x-filament::badge :color="$doc->status->color()">{{ $doc->status->getLabel() }}</x-filament::badge>
                            </td>
                            <td class="px-3 py-2 text-slate-700 dark:text-gray-300">{{ $doc->warehouse->name ?? '-' }}</td>
                            <td class="px-3 py-2 text-slate-700 dark:text-gray-300">{{ $doc->contractor->name ?? '-' }}</td>
                            <td class="px-3 py-2 text-right text-slate-700 dark:text-gray-300">{{ number_format($doc->record_count ?? 0) }}</td>
                            <td class="px-3 py-2 text-right text-slate-700 dark:text-gray-300">{{ number_format($doc->total_quantity ?? 0) }}</td>
                            <td class="px-3 py-2 text-slate-700 dark:text-gray-300">{{ $doc->transmitted_at?->format('H:i:s') ?? '-' }}</td>
                            <td class="px-3 py-2 text-danger-600 dark:text-danger-400">{{ \Illuminate\Support\Str::limit($doc->error_message, 40) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="text-xs text-slate-400 dark:text-gray-500">合計 {{ $documents->count() }} 件</div>
    @endif
</div>
