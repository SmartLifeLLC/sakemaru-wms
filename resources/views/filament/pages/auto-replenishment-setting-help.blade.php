@php $position = ($this->form['strategy_key'] ?? '') === 'position_normal_batch_v1'; @endphp
<details class="ar-editor-helper ar-settings-help">
    <summary>設定項目の説明</summary>
    <dl class="ar-setting-descriptions">
        <dt>発注点</dt><dd>{{ $position ? '現在庫と対象の既発注未入荷残を合計し、この数量を下回ると発注数量を計算します。' : '現在庫がこの数未満になると発注を判定。' }}</dd>
        <dt>最小発注数</dt><dd>1回の発注量の下限。</dd>
        <dt>最大発注数</dt><dd>1回の発注量の上限。発注実績基準では過去最大量を参考にします。</dd>
        <dt>最小発注単位</dt><dd>発注量の刻み。入力欄の単位で指定します。</dd>
        <dt>過剰在庫線</dt><dd>過剰な補充を避けるための目安。入荷までの消費・既発注を考慮した補充後見込がこの線を超えないよう発注量を制限します。現在庫そのものの上限ではありません。@if($position)必須項目です。この線まで毎回補充する設定ではありません。@else未入力は発注点。@endif</dd>
        <dt>通常の1回量</dt><dd>選んだ候補の通常量。発注実績基準は最多の数量（同率は小さい方）、売上基準は指定日数分。@if($position)次回実行までの消費後に発注判定在庫が発注点以上になる量と通常量の大きい方を、最小数・最大数・単位・過剰在庫線の余地に合わせて調整します。@else欠品見込時だけ小口で補充。@endif</dd>
    </dl>
</details>
