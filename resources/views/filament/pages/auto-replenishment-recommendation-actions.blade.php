<div class="ar-recommend-actions">
    <div class="ar-recommend-copy"><strong>安全在庫日数を入力し、おすすめ設定をクリックしてください。</strong></div>
    <label title="{{ $orderUnit['reason'] ?? '商品を選択してください。' }}">発注単位<select wire:model.live="form.order_unit_mode" aria-label="発注単位" @disabled(!$helperItem)>
        <option value="AUTO">自動判定（{{ $orderUnit['automatic_label'] ?? '商品選択後' }}）</option>
        <option value="PIECE" @disabled(!($orderUnit['piece_orderable'] ?? false))>バラ発注{{ ($orderUnit['piece_orderable'] ?? false) ? '' : '（不可）' }}</option>
        <option value="CASE">ケース発注</option>
    </select></label>
    <label class="ar-review-days">安全在庫日数<input type="number" min="1" max="28" step="1" wire:model="recommendationSafetyDays" aria-label="安全在庫日数"></label>
    <button type="button" class="ar-btn primary ar-recommend-button" wire:click="calculateSettings" wire:loading.attr="disabled" wire:target="calculateSettings,selectRecommendationCandidate,recommendationUnitMode,recommendationReviewDays,recommendationSafetyDays,savePalletUnit,saveShippingQuantities,registerShippingHistory,form.shipping_unit_mode,form.order_unit_mode,selectItem,form.item_id,form.channel,form.contractor_id,form.supplier_id,form.source_warehouse_id,form.delivery_course_id" @disabled(!$recommendationReady)><x-heroicon-o-sparkles aria-hidden="true" />おすすめ設定</button>
</div>
<p class="ar-help">安全在庫日数は、入荷前に残しておきたい日数の目安です。「おすすめ設定」で数量へ反映します。</p>
@if(($this->recommendation['basis']['basis_type'] ?? '') === 'sales')
    <details class="ar-editor-helper"><summary>発注実績が少ない場合の1回量</summary><label class="ar-review-days">売上基準の日数<input type="number" min="1" max="28" step="1" wire:model="recommendationReviewDays" aria-label="売上基準の日数"></label><p class="ar-help">通常の1回量を何日分の売上から求めるかを指定します。安全在庫日数とは別です。</p></details>
@endif
@if($this->recommendationPreferencesChanged || $this->recommendationSafetyDays !== $this->appliedRecommendationSafetyDays)<p class="ar-help" role="status">算定条件を変更しました。「おすすめ設定」で再計算してください。</p>@endif
