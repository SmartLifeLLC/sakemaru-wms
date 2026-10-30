{{-- 日常操作の1行：安全在庫日数 → おすすめ設定 → 詳細設定。結果の要約を右に表示 --}}
<div class="ar-recommend-actions ar-recommend-actions-compact">
    <label class="ar-review-days">安全在庫日数<input type="number" min="1" max="28" step="1" wire:model="recommendationSafetyDays" aria-label="安全在庫日数"></label>
    <button type="button" class="ar-btn primary ar-recommend-button" wire:click="calculateSettings" wire:loading.attr="disabled" wire:target="calculateSettings,selectRecommendationCandidate,recommendationUnitMode,recommendationReviewDays,recommendationSafetyDays,savePalletUnit,saveShippingQuantities,registerShippingHistory,form.shipping_unit_mode,form.order_unit_mode,selectItem,form.item_id,form.channel,form.contractor_id,form.supplier_id,form.source_warehouse_id,form.delivery_course_id" @disabled(!$recommendationReady)><x-heroicon-o-sparkles aria-hidden="true" />おすすめ設定</button>
    <button type="button" class="ar-btn" @click="tab = 'advanced'" @disabled(!$helperItem)>詳細設定へ</button>
    <span class="ar-recommend-summary">
        @if($this->recommendation['available'] ?? false)
            発注点 <b>{{ number_format((int) ($this->form['reorder_point_pieces'] ?? 0)) }}</b>／補充目標 <b>{{ number_format((int) ($this->form['target_stock_pieces'] ?? 0)) }}</b>／最小 {{ number_format((int) ($this->form['min_order_pieces'] ?? 0)) }}〜最大 {{ number_format((int) ($this->form['max_order_pieces'] ?? 0)) }}（単位 {{ number_format((int) ($this->form['order_multiple_pieces'] ?? 0)) }}）バラ
            @php $appliedEvents = array_keys(array_filter($this->eventSetup ?? [])); @endphp
            ・イベント {{ $appliedEvents ? implode('・', array_map(fn ($t) => \App\Services\AutoReplenishment\DemandEventCalendar::NAMES[$t] ?? $t, $appliedEvents)) : 'なし' }}
        @elseif($helperItem)
            安全在庫日数を入れて「おすすめ設定」を押すと、数量と10週の在庫推移を計算します。
        @endif
    </span>
</div>
