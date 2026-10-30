@php
    $basis=$this->recommendation['basis'];
    $recommended=$this->recommendation['values'] ?? ($basis['suggested_values'] ?? []);
    $filled=$this->recommendation['available'] && isset($this->recommendation['values']);
    $strategy=$this->form['strategy_key'] ?? '';
    $arrivalGuard=$strategy === 'arrival_batch_v1';
    $adaptive=$strategy === 'arrival_adaptive_v1';
    $salesBased=($basis['basis_type'] ?? '') === 'sales';
    $safetyDays=$basis['safety_stock_days'] ?? null;
    $safetyExample=null;
    $batch=(int)($basis['batch_quantity_pieces'] ?? 0);
    $historyMinimum=(int)($basis['minimum_quantity_pieces'] ?? ($recommended['min_order_pieces'] ?? 0));
    $historyMaximum=(int)($basis['maximum_quantity_pieces'] ?? ($recommended['max_order_pieces'] ?? 0));
    $mean=(float)($basis['mean_daily'] ?? 0);
    $demandProfile=null;
    if ($mean > 0) {
        try { $demandProfile=app(\App\Services\AutoReplenishment\DemandProfile::class)->fromBasis($basis); }
        catch (\RuntimeException) { }
    }
    $positiveInteger=fn($value) => filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>2147483647]]) ?: null;
    $point=$positiveInteger($this->form['reorder_point_pieces'] ?? null);
    $minimum=$positiveInteger($this->form['min_order_pieces'] ?? null);
    $maximum=$positiveInteger($this->form['max_order_pieces'] ?? null);
    $unit=$positiveInteger($this->form['order_multiple_pieces'] ?? null);
    $targetValue=$this->form['target_stock_pieces'] ?? null;
    $target=($targetValue === '' || $targetValue === null) ? $point : $positiveInteger($targetValue);
    $valid=$point && $minimum && $maximum && $unit && $target && $minimum <= $maximum;
    $packing=$positiveInteger($basis['scope']['packing_unit'] ?? null);
    $multiple=null;
    if ($valid && $packing) {
        $a=$unit; $b=$packing;
        while ($b !== 0) { [$a,$b]=[$b,$a % $b]; }
        $multiple=intdiv($unit,$a)*$packing;
    }
    $roundedMinimum=$multiple ? (intdiv($minimum,$multiple)+($minimum % $multiple > 0 ? 1 : 0))*$multiple : null;
    $example=$multiple ? intdiv(min($target,$maximum),$multiple)*$multiple : null;
    $registeredCandidates=($this->form['shipping_unit_mode'] ?? '') === 'SHIPMENT' ? ($this->shippingProfile['quantities'] ?? []) : null;
    if ($registeredCandidates !== null && $valid && $multiple) {
        $example=app(\App\Services\AutoReplenishment\ShippingQuantitySet::class)->floor($registeredCandidates,min($target,$maximum),$minimum,$multiple);
    }
    if ($safetyDays !== null && $valid && $packing && $batch > 0 && $adaptive && $demandProfile) {
        $lead=(int)($basis['safety_lead_days'] ?? 1);
        $exampleDate=\Carbon\CarbonImmutable::parse($demandProfile['period']['end'] ?? ($basis['period']['end'] ?? '2000-01-01'))->addDay();
        $exampleConsumption=$demandProfile ? app(\App\Services\AutoReplenishment\DemandProfile::class)->quantity($demandProfile,$exampleDate->toDateString(),$lead) : 0;
        $exampleStock=max(0, app(\App\Services\AutoReplenishment\SafetyStockSettings::class)->normalOrderBoundary($point,$target,$batch,$exampleConsumption));
        $exampleSnapshot=['stock'=>$exampleStock,'incoming'=>0,'claims'=>0,'packing_unit'=>$packing,
            'business_date'=>$exampleDate->toDateString(),'arrival_date'=>$exampleDate->addDays($lead)->toDateString(),
            'next_arrival_date'=>$exampleDate->addDays($lead+max(1,(int)($basis['review_interval_days'] ?? 1)))->toDateString()];
        if ($registeredCandidates !== null) $exampleSnapshot['shipping_unit']=['mode'=>'SHIPMENT','quantities'=>$registeredCandidates];
        if (($this->form['shipping_unit_mode'] ?? '') === 'PALLET' && isset($basis['pallet_unit']['pieces_per_pallet'])) $exampleSnapshot['shipping_unit']=['mode'=>'PALLET','pieces_per_pallet'=>$basis['pallet_unit']['pieces_per_pallet']];
        $safetyExample=app(\App\Services\AutoReplenishment\StrategyCalculator::class)->calculate(
            array_replace($this->form,['calculation_basis'=>$basis,'target_stock_pieces'=>$target]),$exampleSnapshot);
    }
