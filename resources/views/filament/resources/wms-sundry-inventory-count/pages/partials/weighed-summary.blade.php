{{-- 量り売りの集計（旧Accessの店舗計にあたる） --}}
@php
    $lossPercent = rtrim(rtrim(number_format($weighed['loss_rate'] * 100, 2), '0'), '.');
@endphp
<table class="w-max min-w-full border-collapse text-xs">
    <thead class="sticky top-0 z-10 bg-slate-100 text-slate-700">
        <tr>
            <th class="{{ $thClass }} text-right">明細数</th>
            <th class="{{ $thClass }} text-right">入力済</th>
            <th class="{{ $thClass }} text-right">未入力</th>
            <th class="{{ $thClass }} text-right">理論在庫数 計</th>
            <th class="{{ $thClass }} text-right">実棚数 計</th>
            <th class="{{ $thClass }} text-right">差異数 計</th>
            <th class="{{ $thClass }} text-right">差異金額 計</th>
            <th class="{{ $thClass }} text-right">期間売上数量 計</th>
            <th class="{{ $thClass }} text-right">ロス数量（{{ $lossPercent }}%）</th>
            <th class="{{ $thClass }} text-right">ロス金額</th>
            <th class="{{ $thClass }} text-right">ロス申請後 差異金額</th>
        </tr>
    </thead>
    <tbody>
        <tr class="bg-white font-bold">
            <td class="{{ $tdClass }} text-right tabular-nums">{{ number_format($weighed['detail_count']) }}</td>
            <td class="{{ $tdClass }} text-right tabular-nums">{{ number_format($weighed['counted_count']) }}</td>
            <td class="{{ $tdClass }} text-right tabular-nums">{{ number_format($weighed['uncounted_count']) }}</td>
            <td class="{{ $tdClass }} text-right tabular-nums">{{ $page->formatQuantity($weighed['system_quantity']) }}</td>
            <td class="{{ $tdClass }} text-right tabular-nums">{{ $page->formatQuantity($weighed['counted_quantity']) }}</td>
            <td class="{{ $tdClass }} text-right tabular-nums {{ $signClass($weighed['difference_quantity']) }}">{{ $page->formatQuantity($weighed['difference_quantity']) }}</td>
            <td class="{{ $tdClass }} text-right tabular-nums {{ $signClass($weighed['difference_amount']) }}">{{ $page->formatAmount($weighed['difference_amount']) }}</td>
            <td class="{{ $tdClass }} text-right tabular-nums">{{ $page->formatQuantity($weighed['sales_quantity']) }}</td>
            <td class="{{ $tdClass }} text-right tabular-nums">{{ $page->formatQuantity($weighed['loss_quantity']) }}</td>
            <td class="{{ $tdClass }} text-right tabular-nums">{{ $page->formatAmount($weighed['loss_amount']) }}</td>
            <td class="{{ $tdClass }} text-right tabular-nums {{ $signClass($weighed['difference_amount_after_loss']) }}">{{ $page->formatAmount($weighed['difference_amount_after_loss']) }}</td>
        </tr>
    </tbody>
</table>
<div class="space-y-1 px-3 py-2 text-xs text-slate-600">
    <div>入力済みの明細だけを合計しています。未入力の明細は含めていません。</div>
    <div>差異数 =（カメ + QT）− 理論在庫数、差異金額 = 差異数 × 仕入単価。</div>
    <div>
        期間売上数量は
        {{ $record->sales_from_date?->format('Y/m/d') ?? '（開始日未設定）' }} 〜 {{ $record->theory_end_date?->format('Y/m/d') ?? '-' }}
        の売上数量です。ロス数量 = 期間売上数量 × {{ $lossPercent }}%（商品ごとに四捨五入）、ロス申請後 差異金額 = 差異金額 + ロス金額。
    </div>
</div>
