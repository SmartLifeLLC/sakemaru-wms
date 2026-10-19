@php $monthlyOpenings = $this->monthlyOpenings($amountRows); @endphp
<div class="border-b border-slate-200 bg-slate-50 px-3 py-2 text-xs font-bold text-slate-700">月末前残（中分類別・非管理品）</div>
<table class="w-max min-w-full border-collapse text-xs" aria-label="中分類別月末前残">
    <thead class="bg-slate-100 text-slate-700">
        <tr>
            @foreach (['大分類CD', '大分類名', '中分類CD', '中分類名', '前残基準日', '受払計算残高', '移行差額', '月末前残金額', '計算状態'] as $heading)
                <th class="{{ $thClass }}">{{ $heading }}</th>
            @endforeach
        </tr>
    </thead>
    <tbody>
        @foreach ($monthlyOpenings['rows'] as $opening)
            <tr class="odd:bg-white even:bg-slate-50">
                <td class="{{ $tdClass }}">{{ $opening['category1_code'] }}</td>
                <td class="{{ $tdClass }}">{{ $opening['category1_name'] }}</td>
                <td class="{{ $tdClass }}">{{ $opening['category2_code'] }}</td>
                <td class="{{ $tdClass }}">{{ $opening['category2_name'] }}</td>
                <td class="{{ $tdClass }}">{{ $opening['date'] ? str_replace('-', '/', $opening['date']) : '-' }}</td>
                @foreach (['calculated_amount', 'reconciliation_amount', 'opening_amount'] as $field)
                    <td class="{{ $tdClass }} text-right tabular-nums">{{ $this->formatAmount($opening[$field], 2) }}</td>
                @endforeach
                <td class="{{ $tdClass }}">{{ $opening['status'] }}</td>
            </tr>
        @endforeach
    </tbody>
    <tfoot class="bg-slate-100">
        @foreach ($monthlyOpenings['totals'] as $opening)
            @if(($opening['department_adjustment_amount'] ?? 0) != 0)
                <tr>
                    <th colspan="4" class="{{ $thClass }} text-left">{{ $opening['category1_code'] }} 大分類調整（中分類未配賦）</th>
                    <td class="{{ $tdClass }}">{{ str_replace('-', '/', $opening['date']) }}</td>
                    <td colspan="2" class="{{ $tdClass }}">顧客提出差異の適用残高</td>
                    <td class="{{ $tdClass }} text-right">{{ $this->formatAmount($opening['department_adjustment_amount'], 2) }}</td>
                    <td class="{{ $tdClass }}"></td>
                </tr>
            @endif
            <tr>
                <th colspan="4" class="{{ $thClass }} text-left">{{ $opening['category1_code'] }} {{ $opening['category1_name'] }} 合計</th>
                <td class="{{ $tdClass }}">{{ $opening['date'] ? str_replace('-', '/', $opening['date']) : '-' }}</td>
                <td colspan="2" class="{{ $tdClass }} text-right">報告書 非管理品 {{ $this->formatAmount($opening['report_amount'], 2) }}</td>
                <td class="{{ $tdClass }} text-right font-bold">{{ $this->formatAmount($opening['opening_amount'], 2) }}</td>
                <td class="{{ $tdClass }} {{ $signClass($opening['difference_amount']) }}">照合差 {{ $this->formatAmount($opening['difference_amount'], 2) }}</td>
            </tr>
        @endforeach
    </tfoot>
</table>
