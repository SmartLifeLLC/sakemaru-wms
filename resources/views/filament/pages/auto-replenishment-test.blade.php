@php
    $test = $this->testResult;
    $context = $this->testContext;
    $logistics = ($context['channel'] ?? '') === 'LOGISTICS';
    $formatTestDate = fn ($date) => $date ? \Carbon\Carbon::parse($date)->locale('ja')->isoFormat('YYYY/MM/DD（ddd）') : '—';
@endphp
<div class="ar-backdrop" wire:click="closePolicyTest"></div>
<section class="ar-drawer ar-test" role="dialog" aria-modal="true" aria-label="自動発注のテスト" x-data x-trap.inert.noscroll="true" @keydown.escape.window="$wire.closePolicyTest()">
    <header><b>自動発注のテスト</b><button type="button" wire:click="closePolicyTest" aria-label="閉じる">×</button></header>
    <div class="ar-drawer-body">
        <div class="ar-test-product"><span class="ar-code">{{ $context['item_code'] }}</span><strong>{{ $context['item_name'] }}</strong><span class="ar-badge">{{ $logistics ? '物流発注（店間）' : 'EOS発注' }}</span></div>
        <p class="ar-help">[{{ $warehouse?->code }}] {{ $warehouse?->name }} ／ 発注先 [{{ $context['contractor_code'] }}] {{ $context['contractor_name'] }}@if($logistics)<br>移動元 [{{ $context['source_code'] }}] {{ $context['source_name'] }}@endif</p>
        <div class="ar-notice">現在の在庫で仮計算します。保存・発注は行いません。OFFでも試せます。</div>
        <div class="ar-test-state"><span>商品 <b>{{ $context['is_enabled'] ? 'ON' : 'OFF' }}</b></span><span>倉庫 <b>{{ $warehouse?->is_auto_order_enabled ? 'ON' : 'OFF' }}</b></span><span>全体 <b>{{ $control?->enabled && config('replenishment.enabled') ? 'ON' : 'OFF' }}</b></span><small>実際の自動発注には、すべてONが必要です。</small></div>
        @if($test)
            @include('filament.pages.auto-replenishment-route-warnings', ['masterWarnings' => $test['master_warnings'] ?? []])
            @include('filament.pages.auto-replenishment-supply-note')
            <div class="ar-test-result" role="status"><span class="ar-badge {{ $test['phase'] === 'READY' ? 'green' : ($test['phase'] === 'HOLD' ? 'amber' : '') }}">{{ ['READY'=>'発注対象（仮計算）','NO_ORDER'=>'発注不要','HOLD'=>'保留'][$test['phase']] }}</span><strong>{{ str_replace('補充目標', '過剰在庫線', $test['reason']) }}</strong></div>
            <div class="ar-grid {{ $logistics ? 'three' : '' }}">
                <div class="ar-value ar-test-quantity"><small>仮発注数量</small><b>{{ number_format($test['quantity_pieces']) }}<span>バラ</span></b><small>発注データ：{{ number_format($test['order_quantity']) }} {{ \App\Enums\QuantityType::from($test['quantity_type'])->name() }}</small></div>
                @if($logistics)<div class="ar-value"><small>出庫日{{ $test['phase'] !== 'READY' ? '（参考）' : '' }}</small><b>{{ $formatTestDate($test['picking_date']) }}</b></div>@endif
                <div class="ar-value"><small>{{ $logistics ? '入庫日' : '入荷予定日' }}{{ $test['phase'] !== 'READY' ? '（参考）' : '' }}</small><b>{{ $formatTestDate($test['arrival_date']) }}</b></div>
            </div>
            @if($test['order_unit'] ?? null)<p class="ar-help"><b>{{ $test['order_unit']['label'] }}</b>：{{ $test['order_unit']['reason'] }}</p>@endif
            @if(($test['shipping_unit']['mode'] ?? '') === 'PALLET')<p class="ar-help">1パレット {{ number_format($test['shipping_unit']['pieces_per_pallet']) }}バラ ／ 今回 {{ number_format($test['quantity_pieces'] / $test['shipping_unit']['pieces_per_pallet']) }}パレット</p>@endif
            @if(($test['shipping_unit']['mode'] ?? '') === 'SHIPMENT')<p class="ar-help"><b>出荷数量候補：</b>{{ implode('・', $test['shipping_unit']['quantities']) }}バラ ／ 今回 {{ number_format($test['quantity_pieces']) }}バラ。過剰在庫線・最大発注数に収まる候補を選択します。</p>@endif
            @if(($test['stock'] ?? 0) < 0)<p class="ar-help">実在庫 {{ number_format($test['stock']) }}バラに当日入荷予定 {{ number_format($test['incoming_today'] ?? 0) }}バラを加えて計算します。</p>@endif
            @if(($test['quantity_settings']['safety_days'] ?? null) !== null)<p class="ar-help">おすすめ算定時：安全在庫 {{ $test['quantity_settings']['safety_days'] }}日分（{{ number_format($test['quantity_settings']['safety_pieces']) }}バラ）。</p>@endif
            @if($test['quantity_settings'] ?? null)<p class="ar-help">@if($test['quantity_settings']['normal'] !== null)通常の目安 {{ number_format($test['quantity_settings']['normal']) }} ／ @endif最小 {{ number_format($test['quantity_settings']['minimum']) }} ／ 最大 {{ number_format($test['quantity_settings']['maximum']) }} ／ 過剰在庫線 {{ number_format($test['quantity_settings']['target']) }}バラ。通常量をまとめ、欠品見込時のみ小口で補充します。</p>@endif
            @if($test['phase'] === 'HOLD' && $test['calculated_pieces'] > 0)<p class="ar-error">計算上は {{ number_format($test['calculated_pieces']) }}バラ必要ですが、保留のため発注対象は0バラです。切り詰め・分割発注はしません。</p>@endif
            <section class="ar-test-formula"><h3>計算の要点 <small>総バラ</small></h3>
                @php $steps=$test['formula']; $shortSteps=count($steps)>4 ? [$steps[0],$steps[1],end($steps)] : $steps; @endphp
                @forelse($shortSteps as $step)<p>{{ str_replace('補充目標', '過剰在庫線', $step) }}</p>@empty<p>表示された保留理由を確認してください。</p>@endforelse
                @if(count($steps)>4)<details><summary>計算の詳細</summary>@foreach($steps as $step)<p>{{ str_replace('補充目標', '過剰在庫線', $step) }}</p>@endforeach</details>@endif
            </section>
            @include('filament.pages.auto-replenishment-demand-note', ['demandProfile' => $test['demand_profile'] ?? null])
            @if($test['simulation_at'] ?? null)<p class="ar-help">試算基準：{{ \Carbon\Carbon::parse($test['simulation_at'])->format('Y/m/d H:i') }}（倉庫の設定時刻）。グラフと同じ基準です。実際の発注は実行時刻で再判定します。</p>@endif
            <p class="ar-help">在庫・予定の取得：{{ $test['observed_at'] }} ／ 設定版 {{ $test['policy_version'] }}。確定時に在庫・既発注を再確認します。</p>
        @else
            <div class="ar-test-empty">「仮計算を実行」で、発注数量・{{ $logistics ? '出庫日・入庫日' : '入荷予定日' }}・計算式を確認できます。</div>
        @endif
        @foreach($errors->all() as $error)<p class="ar-error" role="alert">{{ $error }}</p>@endforeach
    </div>
    <footer><span class="ar-help ar-test-loading" wire:loading wire:target="runPolicyTest">仮計算中…</span><button type="button" class="ar-btn" wire:click="closePolicyTest" wire:loading.attr="disabled" wire:target="runPolicyTest">発注せず閉じる</button><button type="button" class="ar-btn danger" wire:click="runPolicyTest" wire:loading.attr="disabled" wire:target="runPolicyTest">{{ $test ? '再計算する' : '仮計算を実行' }}</button></footer>
</section>
