<div>
    <p class="mb-3">棚卸日 {{ $preview['count_date'] ?? '' }} / 適用日 {{ $preview['date'] ?? '' }}</p>
    <p class="mb-3">適用対象：{{ \App\Services\SundryInventoryCount\SundryFinalizationScope::label($preview['application_scope'] ?? 'all') }} / 対象外 {{ count($preview['excluded_lines'] ?? []) }}件</p>
    <p class="mb-3">実棚変更伝票を記録し、入力を変更不可にします。在庫への反映は「在庫へ適用」で別途実行します。</p>
    <div class="overflow-x-auto max-h-96">
        <table class="w-full text-sm">
            <thead><tr>@foreach(['分類', '商品CD', '商品名', '棚卸日理論', '実棚', '適用日理論', '適用日実棚相当', '差異金額'] as $label)<th class="p-2 whitespace-nowrap">{{ $label }}</th>@endforeach</tr></thead>
            <tbody>@foreach($preview['lines'] ?? [] as $line)
                <tr class="border-t border-gray-200 dark:border-gray-700">
                    <td class="p-2 whitespace-nowrap">{{ $line['kind'] === 'book' ? ($line['category1_code'].' '.($line['category1_name'] ?? '').'（部門全体）') : ($line['category2_code'].' '.$line['category2_name']) }}</td>
                    <td class="p-2">{{ $line['item_code'] ?: '金額' }}</td><td class="p-2 whitespace-nowrap">{{ $line['item_name'] }}</td>
                    @foreach([$line['count_theory'], $line['count_actual'], $line['quantity_before'] ?? $line['book_before'], $line['quantity_after'] ?? $line['book_after']] as $value)
                        <td class="p-2 text-right">{{ $value === null ? '-' : number_format($value, $line['kind'] === 'managed' ? 3 : 2) }}</td>
                    @endforeach
                    <td class="p-2 text-right">{{ number_format($line['amount'], 2) }}
                        @if(($line['rounding_adjustment'] ?? 0) != 0)
                            <div class="text-xs whitespace-nowrap">内数：端数配賦 {{ number_format($line['rounding_adjustment'], 2) }}</div>
                        @endif
                    </td>
                </tr>
            @endforeach</tbody>
        </table>
    </div>
    <p class="mt-3 text-right font-bold">差異金額合計 {{ number_format($preview['total'] ?? 0, 2) }} 円</p>
    @if(!empty($preview['excluded_lines']))
        <p class="mt-3">対象外の入力値は保持されます。最終確定後は対象外明細も変更不可になります。</p>
        @php($hasZeroDifference = collect($preview['excluded_lines'])->contains('quantity_policy', 'no_adjustment'))
        <details class="mt-2" @if($hasZeroDifference) open @endif>
            <summary>適用対象外の明細（{{ count($preview['excluded_lines']) }}件）</summary>
            <div class="overflow-x-auto max-h-64">
                <table class="w-full text-sm">
                    <thead><tr><th class="p-2">商品・分類CD</th><th class="p-2">名称</th><th class="p-2">対象外理由</th>
                        @if($hasZeroDifference)<th class="p-2 whitespace-nowrap">再計算理論</th><th class="p-2 whitespace-nowrap">入力実棚</th><th class="p-2 whitespace-nowrap">今回差異</th>@endif
                    </tr></thead>
                    <tbody>@foreach($preview['excluded_lines'] as $line)
                        <tr class="border-t border-gray-200 dark:border-gray-700">
                            <td class="p-2">{{ $line['item_code'] }}</td><td class="p-2 whitespace-nowrap">{{ $line['item_name'] }}</td><td class="p-2">{{ $line['reason'] }}</td>
                            @if($hasZeroDifference)
                                @foreach(['count_theory', 'count_actual', 'difference_quantity'] as $field)
                                    <td class="p-2 text-right">{{ isset($line[$field]) ? number_format($line[$field], 3) : '-' }}</td>
                                @endforeach
                            @endif
                        </tr>
                    @endforeach</tbody>
                </table>
            </div>
        </details>
    @endif
    @foreach(collect($preview['lines'] ?? [])->where('kind', 'book') as $line)
        @if(isset($line['department_adjustment']))
            <p class="mt-2 text-sm">{{ $line['category1_code'] }} {{ $line['category1_name'] }} /
                {{ $line['reason'] }}：{{ number_format($line['amount'], 2) }} 円（数量変更なし）</p>
            @continue
        @endif
        @php($basis = $line['book_reconciliation']['report_reconciliation'])
        <p class="mt-2 text-sm">{{ $line['category1_code'] }} {{ $line['category1_name'] }} / 照合日 {{ $basis['date'] }} /
            報告書残高 {{ number_format($basis['reference']['report_amount'] ?? 0, 2) }} 円 /
            管理品帳簿 {{ number_format($basis['reference']['managed_amount'] ?? 0, 2) }} 円 /
            非管理品帳簿 {{ number_format($basis['unmanaged_book_amount'], 2) }} 円 /
            既知の承認差額 {{ number_format($basis['approved_gap'], 2) }} 円 /
            不明差異 {{ number_format($line['amount'], 2) }} 円</p>
    @endforeach
</div>
