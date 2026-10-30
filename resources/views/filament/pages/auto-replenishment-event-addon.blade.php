@php
    $adjustment = $basis['event_adjustment'] ?? null;
    $forecastEvents = $basis['demand_profile']['event_forecast']['events'] ?? [];
    $excludedNote = collect($basis['warnings'] ?? [])->first(fn ($w) => str_contains($w, 'イベント日'));
@endphp
@if($adjustment && $forecastEvents)
    <div class="ar-event-addon">
        <p><b>2段構え：通常条件 ＋ 直近イベントの追加発注</b>
            @if($adjustment['baseline'])
                <span class="ar-help">通常条件のみ：発注点 {{ number_format($adjustment['baseline']['reorder_point_pieces']) }}／過剰在庫線 {{ number_format($adjustment['baseline']['target_stock_pieces']) }}／通常量 {{ number_format($adjustment['baseline']['batch_quantity_pieces']) }}バラ →
                イベント込み：発注点 <b>{{ number_format($basis['suggested_values']['reorder_point_pieces'] ?? 0) }}</b>（{{ ($adjustment['point_add_on_pieces'] ?? 0) >= 0 ? '+' : '' }}{{ number_format($adjustment['point_add_on_pieces'] ?? 0) }}）／過剰在庫線 <b>{{ number_format($basis['suggested_values']['target_stock_pieces'] ?? 0) }}</b>（{{ ($adjustment['target_add_on_pieces'] ?? 0) >= 0 ? '+' : '' }}{{ number_format($adjustment['target_add_on_pieces'] ?? 0) }}）／通常量 {{ number_format($basis['batch_quantity_pieces'] ?? 0) }}バラ</span>
            @else
                <span class="ar-help">通常条件のみの算定：{{ str_replace('補充目標', '過剰在庫線', $adjustment['baseline_reason']) }}</span>
            @endif
        </p>
        <table class="ar-event-addon-table">
            <thead><tr><th>イベント</th><th>期間</th><th class="num">開始まで</th><th class="num">上乗せ見込</th><th>確保期限（期間前の最終入荷日）</th><th>今回の目標に反映</th><th>期間平均倍率（補正後÷補正前）</th><th>倍率の出所</th></tr></thead>
            <tbody>
            @foreach($adjustment['events'] as $event)
                <tr>
                    <td>{{ $event['name'] }}</td>
                    <td class="ar-code">{{ substr($event['start'],0,10) }}〜{{ substr($event['end'],5) }}</td>
                    <td class="num">{{ $event['days_until_start'] }}日</td>
                    <td class="num">{{ ($event['uplift_pieces'] >= 0 ? '+' : '') . number_format($event['uplift_pieces']) }}バラ</td>
                    <td class="ar-code">{{ $event['secure_by_date'] ?? '—' }}</td>
                    <td>{{ $event['in_target'] ? '反映（安全在庫の対象期間内）' : '未反映（毎週の再計算で近づくと反映）' }}@if(!empty($event['supply_short']))<br><span class="ar-projection-risk">供給不足：開始前の入荷 {{ $event['deliveries_before_start'] }}回 × 最大発注数 ＝ {{ number_format($event['supply_capacity_pieces']) }}バラ</span>@endif</td>
                    <td class="ar-code">@foreach($event['segments'] ?? [] as $seg)<span style="margin-right:8px">{{ $seg['label'] }} {{ $seg['factor'] !== null ? number_format($seg['factor'], 2).'倍' : '—' }}</span>@endforeach</td>
                    <td>{{ ['item' => '自社実績', 'category' => 'カテゴリ', 'none' => 'なし（曜日型のまま）'][$event['factor_source']] ?? $event['factor_source'] }}</td>
                </tr>
                @if($event['daily'])
                    <tr class="ar-event-addon-daily"><td colspan="8">
                        <details><summary>日別の予測（曜日型 → イベント補正）</summary>
                            <div class="ar-event-addon-days">
                                @foreach($event['daily'] as $day)
                                    <span @class(['is-up' => $day['forecast'] > $day['weekday'] * 1.05, 'is-down' => $day['forecast'] < $day['weekday'] * 0.95])><small>{{ substr($day['date'],5) }}</small>{{ number_format($day['weekday'],1) }} → <b>{{ number_format($day['forecast'],1) }}</b></span>
                                @endforeach
                            </div>
                        </details>
                    </td></tr>
                @endif
            @endforeach
            </tbody>
        </table>
        <p class="ar-help">上乗せ見込＝イベント期間の日付別予測−曜日型の合計。期間平均倍率＝区分ごとの補正後の予測数量合計÷補正前（曜日型）の合計（年末年始：前半 12/20〜26・ピーク 12/27〜31・年始 1/1〜7、お盆：前半・本体・後半、GW：前10日・本体・後2日）。この商品に適用したイベントのみ。実行時は「入荷までの消費見込」にも日付別予測を使うため、イベント前は自動で発注が増え、期間後は減ります。@if($excludedNote){{ $excludedNote }}@endif</p>
    </div>
@elseif(isset($basis['demand_events']))
    @php $de = $basis['demand_events']; @endphp
    <p class="ar-help">
        @if(($de['approved'] ?? 0) === 0)この商品に適用する承認済みイベントがないため、曜日別の通常条件のみで算定しています（イベント設定でイベントを承認し、商品に適用すると2段構えになります）。
        @elseif(($de['approved_in_horizon'] ?? 0) > 0)承認済みイベントが13週以内に {{ $de['approved_in_horizon'] }} 件ありますが、この商品（カテゴリ）の学習済み倍率がないため上乗せはありません。曜日別の通常条件のみで算定しています。
        @else 承認済みイベントは13週以内にないため、曜日別の通常条件のみで算定しています。@endif
        @if(($de['excluded_days'] ?? 0) > 0)実績期間内の補正対象日 {{ $de['excluded_days'] }} 日は平常水準の算定から除外しています（この商品に倍率のあるイベントのみ）。@endif
    </p>
@endif