@endphp
@php
    $receiptCount=(int)($basis['own_receipt_count'] ?? 0);
    $receiptDays=(int)($basis['own_receipt_days'] ?? 0);
    $observedCounts=$basis['observed_quantity_counts'] ?? ($basis['batch_quantity_counts'] ?? []);
    $observedMaximum=$basis['observed_maximum_quantity_pieces'] ?? (!$salesBased ? $historyMaximum : null);
    $observedMinimum=$basis['observed_minimum_quantity_pieces'] ?? (!$salesBased ? $historyMinimum : null);
    $arrivalGap=$basis['maximum_arrival_gap_days'] ?? null;
    $referenceOnly=!empty($basis['reference_only']);
@endphp
@if(!$this->recommendation['available'])
    <div class="ar-recommend-unavailable" role="status">
        <p>{{ str_replace('補充目標', '過剰在庫線', $basis['reason'] ?? 'おすすめ設定を計算できません。発注先と数量条件を確認してください。') }}</p>
        @if(isset($basis['demand_positive_days']))<p class="ar-help">売上・出荷 {{ number_format($basis['demand_positive_days']) }}日 ／ 発注に紐づく入荷・出庫 {{ number_format($receiptCount) }}回・{{ number_format($receiptDays) }}日</p>@endif
    </div>
@endif
@if($recommended)
    @if(isset($basis['scope']['order_unit']))<p class="ar-help"><b>{{ $basis['scope']['order_unit']['label'] }}</b>：{{ $basis['scope']['order_unit']['reason'] }}</p>@endif
    <div class="ar-recommend-summary" @if($filled) role="status" @endif>
        @if(isset($basis['shipping_profile_import']['period']))<span>通常量の実績：{{ $basis['shipping_profile_import']['period']['start'] }}〜{{ $basis['shipping_profile_import']['period']['end'] }}（{{ $basis['shipping_profile_import']['normal_source'] ?? 'この倉庫・既存の最大発注数内' }}）</span>@endif
        <span>@if($filled)<span class="ar-recommend-success">候補を入力済み</span> ／ @endif<b>{{ $salesBased ? '売上基準' : ($referenceOnly ? '過去量を参考' : '発注実績基準') }}</b> ／ 通常の1回量 {{ number_format($batch) }}バラ@if($mean > 0)（約{{ number_format($batch / $mean,1) }}日分）@endif</span>
        @if($arrivalGap !== null)<span>{{ ($this->form['channel'] ?? '') === 'FAX' ? '参考条件' : '既存設定' }}での入荷間隔：最大{{ number_format($arrivalGap) }}日</span>@endif
    </div>
@endif
@if($safetyDays !== null && $recommended)
    <p class="ar-help"><b>おすすめ算定時：安全在庫 {{ $safetyDays }}日分 {{ number_format($basis['safety_stock_pieces']) }}バラ ＋ 通常量 {{ number_format($batch) }}バラ</b>で過剰在庫線を算定。@if(($basis['safety_arrival_gap_adjustment_pieces'] ?? 0)>0)入荷間隔のため {{ number_format($basis['safety_arrival_gap_adjustment_pieces']) }}バラを加算。@endif</p>
