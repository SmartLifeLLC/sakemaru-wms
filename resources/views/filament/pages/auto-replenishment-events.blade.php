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
                学習：<code>php artisan wms:learn-demand-events {顧客ID} --apply</code>
                @if($trainedThrough)（最終学習 {{ $trainedThrough }}）@else（<b>未学習</b>：承認しても倍率がない商品は曜日別のままです）@endif
            </div>
            <div class="ar-toolbar">
                <div>
                    <label style="flex-direction:row;align-items:center;gap:8px">対象年
                        <select wire:model.live="year" style="width:110px">@for($y = now('Asia/Tokyo')->year - 1; $y <= now('Asia/Tokyo')->year + 2; $y++)<option value="{{ $y }}">{{ $y }}年</option>@endfor</select>
                    </label>
                </div>
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
                        <thead><tr><th>状態</th><th>種別</th><th>名称</th><th>期間</th><th>前/後</th><th class="num">学習済み倍率（商品／カテゴリ）</th><th>承認</th><th class="ar-sticky">操作</th></tr></thead>
                        <tbody>
                        @forelse($events as $event)
                            @php $counts = $profileCounts[$event->name] ?? $profileCounts[$event->type] ?? []; @endphp
                            <tr>
                                <td><span class="ar-badge {{ $statusClass[$event->status] ?? '' }}">{{ \App\Models\WmsDemandEvent::STATUSES[$event->status] ?? $event->status }}</span></td>
                                <td>{{ $typeLabels[$event->type] ?? $event->type }}</td>
                                <td>{{ $event->name }}@if($event->note)<br><small class="ar-help">{{ $event->note }}</small>@endif</td>
                                <td class="ar-code">{{ $event->start_date->format('Y/m/d') }}〜{{ $event->end_date->format('m/d') }}（{{ $event->start_date->diffInDays($event->end_date) + 1 }}日）</td>
                                <td class="num">{{ $event->pre_days }}/{{ $event->post_days }}</td>
                                <td class="num">{{ number_format($counts['ITEM'] ?? 0) }}／{{ number_format($counts['CATEGORY'] ?? 0) }}</td>
                                <td>@if($event->approved_at)<small class="ar-help">{{ $event->approved_at->format('Y/m/d H:i') }}</small>@endif</td>
                                <td class="ar-sticky"><div class="ar-row-actions">
                                    @if($event->status !== 'APPROVED')<button class="ar-row-btn" wire:click="setStatus({{ $event->id }}, 'APPROVED')" wire:loading.attr="disabled">承認</button>@endif
                                    @if($event->status === 'APPROVED')<button class="ar-row-btn" wire:click="setStatus({{ $event->id }}, 'DRAFT')" wire:loading.attr="disabled">承認を取消</button>@endif
                                    @if($event->status !== 'DISABLED')<button class="ar-row-btn" wire:click="setStatus({{ $event->id }}, 'DISABLED')" wire:loading.attr="disabled">無効</button>@endif
                                    <button class="ar-row-btn" wire:click="edit({{ $event->id }})">編集</button>
                                    <button class="ar-row-btn" wire:click="preview({{ $event->id }})">{{ $previewId === (int) $event->id ? '倍率を閉じる' : '倍率を確認' }}</button>
                                </div></td>
                            </tr>
                            @if($preview && (int) $preview['event']->id === (int) $event->id)
                                <tr><td colspan="8" style="background:#f8fafc">
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
                            <tr><td colspan="8"><div class="ar-empty"><b>{{ $year }}年のイベントは未登録です</b><p>「祝日から素案を追加」で年末年始・お盆・GW・3連休以上の素案を作成し、内容を確認して承認してください。</p></div></td></tr>
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
