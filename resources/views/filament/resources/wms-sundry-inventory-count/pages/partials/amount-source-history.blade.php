<details class="border-t border-slate-200 text-xs text-slate-600">
    <summary class="cursor-pointer px-3 py-2 font-bold">計算履歴（旧システム引継ぎ・保存済み受払）</summary>
    <table class="w-max min-w-full border-collapse" aria-label="保存済みの計算元履歴">
        <thead class="bg-slate-100">
            <tr>
                @foreach (['中分類CD', '中分類名', '計算元基準日', '計算元前残', '前残の出所', '仕入', '移入', '移出', '売上原価', '調整', '保存理論金額', '保存差異金額'] as $heading)
                    <th class="{{ $thClass }}">{{ $heading }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($amountRows as $row)
                <tr class="odd:bg-white even:bg-slate-50">
                    <td class="{{ $tdClass }}">{{ $row->category2_code }}</td>
                    <td class="{{ $tdClass }}">{{ $row->category2_name }}</td>
                    <td class="{{ $tdClass }}">{{ $row->opening_date?->format('Y/m/d') ?? '-' }}</td>
                    <td class="{{ $tdClass }} text-right">{{ $this->formatAmount($row->opening_amount, 2) }}</td>
                    <td class="{{ $tdClass }}">{{ $row->opening_source_label }}</td>
                    @foreach (['purchase_amount', 'transfer_in_amount', 'transfer_out_amount', 'sales_cost_amount', 'adjustment_amount', 'system_amount', 'difference_amount'] as $field)
                        <td class="{{ $tdClass }} text-right tabular-nums">{{ $this->formatAmount($row->{$field}, 2) }}</td>
                    @endforeach
                </tr>
            @endforeach
        </tbody>
    </table>
</details>
