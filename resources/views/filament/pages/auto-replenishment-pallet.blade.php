<div class="ar-pallet-settings">
    <div class="ar-pallet-heading">
        <label>発注単位設定<select wire:model.live="form.shipping_unit_mode"><option value="STANDARD">通常（バラ・ケース・実績単位）</option><option value="SHIPMENT">登録済みの出荷数量候補</option><option value="PALLET">満載パレット単位（従来方式）</option></select></label>
        <span>@if(($this->form['shipping_unit_mode'] ?? '') === 'SHIPMENT')出荷数量候補 {{ count($this->shippingProfile['quantities'] ?? []) }}件@elseif($this->palletUnit)登録済み：<b>1パレット {{ number_format($this->palletUnit['pieces_per_pallet']) }}バラ</b>（{{ number_format($this->palletUnit['pieces_per_pallet'] / max(1,$this->palletUnit['capacity_case'])) }}ケース）@elseパレット入数は未登録です。@endif</span>
    </div>
    @include('filament.pages.auto-replenishment-shipping-quantities')
    @if(($this->form['shipping_unit_mode'] ?? '') === 'PALLET')
    <details @if(($this->form['shipping_unit_mode'] ?? '') === 'PALLET' && !$this->palletUnit) open @endif>
        <summary>共有パレット入数を登録・変更</summary>
        @php $palletSource=$warehouses->firstWhere('id',(int)($this->form['source_warehouse_id'] ?? 0)); @endphp
        <p class="ar-help">移動元 [{{ $palletSource?->code ?? '—' }}] {{ $palletSource?->name }} × 商品CD {{ $helperItem?->code ?? '—' }} の共有設定です。変更後は、利用している各倉庫の商品設定を再保存するまで次回の自動発注を保留します。</p>
        <div class="ar-pallet-input"><label>1パレット（総バラ）<input type="number" min="1" max="2147483647" step="1" wire:model="palletPieces" aria-label="1パレットの総バラ数"></label><span class="ar-help">ケース入数 {{ $helperItem?->capacity_case ?? '—' }} の倍数で入力</span><button type="button" class="ar-btn danger" wire:click="savePalletUnit" wire:loading.attr="disabled" wire:target="savePalletUnit,calculateSettings,form.shipping_unit_mode" @disabled(!$helperItem || empty($this->form['source_warehouse_id']))>共有パレット入数を保存</button></div>
        <p class="ar-help">出庫実績の共通単位から自動登録はしません。倉庫で使用している1パレットの数量を入力してください。</p>
    </details>
    @endif
    @if(($this->form['shipping_unit_mode'] ?? '') === 'PALLET')<p class="ar-help">おすすめ設定・仮計算・実際の発注をパレットの倍数に揃えます。商品上限を超えるパレット数は発注しません。</p>@endif
</div>
