@if($demandProfile)
    <p class="ar-help">需要：@if($demandProfile['period'] ?? null){{ $demandProfile['period']['start'] }}〜{{ $demandProfile['period']['end'] }} ／ @else保存時の期間未記録 ／ @endif平均 {{ number_format($demandProfile['mean'],2) }}バラ／日@if($demandProfile['observed_days'] ?? 0) ／ 観測 {{ number_format($demandProfile['observed_days']) }}日@endif。{{ !empty($demandProfile['legacy']) ? '保存済み平均で共通計算。曜日別への切替はおすすめ設定を再計算・保存してください。' : 'おすすめ設定・グラフ・テスト・バッチで共通。再計算・保存まで同じ根拠を使用します。' }}</p>
@endif
