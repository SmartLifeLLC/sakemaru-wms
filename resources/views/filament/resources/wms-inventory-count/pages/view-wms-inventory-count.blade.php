<x-filament-panels::page class="overflow-hidden">
    @php
        $record = $this->record;
        $rows = $this->rows();
        $totalCount = $this->totalCount();
        $allCount = $this->countForTab('all');
        $diffCount = $this->countForTab('diff');
        $uncountedCount = $this->countForTab('uncounted');
        $hasMore = $rows->count() < $totalCount;
        $isEditable = in_array($record->status, [
            \App\Models\WmsInventoryCount::STATUS_COUNTING,
            \App\Models\WmsInventoryCount::STATUS_CHECKED,
        ]);
        $filterInputClass = 'h-8 w-full rounded-md border border-slate-300 bg-slate-50 px-2 text-xs text-slate-900 shadow-inner outline-none transition placeholder:text-slate-400 focus:border-sky-500 focus:bg-white focus:ring-1 focus:ring-sky-500';
        $filterSelectClass = 'h-8 w-full rounded-md border border-slate-300 bg-slate-50 px-2 text-xs text-slate-900 shadow-inner outline-none transition focus:border-sky-500 focus:bg-white focus:ring-1 focus:ring-sky-500';
        $countInputClass = 'w-20 h-7 rounded border border-slate-300 bg-white px-1 text-right text-xs tabular-nums font-bold outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500';
        $statusColors = [
            'draft' => 'bg-slate-200 text-slate-700',
            'counting' => 'bg-sky-100 text-sky-700',
            'checked' => 'bg-amber-100 text-amber-700',
            'confirmed' => 'bg-green-100 text-green-700',
            'cancelled' => 'bg-red-100 text-red-700',
        ];
    @endphp

    <div x-data="{
        filtersOpen: true,
        changes: {},
        setChange(id, field, value, origFirst, origSecond, origFinal, first, second, final_) {
            let changed = (first !== origFirst || second !== origSecond || final_ !== origFinal);
            if (changed) {
                this.changes[id] = { first: first === '' ? null : parseInt(first), second: second === '' ? null : parseInt(second), final: final_ === '' ? null : parseInt(final_) };
            } else {
                delete this.changes[id];
            }
        },
        get changeCount() { return Object.keys(this.changes).length; },
        save() {
            if (!this.changeCount) return;
            this.$wire.saveInlineChanges(this.changes).then(() => { this.changes = {}; });
        }
    }" @count-update="setChange($event.detail.id, $event.detail.field, $event.detail.value, $event.detail.origFirst, $event.detail.origSecond, $event.detail.origFinal, $event.detail.first, $event.detail.second, $event.detail.final)"
    class="flex h-[calc(100vh-72px)] min-h-0 flex-col gap-2">
        {{-- Header bar --}}
        <div class="shrink-0 overflow-hidden rounded-lg border border-slate-300 bg-slate-100 shadow-sm">
            <div class="flex items-center justify-between border-b border-slate-200 bg-slate-800 px-3 py-2 text-white">
                <div class="flex min-w-0 items-center gap-3">
                    <span class="truncate text-xs text-slate-300">
                        {{ $record->count_no }}
                        / {{ $record->warehouse_name }}
                        / {{ $record->count_date?->format('Y/m/d') }}
                    </span>
                    <span class="rounded-full px-2 py-0.5 text-[11px] font-bold {{ $statusColors[$record->status] ?? 'bg-slate-200 text-slate-700' }}">
                        {{ $record->status_label }}
                    </span>
                    <span class="text-xs text-slate-400">
                        全{{ number_format($allCount) }}件
                        / 差異{{ number_format($diffCount) }}件
                        / 未カウント{{ number_format($uncountedCount) }}件
                    </span>
                </div>
                <button type="button"
                    class="inline-flex items-center gap-1 rounded-md border border-slate-500 px-2 py-1 text-xs font-semibold text-slate-100 hover:bg-slate-700"
                    @click="filtersOpen = ! filtersOpen">
                    <x-filament::icon icon="heroicon-m-magnifying-glass" class="h-4 w-4" />
                    <span>検索条件</span>
                    <x-filament::icon icon="heroicon-m-chevron-down" class="h-4 w-4 transition" x-bind:class="{ 'rotate-180': filtersOpen }" />
                </button>
            </div>

            {{-- Filter form --}}
            <form wire:submit.prevent="search" x-show="filtersOpen" x-collapse x-cloak class="bg-slate-100 p-2">
                <div class="grid grid-cols-2 items-end gap-2 md:grid-cols-6 xl:grid-cols-12">
                    <label class="space-y-1 md:col-span-2">
                        <span class="text-xs font-semibold text-slate-700">フロア</span>
                        <select wire:model="floorFilter" class="{{ $filterSelectClass }}">
                            <option value="">すべて</option>
                            @foreach ($this->floorOptions() as $floor)
                                <option value="{{ $floor }}">{{ $floor }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="space-y-1 md:col-span-2">
                        <span class="text-xs font-semibold text-slate-700">エリア</span>
                        <input type="text" wire:model.defer="areaFilter" placeholder="エリア検索" class="{{ $filterInputClass }}">
                    </label>
                    <label class="space-y-1 md:col-span-2">
                        <span class="text-xs font-semibold text-slate-700">商品CD</span>
                        <input type="text" wire:model.defer="itemCodeFilter" placeholder="商品CD検索" class="{{ $filterInputClass }}">
                    </label>
                    <label class="space-y-1 md:col-span-2">
                        <span class="text-xs font-semibold text-slate-700">ロケーション</span>
                        <input type="text" wire:model.defer="locationFilter" placeholder="ロケーション検索" class="{{ $filterInputClass }}">
                    </label>
                    <label class="space-y-1 md:col-span-2">
                        <span class="text-xs font-semibold text-slate-700">商品名</span>
                        <input type="text" wire:model.defer="itemNameFilter" placeholder="商品名検索" class="{{ $filterInputClass }}">
                    </label>
                    <div class="flex items-end justify-end gap-2 md:col-span-2">
                        <button type="button" wire:click="clearFilters" class="h-8 rounded-md border border-slate-300 px-3 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                            クリア
                        </button>
                        <button type="submit" class="h-8 rounded-md bg-slate-800 px-4 text-xs font-bold text-white hover:bg-slate-700">
                            検索
                        </button>
                    </div>
                </div>
            </form>
        </div>

        {{-- Tab bar + table --}}
        <div class="flex min-h-0 flex-1 flex-col overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-wrap items-end justify-between gap-2 border-b border-slate-200 bg-green-700 px-3 pt-2 text-white">
                <div class="flex items-end gap-1">
                    <button type="button"
                        wire:click="setListTab('all')"
                        class="relative inline-flex h-10 items-center gap-2 rounded-t-md border px-3 text-xs font-bold transition {{ $this->listTab === 'all' ? 'border-slate-200 border-b-white bg-white text-green-800 shadow-sm' : 'border-green-700 bg-green-800 text-white/85 hover:bg-green-900 hover:text-white' }}">
                        @if ($this->listTab === 'all')
                            <span class="absolute inset-x-2 top-0 h-0.5 rounded-full bg-green-600"></span>
                        @endif
                        <span>全件</span>
                        <span class="rounded-full px-2 py-0.5 text-[11px] font-black tabular-nums {{ $this->listTab === 'all' ? 'bg-green-100 text-green-800' : 'bg-white/15 text-white ring-1 ring-white/25' }}">
                            {{ number_format($allCount) }}
                        </span>
                    </button>
                    <button type="button"
                        wire:click="setListTab('diff')"
                        class="relative inline-flex h-10 items-center gap-2 rounded-t-md border px-3 text-xs font-bold transition {{ $this->listTab === 'diff' ? 'border-slate-200 border-b-white bg-white text-red-700 shadow-sm' : 'border-green-700 bg-green-800 text-white/85 hover:bg-green-900 hover:text-white' }}">
                        @if ($this->listTab === 'diff')
                            <span class="absolute inset-x-2 top-0 h-0.5 rounded-full bg-red-600"></span>
                        @endif
                        <span>差異あり</span>
                        <span class="rounded-full px-2 py-0.5 text-[11px] font-black tabular-nums {{ $this->listTab === 'diff' ? 'bg-red-100 text-red-700' : 'bg-white/15 text-white ring-1 ring-white/25' }}">
                            {{ number_format($diffCount) }}
                        </span>
                    </button>
                    <button type="button"
                        wire:click="setListTab('uncounted')"
                        class="relative inline-flex h-10 items-center gap-2 rounded-t-md border px-3 text-xs font-bold transition {{ $this->listTab === 'uncounted' ? 'border-slate-200 border-b-white bg-white text-amber-700 shadow-sm' : 'border-green-700 bg-green-800 text-white/85 hover:bg-green-900 hover:text-white' }}">
                        @if ($this->listTab === 'uncounted')
                            <span class="absolute inset-x-2 top-0 h-0.5 rounded-full bg-amber-500"></span>
                        @endif
                        <span>未カウント</span>
                        <span class="rounded-full px-2 py-0.5 text-[11px] font-black tabular-nums {{ $this->listTab === 'uncounted' ? 'bg-amber-100 text-amber-700' : 'bg-white/15 text-white ring-1 ring-white/25' }}">
                            {{ number_format($uncountedCount) }}
                        </span>
                    </button>
                </div>
                <div class="flex items-center gap-3 pb-2">
                    <div class="rounded-full bg-green-900/40 px-3 py-1 text-sm font-black text-white tabular-nums">{{ number_format($rows->count()) }} / {{ number_format($totalCount) }}件</div>
                    @if ($isEditable)
                        <button type="button" @click="save()" x-show="changeCount > 0" x-cloak
                            class="inline-flex items-center gap-2 rounded-md bg-red-600 px-4 py-1.5 text-sm font-bold text-white shadow-sm hover:bg-red-700">
                            <x-filament::icon icon="heroicon-m-arrow-up-tray" class="h-4 w-4" />
                            <span>保存</span>
                            <span class="rounded-full bg-white/20 px-2 py-0.5 text-xs font-black" x-text="changeCount + '件'"></span>
                        </button>
                    @endif
                    {{ $this->getAction('downloadInstructionPdf') }}
                    {{ $this->getAction('startCounting') }}
                    {{ $this->getAction('calculateDifferences') }}
                    {{ $this->getAction('downloadDiffListPdf') }}
                    {{ $this->getAction('confirm') }}
                    {{ $this->getAction('cancel') }}
                    <div wire:loading class="text-xs">読込中...</div>
                </div>
            </div>

            {{-- Table --}}
            <div class="min-h-0 flex-1 overflow-auto">
                @if ($rows->isEmpty())
                    <div class="p-8 text-center text-sm text-slate-500">条件に一致する明細はありません。</div>
                @else
                    <table class="w-max min-w-full border-collapse text-xs">
                        <thead class="sticky top-0 z-10 bg-slate-100 text-slate-700">
                            <tr>
                                <th class="border border-slate-300 px-2 py-2 text-left">フロア</th>
                                <th class="border border-slate-300 px-2 py-2 text-left">エリア</th>
                                <th class="border border-slate-300 px-2 py-2 text-left">ロケーション</th>
                                <th class="border border-slate-300 px-2 py-2 text-left">
                                    <button type="button" wire:click="sortBy('item_code')" class="inline-flex items-center gap-1 font-bold hover:text-sky-700">
                                        <span>商品CD</span>
                                        <span class="text-[10px]">{{ $this->sortIndicator('item_code') }}</span>
                                    </button>
                                </th>
                                <th class="border border-slate-300 px-2 py-2 text-left">
                                    <button type="button" wire:click="sortBy('item_name')" class="inline-flex items-center gap-1 font-bold hover:text-sky-700">
                                        <span>商品名</span>
                                        <span class="text-[10px]">{{ $this->sortIndicator('item_name') }}</span>
                                    </button>
                                </th>
                                <th class="border border-slate-300 px-2 py-2 text-right">
                                    <button type="button" wire:click="sortBy('system_quantity')" class="inline-flex items-center gap-1 font-bold hover:text-sky-700">
                                        <span>理論数量</span>
                                        <span class="text-[10px]">{{ $this->sortIndicator('system_quantity') }}</span>
                                    </button>
                                </th>
                                <th class="border border-slate-300 px-2 py-2 text-right">1回目</th>
                                <th class="border border-slate-300 px-2 py-2 text-right">2回目</th>
                                <th class="border border-slate-300 px-2 py-2 text-right">最終</th>
                                <th class="border border-slate-300 px-2 py-2 text-right">
                                    <button type="button" wire:click="sortBy('difference_quantity')" class="inline-flex items-center gap-1 font-bold hover:text-sky-700">
                                        <span>差異数量</span>
                                        <span class="text-[10px]">{{ $this->sortIndicator('difference_quantity') }}</span>
                                    </button>
                                </th>
                                <th class="border border-slate-300 px-2 py-2 text-right">差異金額</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rows as $row)
                                @php
                                    $initFirst = $row->first_count_quantity !== null ? (string) (int) $row->first_count_quantity : '';
                                    $initSecond = $row->second_count_quantity !== null ? (string) (int) $row->second_count_quantity : '';
                                    $initFinal = $row->final_count_quantity !== null ? (string) (int) $row->final_count_quantity : '';
                                @endphp
                                <tr wire:key="ic-row-{{ $row->id }}"
                                    x-data="{
                                        first: @js($initFirst), second: @js($initSecond), final_: @js($initFinal),
                                        origFirst: @js($initFirst), origSecond: @js($initSecond), origFinal: @js($initFinal),
                                        system: {{ (int) $row->system_quantity }}, cost: {{ (float) $row->cost_price }},
                                        toInt(v) { return v === '' ? null : parseInt(v); },
                                        get counted() { let f=this.toInt(this.final_),s=this.toInt(this.second),fi=this.toInt(this.first); return f!==null?f:s!==null?s:fi!==null?fi:null; },
                                        get diff() { return this.counted!==null ? this.counted-this.system : null; },
                                        get diffAmt() { return this.diff!==null ? Math.round(this.diff*this.cost) : null; },
                                        get changed() { return this.first!==this.origFirst||this.second!==this.origSecond||this.final_!==this.origFinal; },
                                        clean(v) { return v.replace(/[０-９]/g,c=>String.fromCharCode(c.charCodeAt(0)-0xFEE0)).replace(/[^0-9]/g,''); },
                                        notify() { $dispatch('count-update',{id:{{ $row->id }},origFirst:this.origFirst,origSecond:this.origSecond,origFinal:this.origFinal,first:this.first,second:this.second,final:this.final_}); }
                                    }"
                                    :class="changed ? 'bg-amber-50' : ($el.rowIndex % 2 === 0 ? 'bg-white' : 'bg-slate-50')"
                                    class="hover:bg-sky-50">
                                    <td class="whitespace-nowrap border border-slate-300 px-2 py-1">{{ $row->floor_name ?: '-' }}</td>
                                    <td class="whitespace-nowrap border border-slate-300 px-2 py-1">{{ $row->location_code1 ?: '-' }}</td>
                                    <td class="whitespace-nowrap border border-slate-300 px-2 py-1 font-mono">{{ $row->location_no ?: '-' }}</td>
                                    <td class="whitespace-nowrap border border-slate-300 px-2 py-1 font-mono">{{ $row->item_code ?: '-' }}</td>
                                    <td class="min-w-[240px] border border-slate-300 px-2 py-1">{{ $row->item_name ?: '-' }}</td>
                                    <td class="whitespace-nowrap border border-slate-300 px-2 py-1 text-right font-bold tabular-nums">{{ number_format((int) $row->system_quantity) }}</td>
                                    @if ($isEditable)
                                        <td class="whitespace-nowrap border border-slate-300 px-1 py-0.5" @click.stop>
                                            <input type="text" inputmode="numeric"
                                                :value="first"
                                                @input="first=clean($event.target.value); $event.target.value=first; notify()"
                                                @keydown="if(['e','E','+','-','.'].includes($event.key)) $event.preventDefault()"
                                                class="{{ $countInputClass }}" placeholder="-">
                                        </td>
                                        <td class="whitespace-nowrap border border-slate-300 px-1 py-0.5" @click.stop>
                                            <input type="text" inputmode="numeric"
                                                :value="second"
                                                @input="second=clean($event.target.value); $event.target.value=second; notify()"
                                                @keydown="if(['e','E','+','-','.'].includes($event.key)) $event.preventDefault()"
                                                class="{{ $countInputClass }}" placeholder="-">
                                        </td>
                                        <td class="whitespace-nowrap border border-slate-300 px-1 py-0.5" @click.stop>
                                            <input type="text" inputmode="numeric"
                                                :value="final_"
                                                @input="final_=clean($event.target.value); $event.target.value=final_; notify()"
                                                @keydown="if(['e','E','+','-','.'].includes($event.key)) $event.preventDefault()"
                                                class="{{ $countInputClass }}" placeholder="-">
                                        </td>
                                        <td class="whitespace-nowrap border border-slate-300 px-2 py-1 text-right font-bold tabular-nums"
                                            :class="{ 'text-green-700': diff > 0, 'text-red-700': diff < 0 }"
                                            x-text="diff !== null ? new Intl.NumberFormat().format(diff) : '-'"></td>
                                        <td class="whitespace-nowrap border border-slate-300 px-2 py-1 text-right tabular-nums"
                                            :class="{ 'text-green-700': diffAmt > 0, 'text-red-700': diffAmt < 0 }"
                                            x-text="diffAmt !== null ? '¥' + new Intl.NumberFormat().format(diffAmt) : '-'"></td>
                                    @else
                                        <td class="whitespace-nowrap border border-slate-300 px-2 py-1 text-right tabular-nums">{{ $initFirst !== '' ? number_format((int) $initFirst) : '-' }}</td>
                                        <td class="whitespace-nowrap border border-slate-300 px-2 py-1 text-right tabular-nums">{{ $initSecond !== '' ? number_format((int) $initSecond) : '-' }}</td>
                                        <td class="whitespace-nowrap border border-slate-300 px-2 py-1 text-right font-bold tabular-nums">{{ $initFinal !== '' ? number_format((int) $initFinal) : '-' }}</td>
                                        @php
                                            $diffQty = $row->difference_quantity;
                                        @endphp
                                        <td class="whitespace-nowrap border border-slate-300 px-2 py-1 text-right font-bold tabular-nums {{ $diffQty !== null && $diffQty > 0 ? 'text-green-700' : ($diffQty !== null && $diffQty < 0 ? 'text-red-700' : '') }}">{{ $diffQty !== null ? number_format((int) $diffQty) : '-' }}</td>
                                        <td class="whitespace-nowrap border border-slate-300 px-2 py-1 text-right tabular-nums {{ $row->difference_amount !== null && (float) $row->difference_amount > 0 ? 'text-green-700' : ($row->difference_amount !== null && (float) $row->difference_amount < 0 ? 'text-red-700' : '') }}">{{ $row->difference_amount !== null ? '¥' . number_format((int) $row->difference_amount) : '-' }}</td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>

                    @if ($hasMore)
                        <div class="flex justify-center border-t border-slate-200 p-3">
                            <button type="button" wire:click="loadMore" class="rounded-md border border-slate-300 px-4 py-1.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">
                                さらに表示
                            </button>
                        </div>
                    @endif
                @endif
            </div>
        </div>
    </div>
</x-filament-panels::page>