@endif
@include('filament.pages.auto-replenishment-event-addon', ['basis' => $basis])
@if($safetyDays !== null && $valid && ($target != ($recommended['target_stock_pieces'] ?? null) || $maximum != ($recommended['max_order_pieces'] ?? null) || $minimum != ($recommended['min_order_pieces'] ?? null)))
    <p class="ar-help">数量を手調整しています。日数の算定結果と異なるため、下の例と保存後のテストで確認してください。</p>
@endif
@if($safetyExample !== null)
    <p class="ar-example">例：{{ $lead }}日後入荷・既発注0・現在庫 {{ number_format($exampleStock) }}バラ → @if($safetyExample['phase']==='READY')@if(($basis['scope']['order_unit']['quantity_type'] ?? '') === 'CASE'){{ number_format(intdiv($safetyExample['quantity'], $packing)) }}ケース（{{ number_format($safetyExample['quantity']) }}バラ）発注。@else{{ number_format($safetyExample['quantity']) }}バラ発注。@endif@else{{ str_replace('補充目標', '過剰在庫線', $safetyExample['reason']) }}@endif</p>
@elseif($valid && ($adaptive || $arrivalGuard))
    @if($adaptive && $example !== null && $example >= $roundedMinimum)
        <p class="ar-example">数量例（入数はおすすめ算定時）：補充前見込0・既発注0 → 最大 {{ number_format($maximum) }}・{{ $registeredCandidates !== null ? '登録済み出荷数量候補' : '発注単位 '.number_format($multiple) }}の範囲で {{ number_format($example) }}バラ。</p>
    @elseif($arrivalGuard && $roundedMinimum !== null && $roundedMinimum <= min($maximum,$target))
        <p class="ar-example">数量例（入数はおすすめ算定時）：補充前見込0・既発注0 ＋ 固定 {{ number_format($roundedMinimum) }} ＝ {{ number_format($roundedMinimum) }}バラ（目標 {{ number_format($target) }}以内）。</p>
    @elseif($multiple)<p class="ar-help ar-quantity-caution">過剰在庫線内で最小発注数・発注単位を満たせない設定です。数量条件を見直してください。</p>@endif

@elseif(!$valid)
    <p class="ar-help">数量条件が未入力または不正なため、数量例は表示できません。</p>
