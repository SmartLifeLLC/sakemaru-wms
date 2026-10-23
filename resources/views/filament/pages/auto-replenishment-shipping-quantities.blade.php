<details @if(($this->form['shipping_unit_mode'] ?? '') === 'SHIPMENT' && !$this->shippingProfile) open @endif>
    <summary>出荷数量候補を登録・確認（総バラ）</summary>
    <p class="ar-help">移動元倉庫×商品の共有設定です。WMSの移動発注に紐づく実出庫から作成し、欠品付き実績は除外します。パレットの枚数・積み方を確定するものではありません。</p>
    @if($this->shippingProfile)
        <div style="display:flex;flex-wrap:wrap;gap:5px;max-height:90px;overflow:auto;margin:6px 0">
            @foreach($this->shippingProfile['quantities'] as $quantity)
                <span class="ar-badge">{{ number_format($quantity) }}バラ @if($helperItem?->capacity_case > 0 && $quantity % $helperItem->capacity_case === 0)（{{ number_format($quantity / $helperItem->capacity_case) }}ケース）@endif</span>
            @endforeach
        </div>
        @php $shippingBasis=json_decode($this->shippingProfile['basis'] ?? '{}',true) ?: []; @endphp
        @if($shippingBasis['period'] ?? null)<p class="ar-help">実績 {{ $shippingBasis['period']['start'] }}〜{{ $shippingBasis['period']['end'] }} ／ {{ number_format($shippingBasis['documents'] ?? 0) }}回</p>@endif
    @else<p class="ar-help">出荷数量候補は未登録です。</p>@endif
    <div class="ar-pallet-input">
        <label style="flex:1;min-width:240px">数量候補（JSON配列）<input type="text" wire:model="shippingQuantities" placeholder="[384,576,768,1728]" aria-label="出荷数量候補のJSON配列"></label>
        <button type="button" class="ar-btn danger" wire:click="saveShippingQuantities" wire:loading.attr="disabled" wire:target="saveShippingQuantities,registerShippingHistory,calculateSettings" @disabled(!$helperItem || empty($this->form['source_warehouse_id']))>候補を保存</button>
        <button type="button" class="ar-btn" wire:click="registerShippingHistory" wire:loading.attr="disabled" wire:target="saveShippingQuantities,registerShippingHistory,calculateSettings" @disabled(!$helperItem || empty($this->form['delivery_course_id']))>実出庫から登録</button>
    </div>
    <p class="ar-help">候補の変更後、利用中の店舗は商品設定を再保存するまで保留します。登録だけでは自動発注はONになりません。</p>
</details>
