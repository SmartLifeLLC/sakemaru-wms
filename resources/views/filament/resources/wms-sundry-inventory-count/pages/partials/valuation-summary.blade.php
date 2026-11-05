<div class="border-y border-slate-200 bg-slate-50 px-3 py-2 text-xs font-bold text-slate-700">実棚金額（中分類別）</div>
<table class="w-max min-w-full border-collapse text-xs" aria-label="非管理品の分類別実棚金額">
    <thead class="bg-slate-100 text-slate-700">
        <tr>
            @foreach (['大分類CD', '大分類名', '中分類CD', '中分類名', '入力方式', '入力済商品数', '未入力商品数', '入力済評価額合計', '実棚金額', '計算状態'] as $heading)
                <th class="{{ $thClass }}">{{ $heading }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @foreach ($amountRows as $row)
            @php
                $details = $valuationRows->where('amount_id', $row->id);
                $entered = $details->whereNotNull('quantity')->count();
                $missing = $details->count() - $entered;
            @endphp
            <tr class="odd:bg-white even:bg-slate-50">
                <td class="{{ $tdClass }}">{{ $row->category1_code }}</td>
                <td class="{{ $tdClass }}">{{ $row->category1_name }}</td>
                <td class="{{ $tdClass }}">{{ $row->category2_code }}</td>
                <td class="{{ $tdClass }}">{{ $row->category2_name }}</td>
                <td class="{{ $tdClass }}">{{ $details->isNotEmpty() ? '商品数量から自動計算' : ($row->counted_amount !== null ? '既存金額（保持）' : '数量明細未準備') }}</td>
                <td class="{{ $tdClass }} text-right">{{ $details->isNotEmpty() ? $entered : '-' }}</td>
                <td class="{{ $tdClass }} text-right">{{ $details->isNotEmpty() ? $missing : '-' }}</td>
                <td class="{{ $tdClass }} text-right tabular-nums">{{ $this->formatAmount($entered > 0 ? $details->sum('amount') : null, 2) }}</td>
                <td class="{{ $tdClass }} text-right font-bold tabular-nums">{{ $this->formatAmount($row->counted_amount, 2) }}</td>
                <td class="{{ $tdClass }}">{{ $row->counted_amount !== null ? '入力済み' : ($missing > 0 ? '数量未入力あり' : '未入力') }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