@endif
<details class="ar-editor-helper ar-recommend-basis">
    <summary>算定根拠・実績を確認</summary>
    @include('filament.pages.auto-replenishment-demand-note')
    @if(isset($basis['period']))<p class="ar-help">発注実績期間：{{ $basis['period']['start'] }}〜{{ $basis['period']['end'] }}</p>@endif
    <div class="ar-current-quantity-explanation">
        <p class="ar-help">数量例は入力中の設定です。現在の在庫・入荷予定での判定は、保存後の「テスト」で確認できます。</p>
        @include('filament.pages.auto-replenishment-supply-note')
        <p><b>現在の設定</b>（入力中・保存で反映）</p>
        @if($valid)
            <p>発注点 {{ number_format($point) }} ／ 最小 {{ number_format($minimum) }} ／ 最大 {{ number_format($maximum) }} ／ 単位 {{ number_format($unit) }} ／ 過剰在庫線 {{ number_format($target) }}バラ@if($mean > 0)（約{{ number_format($target / $mean,1) }}日分）@endif。</p>
            @if($adaptive)
                <p>通常量をまとめて発注。次回の入荷までに欠品が見込まれる場合のみ小口で補充します。目標・最大数・最小数・単位を守ります。</p>
            @elseif($arrivalGuard)
                <p>1回量は最小発注数を単位に合わせた固定数量です。補充後見込が過剰在庫線を超える場合は見送ります。</p>
            @elseif($strategy === 'threshold_capped_v1')
                <p>不足数を最小数・単位に合わせ、最大発注数内の数量を発注します。残りは既発注を含めて次回再判定します。</p>
            @elseif($strategy === 'threshold_v1')
                <p>不足数を最小数・単位に合わせます。最大発注数を超える場合は全量を保留します。</p>
            @endif
        @endif
    </div>
    @if($recommended)
        <div class="ar-historical-quantity-explanation">
            <p><b>{{ $salesBased ? '売上・出荷からの算定' : ($referenceOnly ? '少数実績を参考にした算定' : 'この倉庫の発注実績からの算定') }}</b></p>
            @if($salesBased)
                <p>通常量の目安：1日平均 {{ number_format($mean,2) }} × {{ $basis['review_days'] ?? '—' }}日分を単位に切上げ → <b>{{ number_format($batch) }}バラ</b>。最小・最大の初期値もこの数量です。</p>
                @if($receiptDays > 0)<p class="ar-help">発注に紐づく入荷・出庫は {{ number_format($receiptCount) }}回・{{ number_format($receiptDays) }}日。{{ $receiptDays < 5 ? '実績日数が少ないため、初期候補は売上基準です。' : '選択した発注単位に合う実績が不足するため、売上基準です。' }}</p>
                @else<p class="ar-help">発注に紐づく入荷・出庫実績がないため、過去の最大量は未確認です。</p>@endif
            @elseif(isset($basis['batch_frequency']))
                <p>通常の1回量 <b>{{ number_format($batch) }}バラ</b>（最多 {{ number_format($basis['batch_frequency']) }}回）／ 実績の最小 {{ number_format($historyMinimum) }} ／ 最大 {{ number_format($historyMaximum) }}バラ（{{ number_format($basis['maximum_frequency'] ?? 0) }}回）。同率は小さい数量を採用。</p>
                @if($referenceOnly)<p class="ar-help">{{ number_format($receiptDays) }}日分の参考値です。通常の発注量と限らないため、数量条件を確認して保存してください。</p>@endif
            @else<p class="ar-help">保存時の通常量の目安 {{ number_format($batch) }}バラ。「おすすめ設定」で再計算できます。</p>@endif
            @if($observedCounts)
                <p>数量別の実績（回数順・上位5件）：@foreach($observedCounts as $quantity){{ number_format($quantity['pieces']) }}バラ {{ number_format($quantity['count']) }}回{{ $loop->last ? '' : ' ／ ' }}@endforeach。</p>
                @if($salesBased && $observedMaximum !== null)<p class="ar-help">実績の最小 {{ number_format($observedMinimum) }} ／ 最大 {{ number_format($observedMaximum) }}バラ。売上基準の初期値とは別に確認できます。</p>@endif
            @endif
            @if($safetyDays !== null)
                <p>安全在庫日数は発注間隔ではありません。通常量をまとめて発注し、既発注も考慮します。</p>
                <p>発注点は過剰在庫線・通常量・入荷まで{{ $basis['safety_lead_days'] }}日の消費見込@if(isset($basis['safety_lead_consumption_pieces']))（曜日別の最大 {{ number_format($basis['safety_lead_consumption_pieces']) }}バラ）@endifから算定。@if($basis['reorder_point_preserved'] ?? false)整合する既存の発注点を維持しました。@endif</p>
                @if(($recommended['reorder_point_pieces'] ?? 0)>($recommended['target_stock_pieces'] ?? 0))<p class="ar-help">入荷までの出荷見込が通常量より多いため、現在庫で判定する発注点は入荷時の過剰在庫線より大きくなっています。</p>@endif
                @if($point && $point < ($recommended['reorder_point_pieces'] ?? ($basis['normal_order_stock_pieces'] ?? 0)+1))<p class="ar-help ar-quantity-caution">入力中の発注点は算定した発注水準より低く、発注が遅くなる場合があります。</p>@endif
            @elseif($arrivalGap !== null)
                <p>過剰在庫線には、{{ ($this->form['channel'] ?? '') === 'FAX' ? '参考条件' : '既存設定' }}での入荷間隔 最大{{ number_format($arrivalGap) }}日分の需要 ＋ 最小1回分も反映します。入荷遅延による不足を防げるとは限りません。</p>
            @elseif(isset($basis['review_interval_days']))
                <p>発注点は入荷まで＋次の実行まで最大{{ $basis['review_interval_days'] }}日を考慮。過剰在庫線は「通常量＋1日分」と「次の実行までの需要＋最小1回分」の大きい方です。</p>
            @endif
            @if(isset($basis['reorder_gap_required_pieces']))<p>最低ギャップ：最小発注数・平均消費2日分・パレット最小数量の最大値（出荷数量候補・出力単位も考慮）{{ number_format($basis['reorder_gap_required_pieces']) }}バラ。過剰在庫線を維持し、発注点を調整します。発注が遅くなる場合があるため在庫推移も確認してください。</p>@endif
            <p class="ar-help">日数換算は目安です。{{ $basis['unit_basis'] ?? '' }}</p>
            @if(str_contains(implode('', $basis['warnings'] ?? []),'直近28'))<p class="ar-help">直近の実績が少ないため、期間を広げて算定しています。</p>@endif
        </div>
    @elseif(!$this->recommendation['available'])<p class="ar-help">算定できない場合は、発注単位と数量条件を手入力してください。</p>@endif
