@if($this->showItemPicker)
    <section class="ar-item-picker" aria-label="商品検索" x-data="{ searchTerm: '' }" x-init="searchTerm = $wire.itemSearch" @input.capture="searchTerm = $event.target.value">
        <div class="ar-item-search-bar">
            <label for="ar-item-search">商品を検索<input id="ar-item-search" type="search" wire:model.live.debounce.400ms="itemSearch" placeholder="商品CD・商品名（スペースで絞り込み）" autocomplete="off" aria-controls="ar-item-results"></label>
            @if($helperItem)<button type="button" class="ar-btn" wire:click="$set('showItemPicker', false)">選択を変えず閉じる</button>@endif
        </div>
        <p class="ar-item-search-hint" wire:key="ar-item-search-pending-{{ hash('sha256', $this->itemSearch) }}" role="status" x-show="searchTerm !== @js($this->itemSearch)" x-cloak>検索中…</p>
        <div class="ar-item-results" id="ar-item-results" wire:key="ar-item-results-{{ hash('sha256', $this->itemSearch) }}" x-show="searchTerm === @js($this->itemSearch)" wire:loading.class="ar-item-results-loading" wire:target="itemSearch,selectItem">
            @if($itemSearchTerm === '')
                <p class="ar-item-search-hint">商品CDまたは商品名を入力してください。</p>
            @elseif($itemOptions->isEmpty())
                <p class="ar-item-search-hint" role="status">該当する商品がありません。商品CD・商品名を変えて検索してください。</p>
            @else
                <table><thead><tr><th>商品CD</th><th>商品名</th><th>ケース入数</th><th>操作</th></tr></thead><tbody>
                    @foreach($itemOptions as $item)
                        <tr wire:key="ar-item-{{ $item->id }}" @class(['is-selected' => (int)($this->form['item_id'] ?? 0) === (int)$item->id])>
                            <td class="ar-code">{{ $item->code }}</td><td class="ar-item-name">{{ $item->name }}</td><td class="num">{{ $item->capacity_case ?: '—' }}</td>
                            <td><button type="button" class="ar-row-btn" wire:click="selectItem({{ $item->id }})" wire:loading.attr="disabled" wire:target="itemSearch,selectItem" aria-label="商品{{ $item->code }} {{ $item->name }}を選択">{{ (int)($this->form['item_id'] ?? 0) === (int)$item->id ? '選択中' : '選択' }}</button></td>
                        </tr>
                    @endforeach
                </tbody></table>
            @endif
        </div>
        <div class="ar-item-search-footer"><span wire:key="ar-item-count-{{ hash('sha256', $this->itemSearch) }}" x-show="searchTerm === @js($this->itemSearch)" wire:loading.remove wire:target="itemSearch,selectItem">@if($itemSearchTerm !== ''){{ $itemSearchHasMore ? '先頭30件を表示。商品名を追加して絞り込めます。' : $itemOptions->count().'件' }}@endif</span><span role="status" wire:loading wire:target="itemSearch,selectItem">商品を確認しています…</span></div>
    </section>
@endif
