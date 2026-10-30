<div class="ar-projection-explanation">
    <strong>入力中の設定での試算</strong>
    @if($chart['simulation_at'] ?? null)<p>試算基準：{{ \Carbon\Carbon::parse($chart['simulation_at'])->format('Y/m/d H:i') }}（倉庫の設定時刻）。テスト画面も同じ基準です。実際の発注は実行時刻で再判定します。</p>@endif
    <p>業務日 {{ $chart['start'] }}から{{ $chart['horizon_days'] ?? 14 }}日間、{{ ($this->form['channel'] ?? '') === 'FAX' ? '表示した条件で手動発注した場合の参考推移です。FAXの自動発注はONにできません。' : '自動発注をONにした場合の動きです。' }}日付にマウスを重ねるか、選択すると内訳を表示します。</p>
    <p>発注点は検討開始の目安です。下回っても、既発注・補充目標・最小数・通常量によって発注を見送る場合があります。</p>
    @if(isset($chart['safety_stock_assessment']))
        @php $assessment = $chart['safety_stock_assessment']; @endphp
        <p @class(['ar-projection-warning' => $assessment['requires_review']]) role="status"><strong>{{ ['REVIEW' => '要見直し', 'ACCEPTABLE' => '許容範囲', 'UNASSESSED' => '未判定'][$assessment['status']] }}：</strong>{{ $assessment['reason'] }}
            @if($assessment['shortfall_pieces'] !== null && $assessment['shortfall_pieces'] > 0)
                下回り {{ number_format($assessment['shortfall_pieces']) }}バラ@if($assessment['shortfall_days'] !== null)（平均消費 {{ number_format($assessment['shortfall_days'], 2) }}日分）@endif。
            @endif
        </p>
        <p>判定基準：安全在庫日数3日以上。安全在庫の下回りが平均消費量1日分以下なら許容、1日分超または欠品見込みは要見直し。在庫ゼロ以下も要見直しです。日数表示は小数2桁ですが、判定には丸め前の値を使います。</p>
    @endif

    <strong>{{ !empty($chart['demand']['legacy']) ? '各曜日の消費見込（保存済み平均・バラ／日）' : '曜日別の平均消費量（バラ／日）' }}</strong>
    <div class="ar-projection-weekdays">
        @foreach([1,2,3,4,5,6,0] as $weekday)
            @php $average=$chart['demand']['weekdays'][$weekday]; @endphp
            <div><small>{{ ['日','月','火','水','木','金','土'][$weekday] }}@if($average['fallback'])※@endif</small><b>{{ number_format($average['mean'],1) }}</b></div>
        @endforeach
    </div>
    @include('filament.pages.auto-replenishment-demand-note', ['demandProfile' => $chart['demand']])
    <p>納品：{{ implode('・',array_map(fn($d)=>['日','月','火','水','木','金','土'][$d],$chart['calendar']['weekdays'])) }}（日曜なし）</p>
    @if($chart['undated_incoming']>0 || $chart['claims']>0)<p class="ar-projection-warning">日付未定・期限超過・日曜予定 {{ number_format($chart['undated_incoming']) }}バラ／未確定候補 {{ number_format($chart['claims']) }}バラ。発注判定に含め、グラフでは入荷扱いにしていません。</p>@endif
    @if($chart['calendar']['adjusted'] || $chart['calendar']['sunday_master_enabled'])<p class="ar-projection-warning">納品設定と試算条件に差があります。おすすめ設定・グラフ・テスト・自動発注は共通で、日曜・休日を避けて次の納品可能日に移します。運用前に納品曜日・休日設定を確認してください。</p>@endif
    <strong>計算の前提</strong>
    <p>1日の順序は「入荷 → 発注判定 → 消費」。当日入荷予定は計算上の在庫へ加え、未入荷残から差し引きます。おすすめ設定・テスト・バッチも同じ前提で、実在庫は変更しません。入荷までの消費と日々の在庫減少は同じ保存済み需要データを使用し、1バラ未満の端数を日をまたいで繰り越します。</p>
    <p>既発注は登録済みの入荷予定日を使用。今回の試算分は納品可能日に全数入荷する前提です。販売・入荷時刻、移動元の欠品、納品遅れによって実際の在庫は変わります。</p>
    @if(!empty($chart['demand']['legacy']))<p>保存済みの平均消費量を使用しており、新しい期間の履歴は集計していません。</p>
    @else<p>消費はPOS販売・セット商品の消費・確定売上出荷・WMS連携の物流出庫を集計。観測 {{ $chart['demand']['observed_days'] }}日、未連携 {{ $chart['demand']['missing_days'] }}日は除外。販売ゼロ日は含め、※の曜日は全体平均を使用します。</p>@endif
    @foreach($chart['demand']['warnings'] as $warning)<p class="ar-projection-warning">{{ $warning }}</p>@endforeach
    <p>在庫・予定の取得：{{ $chart['observed_at'] }}。「在庫・予定を再取得」で更新できます。試算では発注・設定を保存しません。</p>
</div>

@if($chart['calendar']['reference_assumed'] ?? false)<p class="ar-projection-warning">FAXの納品曜日が未設定のため、翌営業日（月〜土）入荷と仮定した参考値です。実際の納品日を確認して手動発注してください。</p>@endif
