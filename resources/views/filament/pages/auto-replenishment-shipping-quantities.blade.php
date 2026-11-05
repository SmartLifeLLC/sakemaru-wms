@php
    $shippingBasis = json_decode($this->shippingProfile['basis'] ?? '{}', true) ?: [];
    $caseLabel = \App\Enums\QuantityType::CASE->name();
    $pieceLabel = \App\Enums\QuantityType::PIECE->name();
@endphp
<details class="ar-shipping-picker" wire:ignore.self @if(($this->form['shipping_unit_mode'] ?? '') === 'SHIPMENT' && !$this->shippingProfile) open @endif
    wire:key="shipping-picker-{{ $this->form['item_id'] ?? 0 }}-{{ $this->form['source_warehouse_id'] ?? 0 }}-{{ $this->shippingProfile['version'] ?? 0 }}"
    x-data="{
        quantities: $wire.entangle('shippingQuantities'),
        options: @js($this->shippingProfile['quantities'] ?? []),
        capacity: @js((int) ($helperItem?->capacity_case ?? 0)),
        caseLabel: @js($caseLabel), pieceLabel: @js($pieceLabel),
        extraQuantity: '', extraUnit: @js($helperItem?->capacity_case > 0 ? 'CASE' : 'PIECE'), error: '',
        get selected() { return JSON.parse(this.quantities || '[]'); },
        get addedPieces() {
            return Number(String(this.extraQuantity).normalize('NFKC').trim()) * (this.extraUnit === 'CASE' ? this.capacity : 1);
        },
        choose(values) {
            this.quantities = JSON.stringify([...new Set(values)].sort((a, b) => a - b));
            this.error = '';
        },
        toggle(quantity) {
            this.choose(this.selected.includes(quantity) ? this.selected.filter(value => value !== quantity) : [...this.selected, quantity]);
        },
        isCase(quantity) { return this.capacity > 0 && quantity % this.capacity === 0; },
        label(quantity) { return (this.isCase(quantity) ? quantity / this.capacity : quantity).toLocaleString() + (this.isCase(quantity) ? this.caseLabel : this.pieceLabel); },
        add() {
            const value = String(this.extraQuantity).normalize('NFKC').trim();
            const pieces = this.addedPieces;
            if (!/^\d+$/.test(value) || !Number.isSafeInteger(pieces) || pieces < 1 || pieces > 2147483647) {
                this.error = '追加する数量は1以上の整数で入力してください（総バラの上限は2,147,483,647）。';
                return;
            }
            if (!this.options.includes(pieces)) {
                if (this.options.length >= 500) { this.error = '数量候補は500件まで登録できます。'; return; }
                this.options = [...this.options, pieces].sort((a, b) => a - b);
            }
            this.choose([...this.selected, pieces]);
            this.extraQuantity = '';
        }
    }">
    <summary>出荷数量候補を選択・登録</summary>
    <p class="ar-help">出荷に使う数量を選択してください。移動元倉庫×商品の共有設定です。</p>
    <fieldset class="ar-shipping-selection" wire:loading.attr="disabled" wire:target="saveShippingQuantities,registerShippingHistory,calculateSettings,form.shipping_unit_mode">
        <legend class="sr-only">出荷に使う数量（複数選択）</legend>
        <div class="ar-shipping-toolbar">
            <strong aria-live="polite"><span x-text="selected.length"></span>件選択<span class="ar-shipping-total"> / <span x-text="options.length"></span>件</span></strong>
            @if($helperItem?->capacity_case > 0)<span class="ar-help">1{{ $caseLabel }}＝{{ number_format($helperItem->capacity_case) }}{{ $pieceLabel }}</span>@endif
            <div class="ar-shipping-select-actions">
                <button type="button" class="ar-row-btn" @click="choose(options)" :disabled="options.length === 0">すべて選択</button>
                <button type="button" class="ar-row-btn" @click="choose([])" :disabled="selected.length === 0">選択を解除</button>
            </div>
        </div>
        <div class="ar-shipping-options" x-show="options.length > 0">
            <template x-for="quantity in options" :key="quantity">
                <label class="ar-shipping-option" :class="{'is-selected': selected.includes(quantity)}">
                    <input type="checkbox" :value="quantity" :checked="selected.includes(quantity)" @change="toggle(quantity)"
                        :aria-label="label(quantity) + (isCase(quantity) ? '（総バラ' + quantity.toLocaleString() + '）' : '（総バラ）')">
                    <span><strong x-text="label(quantity)"></strong><small x-text="'総バラ ' + quantity.toLocaleString()"></small></span>
                </label>
            </template>
        </div>
        <p class="ar-help ar-shipping-empty" x-show="options.length === 0">候補がありません。「実出庫から登録」または「数量を追加」を利用してください。</p>
        <p class="ar-shipping-empty-selection" x-cloak x-show="options.length > 0 && selected.length === 0">出荷に使う数量を1件以上選択してください。</p>
        <details class="ar-shipping-add" wire:ignore.self>
            <summary>数量を追加</summary>
            <div class="ar-shipping-add-row">
                <label>追加する数量<input type="text" inputmode="numeric" x-model="extraQuantity" @keydown.enter.prevent="add()" placeholder="例：2"></label>
                <label>単位<select x-model="extraUnit" aria-label="追加する数量の単位">@if($helperItem?->capacity_case > 0)<option value="CASE">{{ $caseLabel }}</option>@endif<option value="PIECE">{{ $pieceLabel }}</option></select></label>
                <span class="ar-help" x-text="Number.isSafeInteger(addedPieces) && addedPieces > 0 && addedPieces <= 2147483647 ? '総バラ ' + addedPieces.toLocaleString() : ''"></span>
                <button type="button" class="ar-btn" @click="add()">追加して選択</button>
            </div>
        </details>
        <p class="ar-error" role="alert" x-cloak x-show="error" x-text="error"></p>
        <div class="ar-shipping-footer">
            <span class="ar-help">@if($shippingBasis['period'] ?? null)実出庫 {{ $shippingBasis['period']['start'] }}〜{{ $shippingBasis['period']['end'] }} ／ {{ number_format($shippingBasis['documents'] ?? 0) }}回@endif</span>
            <button type="button" class="ar-btn" wire:click="registerShippingHistory" @disabled(!$helperItem || empty($this->form['delivery_course_id']))>実出庫から登録</button>
            <button type="button" class="ar-btn danger" wire:click="saveShippingQuantities" x-bind:disabled="selected.length === 0 || @js(!$helperItem || empty($this->form['source_warehouse_id']))">選択した数量を保存</button>
        </div>
    </fieldset>
    <p class="ar-help">変更後、利用店舗は商品設定の再保存まで保留します。自動発注のON／OFFは変わりません。</p>
    <details class="ar-shipping-evidence"><summary>実績の対象</summary><p class="ar-help">WMSの移動発注に紐づく実出庫を使用し、欠品付き実績は除外します。パレットの枚数・積み方を確定するものではありません。</p></details>
</details>
