@php
    use App\Enums\QuantityType;

    $quantityTypeLabel = fn (?string $value): string => $value ? (QuantityType::tryFrom($value)?->name() ?? $value) : '-';
    $transferStatusLabel = match ($transfer?->picking_status) {
        'BEFORE' => '未処理',
        'BEFORE_PICKING' => 'ピッキング前',
        'PICKING' => 'ピッキング中',
        'SHORTAGE' => '欠品',
        'COMPLETED' => 'ピッキング完了',
        'SHIPPED' => '出荷済',
        default => $transfer?->picking_status ?? '-',
    };
@endphp

<div class="space-y-4">
    <div class="grid grid-cols-4 gap-3 rounded-lg border border-slate-200 bg-slate-50 p-4 dark:border-gray-700 dark:bg-gray-900">
        <div>
            <div class="mb-1 text-xs font-medium text-slate-500 dark:text-gray-400">バッチCODE</div>
            <div class="font-mono text-sm font-bold text-slate-800 dark:text-gray-200">{{ $batchCode }}</div>
        </div>
        <div>
            <div class="mb-1 text-xs font-medium text-slate-500 dark:text-gray-400">キューID</div>
            <div class="text-sm font-bold text-slate-800 dark:text-gray-200">{{ $queue->id ?? '-' }}</div>
        </div>
        <div>
            <div class="mb-1 text-xs font-medium text-slate-500 dark:text-gray-400">移動伝票ID</div>
            <div class="text-sm font-bold text-slate-800 dark:text-gray-200">{{ $transfer->id ?? $queue->stock_transfer_id ?? '-' }}</div>
        </div>
        <div>
            <div class="mb-1 text-xs font-medium text-slate-500 dark:text-gray-400">件数</div>
            <div class="text-sm font-bold text-slate-800 dark:text-gray-200">{{ number_format($items->count()) }} 件 / 候補 {{ number_format($candidateCount) }} 件</div>
        </div>
        <div>
            <div class="mb-1 text-xs font-medium text-slate-500 dark:text-gray-400">処理日</div>
            <div class="text-sm text-slate-800 dark:text-gray-200">{{ $transfer->process_date ?? $queue->process_date ?? '-' }}</div>
        </div>
        <div>
            <div class="mb-1 text-xs font-medium text-slate-500 dark:text-gray-400">納品日</div>
            <div class="text-sm text-slate-800 dark:text-gray-200">{{ $transfer->delivered_date ?? $queue->delivered_date ?? '-' }}</div>
        </div>
        <div>
            <div class="mb-1 text-xs font-medium text-slate-500 dark:text-gray-400">状態</div>
            <div class="text-sm text-slate-800 dark:text-gray-200">{{ $transfer ? $transferStatusLabel : ($queue->status ?? '-') }}</div>
        </div>
        <div>
            <div class="mb-1 text-xs font-medium text-slate-500 dark:text-gray-400">伝票番号</div>
            <div class="text-sm text-slate-800 dark:text-gray-200">{{ $transfer->slip_number ?? $queue->slip_number ?? '-' }}</div>
        </div>
        <div class="col-span-2">
            <div class="mb-1 text-xs font-medium text-slate-500 dark:text-gray-400">移動元</div>
            <div class="text-sm text-slate-800 dark:text-gray-200">[{{ $transfer->from_warehouse_code ?? $queue->from_warehouse_code ?? '-' }}]{{ $transfer->from_warehouse_name ?? '' }}</div>
        </div>
        <div class="col-span-2">
            <div class="mb-1 text-xs font-medium text-slate-500 dark:text-gray-400">移動先</div>
            <div class="text-sm text-slate-800 dark:text-gray-200">[{{ $transfer->to_warehouse_code ?? $queue->to_warehouse_code ?? '-' }}]{{ $transfer->to_warehouse_name ?? '' }}</div>
        </div>
        <div class="col-span-4">
            <div class="mb-1 text-xs font-medium text-slate-500 dark:text-gray-400">摘要</div>
            <div class="text-sm text-slate-800 dark:text-gray-200">{{ $transfer->note ?? $queue->note ?? '-' }}</div>
        </div>
    </div>

    @if($items->isEmpty())
        <div class="flex flex-col items-center justify-center rounded-lg border border-dashed border-slate-300 py-12 text-slate-400 dark:border-gray-700 dark:text-gray-500">
            <div class="text-sm">移動伝票の商品明細はまだ作成されていません</div>
        </div>
    @else
        <div class="max-h-[260px] overflow-auto rounded-lg border border-slate-200 dark:border-gray-700">
            <table class="w-full text-sm">
                <thead class="sticky top-0 z-10 bg-slate-50 dark:bg-gray-900">
                    <tr>
                        <th class="w-12 px-3 py-2 text-right text-xs font-medium text-slate-600 dark:text-gray-400">No</th>
                        <th class="w-24 px-3 py-2 text-left text-xs font-medium text-slate-600 dark:text-gray-400">商品CD</th>
                        <th class="min-w-72 px-3 py-2 text-left text-xs font-medium text-slate-600 dark:text-gray-400">商品名</th>
                        <th class="w-24 px-3 py-2 text-left text-xs font-medium text-slate-600 dark:text-gray-400">規格</th>
                        <th class="w-24 px-3 py-2 text-right text-xs font-medium text-slate-600 dark:text-gray-400">入力数</th>
                        <th class="w-20 px-3 py-2 text-left text-xs font-medium text-slate-600 dark:text-gray-400">単位</th>
                        <th class="w-20 px-3 py-2 text-right text-xs font-medium text-slate-600 dark:text-gray-400">入数</th>
                        <th class="w-32 px-3 py-2 text-left text-xs font-medium text-slate-600 dark:text-gray-400">備考</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200 dark:divide-gray-700">
                    @foreach($items as $item)
                        <tr class="hover:bg-slate-50 dark:hover:bg-gray-800">
                            <td class="px-3 py-2 text-right text-slate-500 dark:text-gray-400">{{ $item->order_of_items_in_slip ?? $loop->iteration }}</td>
                            <td class="px-3 py-2 font-mono text-slate-800 dark:text-gray-200">{{ $item->item_code ?? '-' }}</td>
                            <td class="px-3 py-2 text-slate-800 dark:text-gray-200">{{ $item->item_name ?? '-' }}</td>
                            <td class="px-3 py-2 text-slate-700 dark:text-gray-300">{{ $item->packaging ?? '-' }}</td>
                            <td class="px-3 py-2 text-right font-semibold text-slate-900 dark:text-white">{{ number_format((float) ($item->quantity ?? 0)) }}</td>
                            <td class="px-3 py-2 text-slate-700 dark:text-gray-300">{{ $quantityTypeLabel($item->quantity_type ?? null) }}</td>
                            <td class="px-3 py-2 text-right text-slate-700 dark:text-gray-300">{{ number_format((int) ($item->capacity_case ?? 0)) }}</td>
                            <td class="px-3 py-2 text-slate-500 dark:text-gray-400">{{ $item->note ?? '-' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
