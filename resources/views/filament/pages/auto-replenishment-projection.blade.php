<section class="ar-projection" aria-label="2週間の在庫推移" x-data="{ changed: false }"
    @ar-quantity-change.window="changed = true"
    @ar-quantity-change.window.debounce.700ms="if ($wire.inventoryProjectionInputs) { $wire.refreshInventoryProjection().then(() => changed = false) }">
    @php $chart = $this->inventoryProjection; @endphp
    <div class="ar-projection-heading">
        <strong>2週間の在庫推移</strong>
        <div class="ar-projection-actions">
        @if($chart)
            <div x-data wire:key="projection-help-{{ hash('sha256',json_encode($chart)) }}">
                <button type="button" class="ar-btn ar-projection-help" aria-label="在庫推移の解説"
                    x-tooltip="{ content: '', onShow: tip => tip.setContent($refs.explanation.innerHTML), allowHTML: true, interactive: true, hideOnClick: false, theme: 'auto-order', duration: 0, maxWidth: 'min(560px, calc(100vw - 40px))', placement: 'left', appendTo: () => $el.closest('.ar-editor'), popperOptions: { modifiers: [{ name: 'preventOverflow', options: { altAxis: true, tether: false, padding: 12, boundary: $el.closest('.ar-editor') } }] }, trigger: 'mouseenter focus', aria: { content: 'describedby', expanded: false } }"
                    @touchend.prevent="$el._tippy.show()" @click="$el._tippy.show()" @click.outside="if (!$el._tippy?.popper.contains($event.target)) $el._tippy?.hide()" @keydown.escape.window.capture="if ($el._tippy?.state.isVisible) { $el._tippy.hide(); $event.stopImmediatePropagation() }">ⓘ 解説</button>
                <div hidden x-ref="explanation">@include('filament.pages.auto-replenishment-projection-help')</div>
            </div>
        @endif
        <button type="button" class="ar-btn" wire:click="refreshInventoryProjection(true)" wire:loading.attr="disabled" wire:target="refreshInventoryProjection,calculateSettings">{{ $this->inventoryProjection ? '在庫・予定を再取得' : '在庫推移を表示' }}</button>
        </div>
    </div>
    <p class="ar-help" wire:loading wire:target="refreshInventoryProjection,calculateSettings">在庫推移を計算しています…</p>
    @if($this->inventoryProjectionError)<p class="ar-projection-warning" role="status">{{ $this->inventoryProjectionError }}</p>@endif
    @if($this->inventoryProjection)
        @php
            $chart=$this->inventoryProjection;
            $days=$chart['days'];
            $low=min(0, $chart['initial_stock'], ...array_column($days,'stock'));
            $high=max(1, $chart['point'], $chart['target'], ...array_column($days,'after_receipt'), ...array_column($days,'demand'), ...array_column($days,'received'));
            $range=max(1,$high-$low); $top=24; $bottom=204;
            $y=fn($q)=>round($bottom-($q-$low)/$range*($bottom-$top),2);
            $x=fn($i)=>66+$i*64;
            $path=[];
            foreach($days as $i=>$day) {
                $path[]=($i===0?'M':'L').$x($i).','.$y($day['opening']);
                $path[]='L'.($x($i)+12).','.$y($day['opening']);
                $path[]='L'.($x($i)+12).','.$y($day['after_receipt']);
                $path[]='L'.($x($i)+56).','.$y($day['stock']);
            }
            $orderText=fn($q)=>$chart['quantity_type']==='CASE' && $q % $chart['capacity_case']===0
                ? number_format(intdiv($q,$chart['capacity_case'])).'ケース（'.number_format($q).'バラ）' : number_format($q).'バラ';
            $minimumX=$chart['minimum_stock_timing']==='日末' ? $x(array_search($chart['minimum_stock_date'],array_column($days,'date'),true))+56 : $x(0);
            $chartKey=hash('sha256',json_encode($chart));
        @endphp
        <div class="ar-projection-result" :class="{ 'is-updating': changed }" wire:key="projection-{{ $chartKey }}" x-data="{ selected: null }">
            <div class="ar-projection-totals">
                <span>現在庫 <b>{{ number_format($chart['initial_stock']) }}</b> バラ</span>
                <span>発注見込 <b>{{ $chart['order_count'] }}</b> 回</span>
                <span @class(['ar-projection-risk'=>$chart['low_stock_buffer']])>最低在庫 <b>{{ number_format($chart['minimum_stock']) }}</b> バラ（{{ substr($chart['minimum_stock_date'],5) }} {{ $chart['minimum_stock_timing'] }}）</span>
                <span>2週間後 <b>{{ number_format($chart['end_stock']) }}</b> バラ</span>
                <span @class(['ar-projection-risk'=>$chart['shortage_days']>0])>不足見込 <b>{{ $chart['shortage_days'] }}</b> 日</span>
            </div>
            <div class="ar-projection-legend"><span class="stock">━ 在庫</span><span class="demand">■ 消費</span><span class="receipt">■ 入荷</span><span class="order">◆ 発注</span><span class="deferred">○ 発注点未満・発注なし</span><span class="point">┄ 発注点</span><span class="target">━ 補充目標</span><small>数量：総バラ</small></div>
            <div class="ar-projection-scroll">
                <svg class="ar-projection-chart" viewBox="0 0 980 250" role="img" aria-label="{{ $chart['start'] }}から{{ $chart['end'] }}までの在庫・消費・入荷・発注の試算。各日を選択すると内訳を表示します。">
                    @for($tick=0;$tick<=4;$tick++)
                        @php $value=$low+$range*$tick/4; @endphp
                        <line x1="60" x2="966" y1="{{ $y($value) }}" y2="{{ $y($value) }}" stroke="var(--ar-projection-grid)"/>
                        <text x="53" y="{{ $y($value)+4 }}" text-anchor="end" class="ar-projection-axis">{{ number_format($value) }}</text>
                    @endfor
                    <line class="ar-projection-target-line" x1="60" x2="966" y1="{{ $y($chart['target']) }}" y2="{{ $y($chart['target']) }}" stroke-width="1.7"><title>補充目標 {{ number_format($chart['target']) }}バラ</title></line>
                    @if($chart['point'] !== $chart['target'])<line class="ar-projection-point-line" x1="60" x2="966" y1="{{ $y($chart['point']) }}" y2="{{ $y($chart['point']) }}" stroke-width="1.7" stroke-dasharray="8 5"><title>発注点 {{ number_format($chart['point']) }}バラ</title></line>@endif
                    @foreach($days as $i=>$day)
                        @if($day['unmet']>0)<rect x="{{ $x($i) }}" y="{{ $top }}" width="60" height="{{ $bottom-$top }}" fill="#ef4444" opacity="0.07"/>@endif
                        <rect x="{{ $x($i)+22 }}" y="{{ $y($day['demand']) }}" width="13" height="{{ $y(0)-$y($day['demand']) }}" fill="#e2a544" opacity="0.65"><title>消費見込 {{ $day['demand'] }}バラ</title></rect>
                        <rect x="{{ $x($i)+38 }}" y="{{ $y($day['received']) }}" width="13" height="{{ $y(0)-$y($day['received']) }}" fill="#16a085" opacity="0.75"><title>入荷 {{ $day['received'] }}バラ</title></rect>
                        <text x="{{ $x($i)+29 }}" y="223" text-anchor="middle" class="ar-projection-axis">{{ substr($day['date'],5) }}</text>
                        <text x="{{ $x($i)+29 }}" y="241" text-anchor="middle" class="ar-projection-axis" @if($day['weekday']===0) style="fill:#dc2626" @endif>{{ ['日','月','火','水','木','金','土'][$day['weekday']] }}</text>
                    @endforeach
                    <path d="{{ implode(' ',$path) }}" fill="none" stroke="#2563eb" stroke-width="2.7" stroke-linejoin="round"/>
                    @foreach($days as $i=>$day)
                        @if($day['ordered']>0)<path d="M{{ $x($i)+12 }},{{ $y($day['after_receipt'])-6 }} l6,6 -6,6 -6,-6 Z" fill="#8b5cf6" stroke="white" stroke-width="1.5"><title>{{ $orderText($day['ordered']) }}発注 → {{ $day['arrival_date'] }}入荷</title></path>
                        @elseif($day['below_point'])<circle class="ar-projection-deferred" cx="{{ $x($i)+12 }}" cy="{{ $y($day['after_receipt']) }}" r="4" stroke-width="2"><title>{{ $day['reason'] }}</title></circle>@endif
                        <rect x="{{ $x($i) }}" y="14" width="62" height="232" fill="transparent" class="ar-projection-hit" tabindex="0" role="button"
                            aria-label="{{ $day['label'] }}：在庫{{ $day['stock'] }}、消費{{ $day['demand'] }}、入荷{{ $day['received'] }}、発注{{ $day['ordered'] }}バラ"
                            :class="{ selected: selected === {{ $i }} }"
                            x-tooltip="{ content: '', onShow: tip => { selected = {{ $i }}; tip.setContent($refs.dayTip{{ $i }}.innerHTML) }, onHidden: () => { if (selected === {{ $i }}) selected = null }, allowHTML: true, interactive: true, hideOnClick: false, theme: 'auto-order', duration: 0, maxWidth: 'min(440px, calc(100vw - 40px))', appendTo: () => $el.closest('.ar-editor'), popperOptions: { modifiers: [{ name: 'preventOverflow', options: { altAxis: true, tether: false, padding: 12, boundary: $el.closest('.ar-editor') } }] }, trigger: 'mouseenter focus', aria: { content: 'describedby', expanded: false } }"
                            @keydown.enter.prevent="$el._tippy.show()" @keydown.space.prevent="$el._tippy.show()"
                            @touchend.prevent="$el._tippy.show()" @click="$el._tippy.show()" @click.outside="if (!$el._tippy?.popper.contains($event.target)) $el._tippy?.hide()" @keydown.escape.window.capture="if ($el._tippy?.state.isVisible) { $el._tippy.hide(); $event.stopImmediatePropagation() }"/>
                    @endforeach
                    <circle class="ar-projection-minimum" cx="{{ $minimumX }}" cy="{{ $y($chart['minimum_stock']) }}" r="4" fill="{{ $chart['low_stock_buffer'] ? '#b91c1c' : '#2563eb' }}" stroke="white" stroke-width="1.5"><title>最低在庫 {{ number_format($chart['minimum_stock']) }}バラ（{{ $chart['minimum_stock_date'] }} {{ $chart['minimum_stock_timing'] }}）</title></circle>
                    <text x="{{ $minimumX }}" y="{{ $y($chart['minimum_stock'])-9 }}" text-anchor="{{ $minimumX > 900 ? 'end' : 'start' }}" class="ar-projection-line-label {{ $chart['low_stock_buffer'] ? 'ar-projection-risk' : 'stock' }}">最低 {{ number_format($chart['minimum_stock']) }}</text>
                    @if($chart['point'] === $chart['target'])
                        <text x="68" y="{{ $y($chart['target'])-7 }}" class="ar-projection-line-label target">発注点・補充目標 {{ number_format($chart['target']) }}バラ</text>
                    @else
                        <text x="68" y="{{ $y($chart['point'])-7 }}" class="ar-projection-line-label point">発注点 {{ number_format($chart['point']) }}バラ</text>
                        <text x="956" y="{{ $y($chart['target'])-7 }}" text-anchor="end" class="ar-projection-line-label target">補充目標 {{ number_format($chart['target']) }}バラ</text>
                    @endif
                </svg>
            </div>
            <div hidden class="ar-projection-day">
                @foreach($days as $i=>$day)
                    <div x-ref="dayTip{{ $i }}">
                        <b>{{ $day['label'] }}</b>　{{ number_format($day['opening']) }} ＋ 入荷 <b class="receipt">{{ number_format($day['received']) }}</b> − 消費 {{ number_format(min($day['demand'],max(0,$day['after_receipt']))) }} ＝ <b>{{ number_format($day['stock']) }}バラ</b>
                        @if($day['received'])<small>入荷内訳：既発注 {{ number_format($day['existing_receipt']) }} ／ 今回の試算 {{ number_format($day['new_receipt']) }}バラ</small>@endif
                        @if($day['ordered'])<small class="order"><b>{{ $orderText($day['ordered']) }}発注</b> → {{ $day['arrival_date'] }}入荷 @if($day['picking_date'])（出庫 {{ $day['picking_date'] }}）@endif</small>
                        @else<small><b class="deferred">{{ $day['below_point'] ? '○ 発注点未満・発注なし' : '発注なし' }}</b>：{{ $day['reason'] }}</small>@endif
                        @if(!$day['below_point'] && $day['stock']<$chart['point'])<small>この日は発注判定後の消費で発注点を下回ります。次の実行日に判定します。</small>@endif
                        <details class="ar-projection-decision"><summary>この日の発注判定を見る</summary>
                            <p>判定時の在庫 {{ number_format($day['after_receipt']) }} ／ 発注点 {{ number_format($chart['point']) }} ／ 入荷予定 {{ number_format($day['pending_at_decision']) }} ／ 未確定候補 {{ number_format($chart['claims']) }}バラ</p>
                            @foreach($day['formula'] as $formula)<p>{{ $formula }}</p>@endforeach
                        </details>
                        @if($day['unmet']>0)<small class="ar-projection-risk">消費見込 {{ number_format($day['demand']) }}バラのうち、{{ number_format($day['unmet']) }}バラ不足する見込みです。</small>@endif
                    </div>
                @endforeach
            </div>
        </div>
    @elseif(!$this->inventoryProjectionError)
        <p class="ar-help">「おすすめ設定」と一緒に表示します。数量の変更に合わせて、発注・入荷後の在庫を確認できます。</p>
    @endif
</section>
