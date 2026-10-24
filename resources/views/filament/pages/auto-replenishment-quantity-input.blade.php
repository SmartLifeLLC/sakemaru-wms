@php
    $caseInput = ($orderUnit['quantity_type'] ?? 'PIECE') === 'CASE';
    $factor = $caseInput ? max(1, (int) ($orderUnit['capacity_case'] ?? 1)) : 1;
    $readonly = $key === 'order_multiple_pieces' && in_array($this->form['shipping_unit_mode'] ?? '', ['PALLET', 'SHIPMENT'], true);
    $inputId = 'ar-quantity-'.$key;
@endphp
<div class="ar-quantity-field" wire:key="quantity-{{ $this->form['item_id'] ?? 0 }}-{{ $key }}-{{ $caseInput ? 'CASE' : 'PIECE' }}-{{ $factor }}-{{ (int) $readonly }}"
    x-data="{
        get total() { return $wire.form.{{ $key }} ?? null },
        set total(value) { $wire.form.{{ $key }} = value; $dispatch('ar-quantity-change') },
        factor: {{ $factor }},
        remainderOpen: false,
        inputError: '',
        empty() { return this.total === null || this.total === '' || this.total === undefined },
        units() { return this.empty() ? '' : Math.floor(Number(this.total) / this.factor) },
        remainder() { return this.empty() ? 0 : Number(this.total) % this.factor },
        update(input, remainder = false) {
            input.setCustomValidity('');
            this.inputError = '';
            if (input.validity.badInput) {
                this.inputError = '数量は整数で入力してください。';
                input.setCustomValidity(this.inputError);
                return;
            }
            if (input.value === '') {
                if (remainder) this.total = Number(this.units()) * this.factor;
                else this.total = null;
                return;
            }
            const value = Number(input.value);
            const next = remainder ? Number(this.units()) * this.factor + value : value * this.factor + this.remainder();
            if (!Number.isInteger(value) || value < 0 || (remainder &amp;&amp; value >= this.factor) || next > 2147483647) {
                this.inputError = remainder ? '端数は0から' + (this.factor - 1) + 'バラで入力してください。' : '数量は上限内の0以上の整数で入力してください。';
                input.setCustomValidity(this.inputError);
                return;
            }
            this.total = next;
        },
        finish(input, remainder = false) {
            this.update(input, remainder);
            if (!input.checkValidity()) {
                this.inputError += '元の数量に戻しました。';
                input.value = remainder ? this.remainder() : this.units();
                input.setCustomValidity('');
            }
        }
    }">
    <label for="{{ $inputId }}">{{ $label }}</label>
    <div class="ar-unit-input">
        <input id="{{ $inputId }}" type="number" min="0" step="1" max="{{ intdiv(2147483647, $factor) }}"
            :value="units()" @input="update($el)" @change="finish($el)" @readonly($readonly)
            @if($key === 'target_stock_pieces') placeholder="未入力は発注点" @endif
            aria-describedby="{{ $inputId }}-total">
        <span>{{ $caseInput ? \App\Enums\QuantityType::CASE->name() : \App\Enums\QuantityType::PIECE->name() }}</span>
    </div>
    <small class="ar-error" role="alert" x-cloak x-show="inputError" x-text="inputError"></small>
    @if($caseInput)
        <div class="ar-quantity-remainder" x-cloak x-show="remainder() > 0 || remainderOpen">
            <span>＋</span><input type="number" min="0" max="{{ $factor - 1 }}" step="1" :value="remainder()"
                @input="update($el, true)" @change="finish($el, true)" @readonly($readonly)
                @focus="remainderOpen = true" aria-label="{{ $label }}の端数（バラ）"><span>バラ</span>
        </div>
    @endif
    <div class="ar-quantity-total" id="{{ $inputId }}-total">
        @if($caseInput)
            <span x-text="empty() ? '総バラ：—' : '総バラ：' + Number(total).toLocaleString('ja-JP')"></span>
            @unless($readonly || $factor <= 1)<button type="button" x-show="!empty() &amp;&amp; !remainder() &amp;&amp; !remainderOpen" @click="remainderOpen = true; $nextTick(() => $root.querySelector('.ar-quantity-remainder input').focus())" aria-label="{{ $label }}の端数を入力">端数</button>@endunless
        @endif
    </div>
</div>