</details>

@if(isset($basis['shipping_lots']))
    <details class="ar-editor-helper ar-lot-profile">
        <summary>物流の出荷数量実績を確認</summary>
        <p class="ar-help">WMSの物流発注に紐づく出庫済み移動伝票を集計。ケース・バラは総バラに統一しています。</p>
        @foreach(['own'=>'この倉庫向け','source_all_warehouses'=>'移動元の全倉庫向け'] as $key=>$label)
            @php $lot=$basis['shipping_lots'][$key]; @endphp
            <p><b>{{ $label }}</b>：{{ number_format($lot['count']) }}回・{{ number_format($lot['days']) }}日 @if($lot['count'])／ {{ number_format($lot['warehouses']) }}倉庫@endif</p>
            @if($lot['count'])
                <table><thead><tr><th>出庫数量（総バラ）</th><th>ケース換算</th><th>回数</th></tr></thead><tbody>
                @foreach($lot['top'] as $quantity)<tr><td>{{ number_format($quantity['pieces']) }}</td><td>{{ $quantity['cases'] === null ? '—' : number_format($quantity['cases']) }}</td><td>{{ number_format($quantity['count']) }}</td></tr>@endforeach
                </tbody></table>
                <p class="ar-help">最小 {{ number_format($lot['minimum_pieces']) }} ／ 中央値 {{ number_format($lot['median_pieces']) }} ／ 共通単位 {{ number_format($lot['gcd_pieces']) }}バラ。上位5数量を表示。ケース換算はおすすめ算定時の入数です。</p>
            @else<p class="ar-help">対象期間に該当する出庫実績がありません。</p>@endif
        @endforeach
        <p class="ar-help">共通単位＝パレット入数ではありません。@if(($basis['unit_mode'] ?? '') === 'PALLET')今回は登録済みの1パレット {{ number_format($basis['pallet_unit']['pieces_per_pallet'] ?? ($recommended['order_multiple_pieces'] ?? 0)) }}バラで算定しています。@else出荷数量候補を登録すると、実績の数量から選べます。@endif</p>
    </details>
@endif

@if(!empty($basis['shipping_quantities']))
    <p class="ar-help"><b>出荷数量候補：</b>{{ implode('・', $basis['shipping_quantities']) }}バラ。店舗の通常量を基準に、最大発注数・入荷時の過剰在庫線内で選択します。満載への切上げや候補の合算は行いません。</p>
@endif
