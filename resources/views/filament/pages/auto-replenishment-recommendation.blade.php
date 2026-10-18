@php
    $basis=$this->recommendation['basis'];
    $recommended=$this->recommendation['values'] ?? ($basis['suggested_values'] ?? []);
    $filled=$this->recommendation['available'] && isset($this->recommendation['values']);
    $arrivalGuard=($recommended['strategy_key'] ?? '') === 'arrival_batch_v1';
    $batch=(int)($recommended['min_order_pieces'] ?? 0);
    $target=(int)($recommended['target_stock_pieces'] ?? ($recommended['reorder_point_pieces'] ?? 0));
@endphp
@if($filled)<p class="ar-recommend-success" role="status">おすすめ値を入力しました。「設定を保存」で反映します。</p>
@elseif(!$this->recommendation['available'])<p class="ar-error" role="alert">{{ $basis['reason'] ?? 'おすすめ設定を計算できません。発注先と数量条件を確認してください。' }}</p>@endif
<details class="ar-editor-helper ar-recommend-basis" @if($filled) open @endif>
    <summary>{{ $filled ? '計算の要点・サンプル' : '保存時の計算' }} @if(isset($basis['period']))（{{ $basis['period']['start'] }}〜{{ $basis['period']['end'] }}）@endif</summary>
    @if($recommended)
        <p>発注点は入荷までの出荷分、1回量は過去実績から算定。補充目標は1回量＋出荷が多い日の1日分です。</p>
        @if($arrivalGuard)
            <p><b>補充目標を超える場合は見送り。</b>入荷までの出荷見込・既発注も考慮します。</p>
            <p class="ar-example">例：在庫見込 {{ number_format(max(0,$target-$batch)) }} ＋ 1回量 {{ number_format($batch) }} ＝ {{ number_format($target) }}バラ → 発注</p>
        @else
            <p>「おすすめ設定」で、補充目標を超えない発注方法に再計算できます。</p>
        @endif
        <p class="ar-help">実績 {{ number_format($basis['own_receipt_count'] ?? 0) }}回 @if(isset($basis['mean_daily']))／ 1日平均 {{ number_format($basis['mean_daily'],2) }}バラ@endif。数量はすべて総バラ。</p>
        @if(str_contains(implode('', $basis['warnings'] ?? []),'直近28'))<p class="ar-help">直近の実績が少ないため、期間を広げて算定しています。</p>@endif
    @endif
</details>
