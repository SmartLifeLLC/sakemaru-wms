<x-filament-panels::page>
    @include('filament.pages.auto-replenishment-style')
    @php
        $statusClass = ['DRAFT' => 'amber', 'APPROVED' => 'green', 'DISABLED' => ''];
        $typeLabels = \App\Services\AutoReplenishment\DemandEventCalendar::NAMES;
    @endphp
    <div class="ar-shell">
        <main class="ar-main">
            <div class="ar-title"><div><small>発注 / 自動発注</small><h1>イベント設定（年末年始・お盆・GW・連休）</h1></div></div>
            <nav class="ar-tabs">
                @foreach(['settings'=>'商品別発注設定','checklist'=>'確認リスト','history'=>'自動発注履歴','events'=>'イベント設定'] as $key=>$name)
                    <a href="/admin/auto-order/{{ $key }}" class="{{ $key === 'events' ? 'active' : '' }}">{{ $name }}</a>
                @endforeach
            </nav>
            @if($errors->has('operation'))<div class="ar-notice warn" role="alert">{{ $errors->first('operation') }}</div>@endif
            @if(!$installed)
                <div class="ar-notice warn">イベント設定のテーブルが未作成です。マイグレーション（wms_demand_events / wms_demand_event_profiles）を実行してください。それまでのおすすめ設定は従来どおり曜日別のみで算定します。</div>
            @else
            <div class="ar-notice">
                承認したイベントだけが「おすすめ設定」の日付別予測に使われます（通常の発注条件＋直近イベント向けの追加発注の2段構え）。倍率は過去3年の販売実績から商品別に学習し、実績の少ない商品はカテゴリの倍率へ寄せます。
                <b>週次の流れ</b>：「今後9週のイベントを準備」（または月曜の自動実行）で、期間内のイベントを素案化 → 倍率を学習 → 過去の同じイベントで曜日別のみと補正ありを実際の計算で比較 → 標準イベントは合格なら自動承認（任意イベントは担当者が承認）→ 週次の再計算で発注点・補充目標に反映。
                全商品の一括学習：<code>php artisan wms:learn-demand-events {顧客ID} --apply</code>
                @if($trainedThrough)（最終学習 {{ $trainedThrough }}）@else（<b>未学習</b>：承認しても倍率がない商品は曜日別のままです）@endif
            </div>
            <div class="ar-toolbar">
                <div>
                    <label style="flex-direction:row;align-items:center;gap:8px">対象年
                        <select wire:model.live="year" style="width:110px">@for($y = now('Asia/Tokyo')->year - 1; $y <= now('Asia/Tokyo')->year + 2; $y++)<option value="{{ $y }}">{{ $y }}年</option>@endfor</select>
                    </label>
                </div>
                <button class="ar-btn primary" wire:click="prepare" wire:loading.attr="disabled" wire:confirm="今後{{ (int) config('replenishment.demand_events.weekly_prepare.horizon_days', 63) }}日以内のイベントについて、素案の登録・倍率の学習・過去実例での検証・標準イベントの自動承認を実行します（数分かかることがあります）。よろしいですか？">今後9週のイベントを準備（学習・検証・承認）</button>
                <button class="ar-btn" wire:click="propose" wire:loading.attr="disabled" @disabled(!$proposals)>祝日から素案を追加（{{ count($proposals) }}件）</button>
                <button class="ar-btn primary" wire:click="create">任意のイベントを追加</button>
            </div>
            @if($showForm)
                <div class="ar-notice" style="background:#fff;border-color:#cbd5e1">
                    <h3 style="font-weight:700;margin-bottom:8px">{{ $editingId ? 'イベントを編集' : 'イベントを追加' }}</h3>
                    <div class="ar-grid four">
                        <label>種別<select wire:model="form.type">@foreach($typeLabels as $value=>$label)<option value="{{ $value }}">{{ $label }}</option>@endforeach</select></label>
                        <label>名称<input type="text" wire:model="form.name" maxlength="60" placeholder="例：値上げ前・チラシ特売"></label>
                        <label>開始日<input type="date" wire:model="form.start_date"></label>
                        <label>終了日<input type="date" wire:model="form.end_date"></label>
                        <label>前日数（連休・任意）<input type="number" min="0" max="7" wire:model="form.pre_days"></label>
                        <label>後日数（連休・任意）<input type="number" min="0" max="7" wire:model="form.post_days"></label>
                        <label style="grid-column:span 2">備考<input type="text" wire:model="form.note" maxlength="255"></label>
                    </div>
                    <p class="ar-help">年末年始・お盆は日付で合わせ曜日の型を外します。GW・連休・任意は「前日〜初日・中日・最終日〜翌日」の並びで合わせます。承認済みイベントの期間を変更すると素案に戻ります。</p>
                    @foreach($errors->getMessages() as $field => $messages)@if($field !== 'operation')<p class="ar-error">{{ $messages[0] }}</p>@endif @endforeach
                    <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:8px">
                        <button class="ar-btn" wire:click="cancel">保存せず閉じる</button>
                        <button class="ar-btn primary" wire:click="save" wire:loading.attr="disabled">保存</button>
                    </div>
                </div>
            @endif
            <div class="ar-card">
                <header><div>{{ $year }}年のイベント <span>{{ $events->count() }}件（承認 {{ $events->where('status','APPROVED')->count() }}）</span></div></header>
                <div class="ar-table-wrap">
                    <table>
                        <thead><tr><th>状態</th><th>種別</th><th>名称</th><th>期間</th><th>前/後</th><th class="num">学習済み倍率（商品／カテゴリ）</th><th>過去実例での検証</th><th>承認</th><th class="ar-sticky">操作</th></tr></thead>
                        <tbody>
                        @forelse($events as $event)
                            @php $counts = $profileCounts[$event->name] ?? $profileCounts[$event->type] ?? []; @endphp
                            <tr>
                                <td><span class="ar-badge {{ $statusClass[$event->status] ?? '' }}">{{ \App\Models\WmsDemandEvent::STATUSES[$event->status] ?? $event->status }}</span></td>
                                <td>{{ $typeLabels[$event->type] ?? $event->type }}</td>
                                <td>{{ $event->name }}@if($event->note)<br><small class="ar-help">{{ $event->note }}</small>@endif</td>
                                <td class="ar-code">{{ $event->start_date->format('Y/m/d') }}〜{{ $event->end_date->format('m/d') }}（{{ $event->start_date->diffInDays($event->end_date) + 1 }}日）</td>
                                <td class="num">{{ $event->pre_days }}/{{ $event->post_days }}</td>
                                <td class="num">{{ number_format($counts['ITEM'] ?? 0) }}／{{ number_format($counts['CATEGORY'] ?? 0) }}@if($event->factor_trained_through)<br><small class="ar-help">学習 {{ $event->factor_trained_through->format('m/d') }}まで</small>@endif</td>
                                <td>
                                    @php $bt = $event->backtest; $pooled = $bt['pooled'] ?? null; $pct = fn ($v) => $v === null ? '—' : round($v * 100, 1).'%'; @endphp
                                    @if($bt)
                                        <span class="ar-badge {{ ($bt['verdict'] ?? '') === 'PASS' ? 'green' : (($bt['verdict'] ?? '') === 'FAIL' ? 'amber' : '') }}">{{ ['PASS' => '合格', 'FAIL' => '不合格', 'NO_DATA' => '実例なし'][$bt['verdict'] ?? ''] ?? $bt['verdict'] }}</span>
                                        @if($pooled)<small class="ar-help"> 過去{{ count($bt['instances']) }}回：予測誤差 {{ $pct($pooled['base']['wape']) }}→{{ $pct($pooled['event']['wape']) }}、欠品率 {{ $pct($pooled['base']['stockout_rate']) }}→{{ $pct($pooled['event']['stockout_rate']) }}、平均在庫 {{ $pooled['base']['mean_stock'] }}→{{ $pooled['event']['mean_stock'] }}</small>@endif
                                        @if($event->review_note)<br><small class="ar-help">{{ $event->review_note }}</small>@endif
                                    @else<small class="ar-help">未検証（「今後9週のイベントを準備」で実行）</small>@endif
                                </td>
                                <td>@if($event->approved_at)<small class="ar-help">{{ $event->approved_at->format('Y/m/d H:i') }}{{ $event->auto_approved ? '（自動）' : '' }}</small>@endif</td>
                                <td class="ar-sticky"><div class="ar-row-actions">
                                    @if($event->status !== 'APPROVED')<button class="ar-row-btn" wire:click="setStatus({{ $event->id }}, 'APPROVED')" wire:loading.attr="disabled">承認</button>@endif
                                    @if($event->status === 'APPROVED')<button class="ar-row-btn" wire:click="setStatus({{ $event->id }}, 'DRAFT')" wire:loading.attr="disabled">承認を取消</button>@endif
                                    @if($event->status !== 'DISABLED')<button class="ar-row-btn" wire:click="setStatus({{ $event->id }}, 'DISABLED')" wire:loading.attr="disabled">無効</button>@endif
                                    <button class="ar-row-btn" wire:click="edit({{ $event->id }})">編集</button>
                                    <button class="ar-row-btn" wire:click="preview({{ $event->id }})">{{ $previewId === (int) $event->id ? '倍率を閉じる' : '倍率を確認' }}</button>
                                </div></td>
                            </tr>
                            @if($preview && (int) $preview['event']->id === (int) $event->id)
                                <tr><td colspan="9" style="background:#f8fafc">
                                    <p class="ar-help"><b>{{ $event->name }}</b> の学習済み倍率（自動発注設定のある商品。平常の日平均に対する倍率。自社実績が少ない商品はカテゴリ値を表示）：商品 {{ $preview['items'] }}件・カテゴリ {{ $preview['categories'] }}件</p>
                                    <table style="width:auto;min-width:60%">
                                        <thead><tr><th>商品CD</th><th>商品名</th><th>カテゴリ</th><th>出所</th><th class="num">ピーク倍率</th><th>ピーク日</th><th class="num">期間平均</th><th class="num">学習年数</th><th class="num">観測バラ</th></tr></thead>
                                        <tbody>
                                        @foreach($preview['rows'] as $row)
                                            <tr><td class="ar-code">{{ $row['code'] }}</td><td>{{ $row['name'] }}</td><td>{{ $row['category'] }}</td><td>{{ $row['source'] }}</td>
                                                <td class="num">{{ $row['summary'] ? number_format($row['summary']['peak'], 2).'倍' : '—' }}</td><td>{{ $row['summary']['peak_label'] ?? '—' }}</td>
                                                <td class="num">{{ $row['summary'] && $row['summary']['mean'] !== null ? number_format($row['summary']['mean'], 2).'倍' : '—' }}</td>
                                                <td class="num">{{ $row['summary']['years'] ?? '—' }}</td><td class="num">{{ $row['pieces'] !== null ? number_format($row['pieces']) : '—' }}</td></tr>
                                        @endforeach
                                        </tbody>
                                    </table>
                                </td></tr>
                            @endif
                        @empty
                            <tr><td colspan="9"><div class="ar-empty"><b>{{ $year }}年のイベントは未登録です</b><p>「祝日から素案を追加」で年末年始・お盆・GW・3連休以上の素案を作成し、内容を確認して承認してください。</p></div></td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                <footer class="ar-help">承認権限：自動発注ON／OFFと同じ権限。学習モデル {{ $modelVersion ?? '' }}。倍率の学習・再学習は保守コマンドで行い、承認済みイベントは毎週の自動再計算（有効化時）と「おすすめ設定」の計算に反映されます。</footer>
            </div>
            @endif
        </main>
    </div>
</x-filament-panels::page>
