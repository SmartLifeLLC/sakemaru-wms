<div class="space-y-4">
    @if ($record !== null)
        <div class="grid grid-cols-2 gap-3 text-sm md:grid-cols-4">
            <div>
                <div class="text-gray-500">日付</div>
                <div class="font-medium">{{ $record->snapshot_date?->format('Y/m/d') }}</div>
            </div>
            <div>
                <div class="text-gray-500">時間帯</div>
                <div class="font-medium">{{ $record->snapshot_time === 'morning' ? '朝' : '夕' }}</div>
            </div>
            <div>
                <div class="text-gray-500">倉庫</div>
                <div class="font-medium">{{ $record->warehouse?->code }} {{ $record->warehouse?->name }}</div>
            </div>
            <div>
                <div class="text-gray-500">商品CD</div>
                <div class="font-medium">{{ $record->item?->code }}</div>
            </div>
        </div>
    @endif

    <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-3 py-2 text-left font-medium text-gray-600">ロケーション</th>
                    <th class="px-3 py-2 text-left font-medium text-gray-600">フロア</th>
                    <th class="px-3 py-2 text-left font-medium text-gray-600">賞味期限</th>
                    <th class="px-3 py-2 text-right font-medium text-gray-600">現在庫数</th>
                    <th class="px-3 py-2 text-right font-medium text-gray-600">引当済み数</th>
                    <th class="px-3 py-2 text-right font-medium text-gray-600">仕入単価</th>
                    <th class="px-3 py-2 text-right font-medium text-gray-600">ロットID</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 bg-white">
                @forelse ($lots as $lot)
                    <tr>
                        <td class="px-3 py-2">{{ $lot->location?->name ?? $lot->location_id }}</td>
                        <td class="px-3 py-2">{{ $lot->floor?->name ?? $lot->floor_id }}</td>
                        <td class="px-3 py-2">{{ $lot->expiration_date?->format('Y/m/d') ?? '-' }}</td>
                        <td class="px-3 py-2 text-right">{{ number_format($lot->current_quantity) }}</td>
                        <td class="px-3 py-2 text-right">{{ number_format($lot->reserved_quantity) }}</td>
                        <td class="px-3 py-2 text-right">{{ $lot->price === null ? '-' : number_format((float) $lot->price, 2) }}</td>
                        <td class="px-3 py-2 text-right">{{ $lot->lot_id }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-3 py-6 text-center text-gray-500">ロット明細はありません</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($lots->count() >= 500)
        <div class="text-sm text-amber-600">表示は先頭500件に制限しています。</div>
    @endif
</div>
