<x-filament-panels::page class="overflow-hidden">
    @php
        $record = $this->record;
        $rows = $this->rows();
        $totalCount = $this->totalCount();
        $allCount = $this->countForTab('all');
        $diffCount = $this->countForTab('diff');
        $uncountedCount = $this->countForTab('uncounted');
        $pageFirst = $rows->firstItem() ?? 0;
        $pageLast = $rows->lastItem() ?? 0;
        $activeRound = $this->activeCountRound;
        $floorOptions = $this->floorOptions();
        $locationOptions = $this->locationOptions();
        $isEditable = in_array($record->status, [
            \App\Models\WmsInventoryCount::STATUS_DRAFT,
            \App\Models\WmsInventoryCount::STATUS_COUNTING,
            \App\Models\WmsInventoryCount::STATUS_CHECKED,
        ]);
        $filterInputClass = 'h-8 w-full rounded-md border border-slate-300 bg-slate-50 px-2 text-xs text-slate-900 shadow-inner outline-none transition placeholder:text-slate-400 focus:border-sky-500 focus:bg-white focus:ring-1 focus:ring-sky-500';
        $filterSelectClass = 'h-8 w-full rounded-md border border-slate-300 bg-slate-50 px-2 text-xs text-slate-900 shadow-inner outline-none transition focus:border-sky-500 focus:bg-white focus:ring-1 focus:ring-sky-500';
        $countInputClass = 'w-20 h-7 rounded border border-slate-300 bg-white px-1 text-right text-xs tabular-nums font-bold outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500 disabled:bg-slate-100 disabled:text-slate-500 disabled:cursor-not-allowed';
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
        locationPickerOpen: false,
        activeTab: @entangle('listTab'),
        activeRound: @entangle('activeCountRound'),
        filters: { floor: '', area: '', itemCode: '', itemName: '', locationText: '' },
        selectedLocations: [],
        changes: {},
        normalize(value) {
            return String(value ?? '').replace(/[Ａ-Ｚａ-ｚ０-９]/g, c => String.fromCharCode(c.charCodeAt(0) - 0xFEE0)).toLowerCase();
        },
        includes(value, keyword) {
            keyword = this.normalize(keyword).trim();
            return keyword === '' || this.normalize(value).includes(keyword);
        },
        rowVisible(row) {
            if (this.activeTab === 'diff' && !(row.diff !== null && row.diff !== 0)) return false;
            if (this.activeTab === 'uncounted' && this.activeRound === 1 && row.first !== '') return false;
            if (this.activeTab === 'uncounted' && this.activeRound === 2 && row.second !== '') return false;
            if (this.activeTab === 'uncounted' && this.activeRound === 3 && row.final_ !== '') return false;
            if (this.filters.floor !== '' && row.floor !== this.filters.floor) return false;
            if (!this.includes(row.area, this.filters.area)) return false;
            if (!this.includes(row.itemCode, this.filters.itemCode)) return false;
            if (!this.includes(row.itemName, this.filters.itemName)) return false;
            if (!this.includes(row.location, this.filters.locationText)) return false;
            if (this.selectedLocations.length && !this.selectedLocations.includes(row.location)) return false;
            return true;
        },
        toggleLocation(location) {
            if (this.selectedLocations.includes(location)) {
                this.selectedLocations = this.selectedLocations.filter(v => v !== location);
            } else {
                this.selectedLocations = [...this.selectedLocations, location];
            }
        },
        clearFilters() {
            this.filters = { floor: '', area: '', itemCode: '', itemName: '', locationText: '' };
            this.selectedLocations = [];
        },
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
                        / 表示{{ number_format($pageFirst) }}-{{ number_format($pageLast) }}件
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

            {{-- Filter form: JS only. Server communication happens only on save. --}}
            <div x-show="filtersOpen" x-collapse x-cloak class="bg-slate-100 p-2">
                <div class="grid grid-cols-2 items-end gap-2 md:grid-cols-6 xl:grid-cols-12">
                    <label class="space-y-1 md:col-span-2">
                        <span class="text-xs font-semibold text-slate-700">フロア</span>
                        <select x-model="filters.floor" class="{{ $filterSelectClass }}">
                            <option value="">すべて</option>
                            @foreach ($floorOptions as $floor)
                                <option value="{{ $floor }}">{{ $floor }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="space-y-1 md:col-span-2">
                        <span class="text-xs font-semibold text-slate-700">エリア</span>
                        <input type="text" x-model="filters.area" placeholder="エリア検索" class="{{ $filterInputClass }}">
                    </label>
                    <label class="space-y-1 md:col-span-2">
                        <span class="text-xs font-semibold text-slate-700">商品CD</span>
                        <input type="text" x-model="filters.itemCode" placeholder="商品CD検索" class="{{ $filterInputClass }}">
                    </label>
                    <div class="relative space-y-1 md:col-span-2">
                        <span class="text-xs font-semibold text-slate-700">ロケーション</span>
                        <button type="button" @click="locationPickerOpen = ! locationPickerOpen" class="{{ $filterInputClass }} flex items-center justify-between text-left">
                            <span x-text="selectedLocations.length ? selectedLocations.length + '件選択' : 'ロケーション選択'"></span>
                            <x-filament::icon icon="heroicon-m-chevron-down" class="h-4 w-4" />
                        </button>
                        <div x-show="locationPickerOpen" x-cloak @click.outside="locationPickerOpen = false" class="absolute z-30 mt-1 w-[32rem] max-w-[calc(100vw-2rem)] rounded-lg border border-slate-200 bg-white p-2 shadow-xl">
                            <input type="text" x-model="filters.locationText" placeholder="ロケーション検索..." class="{{ $filterInputClass }} mb-2">
                            <div class="grid max-h-64 grid-cols-2 gap-1 overflow-auto rounded-md border border-slate-200 p-1">
                                @foreach ($locationOptions as $location)
                                    <label x-show="includes(@js($location), filters.locationText)" class="flex min-w-0 items-center gap-2 rounded-md border border-slate-200 px-2 py-1 text-xs hover:bg-slate-50" :class="selectedLocations.includes(@js($location)) ? 'border-sky-500 bg-sky-50 text-sky-800' : ''">
                                        <input type="checkbox" class="rounded border-slate-300" :checked="selectedLocations.includes(@js($location))" @change="toggleLocation(@js($location))">
                                        <span class="truncate font-mono">{{ $location }}</span>
                                    </label>
                                @endforeach
                            </div>
                            <div class="mt-2 flex items-center justify-between text-xs">
                                <button type="button" class="text-slate-600 hover:text-slate-900" @click="selectedLocations = []">選択解除</button>
                                <button type="button" class="rounded bg-slate-800 px-3 py-1 font-bold text-white" @click="locationPickerOpen = false">閉じる</button>
                            </div>
                        </div>
                    </div>
                    <label class="space-y-1 md:col-span-2">
                        <span class="text-xs font-semibold text-slate-700">商品名</span>
                        <input type="text" x-model="filters.itemName" placeholder="商品名検索" class="{{ $filterInputClass }}">
                    </label>
                    <div class="flex items-end justify-end gap-2 md:col-span-2">
                        <button type="button" @click="clearFilters()" class="h-8 rounded-md border border-slate-300 px-3 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                            クリア
                        </button>
                    </div>
                </div>
            </div>
        </div>

        {{-- Tab bar + table --}}
        <div class="flex min-h-0 flex-1 flex-col overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-wrap items-end justify-between gap-2 border-b border-slate-200 bg-green-700 px-3 pt-2 text-white">
                <div class="flex items-end gap-1">
                    <button type="button"
                        @click="activeTab = 'all'"
                        class="relative inline-flex h-10 items-center gap-2 rounded-t-md border px-3 text-xs font-bold transition"
                        :class="activeTab === 'all' ? 'border-slate-200 border-b-white bg-white text-green-800 shadow-sm' : 'border-green-700 bg-green-800 text-white/85 hover:bg-green-900 hover:text-white'">
                        <span x-show="activeTab === 'all'" class="absolute inset-x-2 top-0 h-0.5 rounded-full bg-green-600"></span>
                        <span>全件</span>
                        <span class="rounded-full px-2 py-0.5 text-[11px] font-black tabular-nums" :class="activeTab === 'all' ? 'bg-green-100 text-green-800' : 'bg-white/15 text-white ring-1 ring-white/25'">
                            {{ number_format($allCount) }}
                        </span>
                    </button>
                    <button type="button"
                        @click="activeTab = 'diff'"
                        class="relative inline-flex h-10 items-center gap-2 rounded-t-md border px-3 text-xs font-bold transition"
                        :class="activeTab === 'diff' ? 'border-slate-200 border-b-white bg-white text-red-700 shadow-sm' : 'border-green-700 bg-green-800 text-white/85 hover:bg-green-900 hover:text-white'">
                        <span x-show="activeTab === 'diff'" class="absolute inset-x-2 top-0 h-0.5 rounded-full bg-red-600"></span>
                        <span>差異あり</span>
                        <span class="rounded-full px-2 py-0.5 text-[11px] font-black tabular-nums" :class="activeTab === 'diff' ? 'bg-red-100 text-red-700' : 'bg-white/15 text-white ring-1 ring-white/25'">
                            {{ number_format($diffCount) }}
                        </span>
                    </button>
                    <button type="button"
                        @click="activeTab = 'uncounted'"
                        class="relative inline-flex h-10 items-center gap-2 rounded-t-md border px-3 text-xs font-bold transition"
                        :class="activeTab === 'uncounted' ? 'border-slate-200 border-b-white bg-white text-amber-700 shadow-sm' : 'border-green-700 bg-green-800 text-white/85 hover:bg-green-900 hover:text-white'">
                        <span x-show="activeTab === 'uncounted'" class="absolute inset-x-2 top-0 h-0.5 rounded-full bg-amber-500"></span>
                        <span>未カウント</span>
                        <span class="rounded-full px-2 py-0.5 text-[11px] font-black tabular-nums" :class="activeTab === 'uncounted' ? 'bg-amber-100 text-amber-700' : 'bg-white/15 text-white ring-1 ring-white/25'">
                            {{ number_format($uncountedCount) }}
                        </span>
                    </button>
                </div>
                <div class="flex items-center gap-3 pb-2">
                    <div class="flex items-center gap-1 rounded-md bg-green-900/30 p-1 text-xs font-bold">
                        <span class="px-2 text-white/80">入力中</span>
                        @foreach ([1 => '1回目', 2 => '2回目', 3 => '最終'] as $round => $label)
                            <button type="button"
                                wire:click="setActiveCountRound({{ $round }})"
                                x-bind:disabled="changeCount > 0"
                                class="h-7 rounded px-2 disabled:cursor-not-allowed disabled:opacity-50 {{ $activeRound === $round ? 'bg-white text-green-800' : 'text-white hover:bg-green-800' }}">
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>
                    <div class="rounded-full bg-green-900/40 px-3 py-1 text-sm font-black text-white tabular-nums">
                        {{ number_format($pageFirst) }}-{{ number_format($pageLast) }} / {{ number_format($rows->total()) }}件
                    </div>
                    <div class="flex items-center gap-1 text-xs font-bold">
                        <button type="button"
                            wire:click="previousItemPage"
                            x-bind:disabled="changeCount > 0"
                            @disabled($rows->onFirstPage())
                            class="h-8 rounded-md border border-green-300 px-2 text-white disabled:cursor-not-allowed disabled:opacity-40 hover:bg-green-800">
                            前へ
                        </button>
                        <span class="px-2 tabular-nums">{{ $rows->currentPage() }} / {{ $rows->lastPage() }}</span>
                        <button type="button"
                            wire:click="nextItemPage"
                            x-bind:disabled="changeCount > 0"
                            @disabled(! $rows->hasMorePages())
                            class="h-8 rounded-md border border-green-300 px-2 text-white disabled:cursor-not-allowed disabled:opacity-40 hover:bg-green-800">
                            次へ
                        </button>
                    </div>
                    @if ($isEditable)
                        <button type="button" @click="save()" x-show="changeCount > 0" x-cloak
                            class="inline-flex items-center gap-2 rounded-md bg-red-600 px-4 py-1.5 text-sm font-bold text-white shadow-sm hover:bg-red-700">
                            <x-filament::icon icon="heroicon-m-arrow-up-tray" class="h-4 w-4" />
                            <span>反映</span>
                            <span class="rounded-full bg-white/20 px-2 py-0.5 text-xs font-black" x-text="changeCount + '件'"></span>
                        </button>
                    @endif
                    {{ $this->getAction('downloadInstructionPdf') }}
                    @if ($record->status === \App\Models\WmsInventoryCount::STATUS_DRAFT)
                        {{ $this->getAction('startCounting') }}
                    @endif
                    @if (in_array($record->status, [
                        \App\Models\WmsInventoryCount::STATUS_DRAFT,
                        \App\Models\WmsInventoryCount::STATUS_COUNTING,
                        \App\Models\WmsInventoryCount::STATUS_CHECKED,
                    ], true))
                        @php
                            $isCountingStarted = $record->status !== \App\Models\WmsInventoryCount::STATUS_DRAFT;
                        @endphp
                        <button type="button"
                            wire:click="calculateActiveRoundDifferences"
                            @click="activeTab = 'diff'"
                            x-bind:disabled="changeCount > 0 || {{ $isCountingStarted ? 'false' : 'true' }}"
                            class="inline-flex items-center gap-2 rounded-md bg-amber-500 px-3 py-1.5 text-sm font-bold text-white shadow-sm hover:bg-amber-600 disabled:cursor-not-allowed disabled:opacity-50">
                            <x-filament::icon icon="heroicon-m-calculator" class="h-4 w-4" />
                            <span>{{ $this->activeRoundLabel() }}差異計算</span>
                        </button>
                        <div class="flex items-center gap-1 rounded-md bg-green-900/30 p-1">
                            @foreach ([1 => '1回目', 2 => '2回目', 3 => '最終'] as $round => $label)
                                @php
                                    $roundConfirmed = $this->isRoundConfirmed($round);
                                    $roundAvailable = $round <= $activeRound;
                                @endphp
                                <button type="button"
                                    wire:click="confirmRound({{ $round }})"
                                    x-bind:disabled="changeCount > 0 || {{ (! $isCountingStarted || $roundConfirmed || ! $roundAvailable) ? 'true' : 'false' }}"
                                    class="h-8 rounded-md px-3 text-xs font-bold shadow-sm disabled:cursor-not-allowed disabled:opacity-50 {{ $roundConfirmed ? 'bg-slate-300 text-slate-600' : ($roundAvailable ? 'bg-blue-600 text-white hover:bg-blue-700' : 'bg-white/20 text-white') }}">
                                    {{ $roundConfirmed ? "{$label}確定済" : "{$label}確定" }}
                                </button>
                            @endforeach
                        </div>
                    @endif
                    @if ($record->status !== \App\Models\WmsInventoryCount::STATUS_DRAFT)
                        {{ $this->getAction('downloadDiffListPdf') }}
                    @endif
                    @if ($record->status === \App\Models\WmsInventoryCount::STATUS_CHECKED)
                        {{ $this->getAction('confirm') }}
                    @endif
                    @if (! in_array($record->status, [
                        \App\Models\WmsInventoryCount::STATUS_CONFIRMED,
                        \App\Models\WmsInventoryCount::STATUS_CANCELLED,
                    ], true))
                        {{ $this->getAction('cancel') }}
                    @endif
                    <div wire:loading class="text-xs">読込中...</div>
                </div>
            </div>

            {{-- Table --}}
            <div class="min-h-0 flex-1 overflow-auto">
                @if ($rows->count() === 0)
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
                                <th class="border border-slate-300 px-2 py-2 text-center">回数</th>
                                <th class="border border-slate-300 px-2 py-2 text-left">入力者</th>
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
                            @foreach ($rows->items() as $row)
                                @php
                                    $initFirst = $row->first_count_quantity !== null ? (string) (int) $row->first_count_quantity : '';
                                    $initSecond = $row->second_count_quantity !== null ? (string) (int) $row->second_count_quantity : '';
                                    $initFinal = $row->final_count_quantity !== null ? (string) (int) $row->final_count_quantity : '';
                                @endphp
                                <tr wire:key="ic-row-{{ $row->id }}"
                                    x-data="{
                                        floor: @js($row->floor_name ?: ''),
                                        area: @js($row->location_code1 ?: ''),
                                        location: @js($row->location_no ?: ''),
                                        itemCode: @js($row->item_code ?: ''),
                                        itemName: @js($row->item_name ?: ''),
                                        first: @js($initFirst), second: @js($initSecond), final_: @js($initFinal),
                                        origFirst: @js($initFirst), origSecond: @js($initSecond), origFinal: @js($initFinal),
                                        system: {{ (int) $row->system_quantity }}, cost: {{ (float) $row->cost_price }},
                                        toInt(v) { return v === '' ? null : parseInt(v); },
                                        get counted() {
                                            if (this.activeRound == 3) return this.toInt(this.final_);
                                            if (this.activeRound == 2) return this.toInt(this.second);
                                            return this.toInt(this.first);
                                        },
                                        get diff() { return this.counted!==null ? this.counted-this.system : null; },
                                        get diffAmt() { return this.diff!==null ? Math.round(this.diff*this.cost) : null; },
                                        get changed() { return this.first!==this.origFirst||this.second!==this.origSecond||this.final_!==this.origFinal; },
                                        clean(v) { return v.replace(/[０-９]/g,c=>String.fromCharCode(c.charCodeAt(0)-0xFEE0)).replace(/[^0-9]/g,''); },
                                        notify() { $dispatch('count-update',{id:{{ $row->id }},origFirst:this.origFirst,origSecond:this.origSecond,origFinal:this.origFinal,first:this.first,second:this.second,final:this.final_}); }
                                    }"
                                    x-show="rowVisible($data)"
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
                                                @disabled($activeRound !== 1)
                                                class="{{ $countInputClass }}" placeholder="-">
                                        </td>
                                        <td class="whitespace-nowrap border border-slate-300 px-1 py-0.5" @click.stop>
                                            <input type="text" inputmode="numeric"
                                                :value="second"
                                                @input="second=clean($event.target.value); $event.target.value=second; notify()"
                                                @keydown="if(['e','E','+','-','.'].includes($event.key)) $event.preventDefault()"
                                                @disabled($activeRound !== 2)
                                                class="{{ $countInputClass }}" placeholder="-">
                                        </td>
                                        <td class="whitespace-nowrap border border-slate-300 px-1 py-0.5" @click.stop>
                                            <input type="text" inputmode="numeric"
                                                :value="final_"
                                                @input="final_=clean($event.target.value); $event.target.value=final_; notify()"
                                                @keydown="if(['e','E','+','-','.'].includes($event.key)) $event.preventDefault()"
                                                @disabled($activeRound !== 3)
                                                class="{{ $countInputClass }}" placeholder="-">
                                        </td>
                                        <td class="whitespace-nowrap border border-slate-300 px-2 py-1 text-center font-bold tabular-nums">
                                            <span x-text="final_ !== '' ? '最終' : (second !== '' ? '2回目' : (first !== '' ? '1回目' : '-'))"></span>
                                        </td>
                                        <td class="whitespace-nowrap border border-slate-300 px-2 py-1 text-slate-600">{{ $row->latestLog?->actor_name ?? '-' }}</td>
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
                                        <td class="whitespace-nowrap border border-slate-300 px-2 py-1 text-center font-bold tabular-nums">{{ $initFinal !== '' ? '最終' : ($initSecond !== '' ? '2回目' : ($initFirst !== '' ? '1回目' : '-')) }}</td>
                                        <td class="whitespace-nowrap border border-slate-300 px-2 py-1 text-slate-600">{{ $row->latestLog?->actor_name ?? '-' }}</td>
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

                @endif
            </div>
            <div class="flex shrink-0 items-center justify-between border-t border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700">
                <div class="tabular-nums">
                    {{ number_format($pageFirst) }}-{{ number_format($pageLast) }} / {{ number_format($rows->total()) }}件
                    <span class="ml-2 font-bold text-green-800">入力中: {{ $this->activeRoundLabel() }}</span>
                    <span x-show="changeCount > 0" x-cloak class="ml-2 font-bold text-red-700">未反映の入力があります。反映後にページ移動できます。</span>
                </div>
                <div class="flex items-center gap-1 font-bold">
                    <button type="button"
                        wire:click="previousItemPage"
                        x-bind:disabled="changeCount > 0"
                        @disabled($rows->onFirstPage())
                        class="h-8 rounded-md border border-slate-300 bg-white px-3 disabled:cursor-not-allowed disabled:opacity-40 hover:bg-slate-100">
                        前へ
                    </button>
                    <span class="px-2 tabular-nums">{{ $rows->currentPage() }} / {{ $rows->lastPage() }}</span>
                    <button type="button"
                        wire:click="nextItemPage"
                        x-bind:disabled="changeCount > 0"
                        @disabled(! $rows->hasMorePages())
                        class="h-8 rounded-md border border-slate-300 bg-white px-3 disabled:cursor-not-allowed disabled:opacity-40 hover:bg-slate-100">
                        次へ
                    </button>
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
