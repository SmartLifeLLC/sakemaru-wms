<x-filament-panels::page class="overflow-hidden">
    @php
        $record = $this->record;
        $isEditable = $this->isEditable();
        $listTab = $this->listTab;
        $summary = $this->summary();
        $total = $summary['totals']['total'];
        $counts = $this->itemCounts();
        $categoryOptions = $this->categoryOptions();
        $amountRows = $this->amountRows();
        $rows = $listTab === 'items' ? $this->rows() : null;
        $pageFirst = $rows?->firstItem() ?? 0;
        $pageLast = $rows?->lastItem() ?? 0;
        $uncountedAmounts = $amountRows->whereNull('counted_amount')->count();
        $filterInputClass = 'h-8 w-full rounded-md border border-slate-300 bg-slate-50 px-2 text-xs text-slate-900 shadow-inner outline-none transition placeholder:text-slate-400 focus:border-sky-500 focus:bg-white focus:ring-1 focus:ring-sky-500';
        $filterSelectClass = 'h-8 w-full rounded-md border border-slate-300 bg-slate-50 px-2 text-xs text-slate-900 shadow-inner outline-none transition focus:border-sky-500 focus:bg-white focus:ring-1 focus:ring-sky-500';
        $countInputClass = 'w-20 h-7 rounded border border-slate-300 bg-white px-1 text-right text-xs tabular-nums font-bold outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500 disabled:bg-slate-100 disabled:text-slate-500 disabled:cursor-not-allowed';
        $amountInputClass = 'w-28 h-7 rounded border border-slate-300 bg-white px-1 text-right text-xs tabular-nums font-bold outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500 disabled:bg-slate-100 disabled:text-slate-500 disabled:cursor-not-allowed';
        $dateInputClass = 'w-32 h-7 rounded border border-slate-300 bg-white px-1 text-xs tabular-nums outline-none focus:border-sky-500 focus:ring-1 focus:ring-sky-500 disabled:bg-slate-100 disabled:text-slate-500 disabled:cursor-not-allowed';
        $thClass = 'border border-slate-300 px-2 py-2';
        $tdClass = 'whitespace-nowrap border border-slate-300 px-2 py-1';
        $statusColors = [
            'counting' => 'bg-sky-100 text-sky-700',
            'confirmed' => 'bg-green-100 text-green-700',
            'cancelled' => 'bg-red-100 text-red-700',
        ];
        $signClass = fn ($value) => $value === null ? '' : ((float) $value > 0 ? 'text-green-700' : ((float) $value < 0 ? 'text-red-700' : ''));
        $tabClass = fn (string $tab) => $listTab === $tab
            ? 'border-slate-200 border-b-white bg-white text-green-800 shadow-sm'
            : 'border-green-700 bg-green-800 text-white/85 hover:bg-green-900 hover:text-white';
        $tabBadgeClass = fn (string $tab) => $listTab === $tab
            ? 'bg-green-100 text-green-800'
            : 'bg-white/15 text-white ring-1 ring-white/25';
    @endphp

    <div x-data="{
        filtersOpen: true,
        changes: { items: {}, amounts: {} },
        get changeCount() {
            return Object.keys(this.changes.items).length + Object.keys(this.changes.amounts).length;
        },
        toNum(value) {
            value = String(value ?? '');
            if (value === '' || value === '-' || value === '.' || value === '-.') return null;
            let number = parseFloat(value);
            return Number.isNaN(number) ? null : number;
        },
        clean(value) {
            value = String(value ?? '')
                .replace(/[０-９．]/g, c => String.fromCharCode(c.charCodeAt(0) - 0xFEE0))
                .replace(/[−－ー―]/g, '-')
                .replace(/[^0-9.-]/g, '');
            let negative = value.includes('-');
            value = value.replace(/-/g, '');
            let parts = value.split('.');
            if (parts.length > 1) value = parts[0] + '.' + parts.slice(1).join('');
            return (negative ? '-' : '') + value;
        },
        formatNumber(value, digits = 0) {
            return new Intl.NumberFormat('ja-JP', { maximumFractionDigits: digits }).format(value);
        },
        setItemChange(id, value, original) {
            if (value !== original) {
                this.changes.items[id] = value === '' ? null : value;
            } else {
                delete this.changes.items[id];
            }
        },
        setAmountChange(id, fields) {
            if (Object.keys(fields).length) {
                this.changes.amounts[id] = fields;
            } else {
                delete this.changes.amounts[id];
            }
        },
        save() {
            if (!this.changeCount) return;
            this.$wire.saveChanges(this.changes).then((saved) => {
                if (saved) this.changes = { items: {}, amounts: {} };
            });
        },
        guard(callback) {
            if (this.changeCount > 0) {
                alert('先に反映を実施してください');
                return;
            }
            callback();
        }
    }"
    @sundry-item-change="setItemChange($event.detail.id, $event.detail.value, $event.detail.original)"
    @sundry-amount-change="setAmountChange($event.detail.id, $event.detail.fields)"
    class="flex h-[calc(100vh-72px)] min-h-0 flex-col gap-2">
        {{-- Header bar --}}
        <div class="relative z-20 shrink-0 overflow-visible rounded-lg border border-slate-300 bg-slate-100 shadow-sm">
            <div class="flex items-center justify-between border-b border-slate-200 bg-slate-800 px-3 py-2 text-white">
                <div class="flex min-w-0 flex-wrap items-center gap-3">
                    <span class="rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-bold text-amber-700">棚卸し（雑貨）</span>
                    <span class="truncate text-xs text-slate-300">
                        {{ $record->count_no }}
                        / {{ $record->warehouse_code }} {{ $record->warehouse_name }}
                        / {{ $record->count_date?->format('Y/m/d') }}
                    </span>
                    <span class="rounded-full px-2 py-0.5 text-[11px] font-bold {{ $statusColors[$record->status] ?? 'bg-slate-200 text-slate-700' }}">
                        {{ $record->status_label }}
                    </span>
                    @if ($record->theory_end_date)
                        <span class="rounded-full bg-purple-100 px-2 py-0.5 text-[11px] font-bold text-purple-700">
                            受払終了日 {{ $record->theory_end_date->format('Y/m/d') }}
                        </span>
                    @endif
                    <span class="rounded-full bg-slate-200 px-2 py-0.5 text-[11px] font-bold text-slate-700">
                        理論 {{ $this->formatAmount($total['system']) }}
                    </span>
                    <span class="rounded-full bg-slate-200 px-2 py-0.5 text-[11px] font-bold text-slate-700">
                        実棚 {{ $this->formatAmount($total['counted']) }}
                    </span>
                    <span class="rounded-full bg-slate-200 px-2 py-0.5 text-[11px] font-bold {{ $signClass($total['difference']) ?: 'text-slate-700' }}">
                        差異 {{ $this->formatAmount($total['difference']) }}
                    </span>
                    <span class="text-xs text-slate-400">
                        数量明細{{ number_format($counts['all']) }}件
                        / 差異{{ number_format($counts['diff']) }}件
                        / 未入力{{ number_format($counts['uncounted']) }}件
                        / 金額明細{{ number_format($amountRows->count()) }}件（未入力{{ number_format($uncountedAmounts) }}件）
                    </span>
                </div>
                <button type="button"
                    class="inline-flex shrink-0 items-center gap-1 rounded-md border border-slate-500 px-2 py-1 text-xs font-semibold text-slate-100 hover:bg-slate-700"
                    @click="filtersOpen = ! filtersOpen">
                    <x-filament::icon icon="heroicon-m-magnifying-glass" class="h-4 w-4" />
                    <span>検索条件</span>
                    <x-filament::icon icon="heroicon-m-chevron-down" class="h-4 w-4 transition" x-bind:class="{ 'rotate-180': filtersOpen }" />
                </button>
            </div>

            {{-- Filter form（数量明細用） --}}
            <div x-show="filtersOpen" x-collapse x-cloak class="bg-slate-100 p-2">
                <div class="grid grid-cols-2 items-end gap-2 md:grid-cols-6 xl:grid-cols-12">
                    <label class="space-y-1 md:col-span-2">
                        <span class="text-xs font-semibold text-slate-700">中分類</span>
                        <select wire:model.live="categoryFilter" class="{{ $filterSelectClass }}">
                            <option value="">すべて</option>
                            @foreach ($categoryOptions as $code => $label)
                                <option value="{{ $code }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="space-y-1 md:col-span-2">
                        <span class="text-xs font-semibold text-slate-700">商品CD</span>
                        <input type="text" wire:model.live.debounce.300ms="itemCodeFilter" placeholder="商品CD検索" class="{{ $filterInputClass }}">
                    </label>
                    <label class="space-y-1 md:col-span-2">
                        <span class="text-xs font-semibold text-slate-700">商品名</span>
                        <input type="text" wire:model.live.debounce.300ms="itemNameFilter" placeholder="商品名検索" class="{{ $filterInputClass }}">
                    </label>
                    <label class="space-y-1 md:col-span-2">
                        <span class="text-xs font-semibold text-slate-700">表示</span>
                        <select wire:model.live="stateFilter" class="{{ $filterSelectClass }}">
                            <option value="all">全件</option>
                            <option value="diff">差異あり</option>
                            <option value="uncounted">未入力</option>
                        </select>
                    </label>
                    <div class="flex items-end justify-end gap-2 md:col-span-2 xl:col-span-4">
                        <button type="button" wire:click="clearFilters" class="h-8 rounded-md border border-slate-300 px-3 text-xs font-semibold text-slate-700 hover:bg-slate-50">
                            クリア
                        </button>
                    </div>
                </div>
            </div>
        </div>

        {{-- Tab bar + table --}}
        <div class="flex min-h-0 flex-1 flex-col overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="border-b border-slate-200 bg-green-700 px-3 pt-2 text-white">
                <div class="flex flex-wrap items-end justify-between gap-2">
                    <div class="flex items-end gap-1">
                        <button type="button"
                            @click="guard(() => $wire.setListTab('items'))"
                            class="relative inline-flex h-10 items-center gap-2 rounded-t-md border px-3 text-xs font-bold transition {{ $tabClass('items') }}">
                            <span>数量明細（在庫管理あり）</span>
                            <span class="rounded-full px-2 py-0.5 text-[11px] font-black tabular-nums {{ $tabBadgeClass('items') }}">
                                {{ number_format($counts['all']) }}
                            </span>
                        </button>
                        <button type="button"
                            @click="guard(() => $wire.setListTab('amounts'))"
                            class="relative inline-flex h-10 items-center gap-2 rounded-t-md border px-3 text-xs font-bold transition {{ $tabClass('amounts') }}">
                            <span>金額明細（在庫管理なし）</span>
                            <span class="rounded-full px-2 py-0.5 text-[11px] font-black tabular-nums {{ $tabBadgeClass('amounts') }}">
                                {{ number_format($amountRows->count()) }}
                            </span>
                        </button>
                        <button type="button"
                            @click="guard(() => $wire.setListTab('summary'))"
                            class="relative inline-flex h-10 items-center gap-2 rounded-t-md border px-3 text-xs font-bold transition {{ $tabClass('summary') }}">
                            <span>集計</span>
                        </button>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 pb-2">
                        @if ($listTab === 'items' && $rows)
                            <div class="rounded-full bg-green-900/40 px-3 py-1 text-sm font-black text-white tabular-nums">
                                {{ number_format($pageFirst) }}-{{ number_format($pageLast) }} / {{ number_format($rows->total()) }}件
                            </div>
                            <div class="flex items-center gap-1 text-xs font-bold">
                                <button type="button"
                                    @click="guard(() => $wire.previousItemPage())"
                                    x-bind:class="{ 'cursor-not-allowed opacity-40': changeCount > 0 }"
                                    @disabled($rows->onFirstPage())
                                    class="h-8 rounded-md border border-green-300 px-2 text-white disabled:cursor-not-allowed disabled:opacity-40 hover:bg-green-800">
                                    前へ
                                </button>
                                <span class="px-2 tabular-nums">{{ $rows->currentPage() }} / {{ $rows->lastPage() }}</span>
                                <button type="button"
                                    @click="guard(() => $wire.nextItemPage())"
                                    x-bind:class="{ 'cursor-not-allowed opacity-40': changeCount > 0 }"
                                    @disabled(! $rows->hasMorePages())
                                    class="h-8 rounded-md border border-green-300 px-2 text-white disabled:cursor-not-allowed disabled:opacity-40 hover:bg-green-800">
                                    次へ
                                </button>
                            </div>
                        @endif
                        @if ($isEditable)
                            <button type="button" @click="save()" x-show="changeCount > 0" x-cloak
                                class="inline-flex items-center gap-2 rounded-md bg-red-600 px-4 py-1.5 text-sm font-bold text-white shadow-sm hover:bg-red-700">
                                <x-filament::icon icon="heroicon-m-arrow-up-tray" class="h-4 w-4" />
                                <span>反映</span>
                                <span class="rounded-full bg-white/20 px-2 py-0.5 text-xs font-black" x-text="changeCount + '件'"></span>
                            </button>
                        @endif
                    </div>
                </div>
                <div class="flex flex-wrap items-center justify-end gap-2 pb-2">
                    {{ $this->getAction('addItem') }}
                    {{ $this->getAction('refreshTheory') }}
                    {{ $this->getAction('fillUncountedWithZero') }}
                    {{ $this->getAction('downloadInstructionSheet') }}
                    {{ $this->getAction('downloadDifferenceWorkbook') }}
                    {{ $this->getAction('confirm') }}
                    {{ $this->getAction('reopen') }}
                    {{ $this->getAction('cancel') }}
                    <div wire:loading class="text-xs">読込中...</div>
                </div>
            </div>

            <div class="min-h-0 flex-1 overflow-auto">
                {{-- 数量明細（在庫管理あり） --}}
                @if ($listTab === 'items')
                    @if (! $rows || $rows->count() === 0)
                        <div class="p-8 text-center text-sm text-slate-500">条件に一致する明細はありません。</div>
                    @else
                        <table class="w-max min-w-full border-collapse text-xs">
                            <thead class="sticky top-0 z-10 bg-slate-100 text-slate-700">
                                <tr>
                                    <th class="{{ $thClass }} text-left">中分類</th>
                                    <th class="{{ $thClass }} text-left">商品CD</th>
                                    <th class="{{ $thClass }} text-left">商品名</th>
                                    <th class="{{ $thClass }} text-right">原価</th>
                                    <th class="{{ $thClass }} text-right">理論数</th>
                                    <th class="{{ $thClass }} text-right">実棚数</th>
                                    <th class="{{ $thClass }} text-right">差異数</th>
                                    <th class="{{ $thClass }} text-right">理論金額</th>
                                    <th class="{{ $thClass }} text-right">実棚金額</th>
                                    <th class="{{ $thClass }} text-right">差異金額</th>
                                    <th class="{{ $thClass }} text-left">入力者</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($rows->items() as $row)
                                    @php
                                        $initCounted = $this->inputValue($row->counted_quantity);
                                    @endphp
                                    <tr wire:key="sundry-item-{{ $row->id }}-u{{ $row->updated_at?->timestamp ?? 0 }}"
                                        x-data="{
                                            counted: @js($initCounted),
                                            original: @js($initCounted),
                                            system: {{ (float) $row->system_quantity }},
                                            cost: {{ (float) $row->cost_price }},
                                            get diff() {
                                                let quantity = toNum(this.counted);
                                                return quantity === null ? null : Math.round((quantity - this.system) * 1000) / 1000;
                                            },
                                            get countedAmount() {
                                                let quantity = toNum(this.counted);
                                                return quantity === null ? null : Math.round(quantity * this.cost);
                                            },
                                            get diffAmount() {
                                                return this.diff === null ? null : Math.round(this.diff * this.cost);
                                            },
                                            get changed() { return this.counted !== this.original; },
                                            notify() {
                                                $dispatch('sundry-item-change', { id: {{ $row->id }}, value: this.counted, original: this.original });
                                            }
                                        }"
                                        :class="changed ? 'bg-amber-50' : ($el.rowIndex % 2 === 0 ? 'bg-white' : 'bg-slate-50')"
                                        class="hover:bg-sky-50">
                                        <td class="{{ $tdClass }}">{{ $row->category2_code }} {{ $row->category2_name }}</td>
                                        <td class="{{ $tdClass }} font-mono">{{ $row->item_code ?: '-' }}</td>
                                        <td class="min-w-[240px] border border-slate-300 px-2 py-1">
                                            {{ $row->item_name ?: '-' }}
                                            @if ($row->is_additional)
                                                <span class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-bold text-amber-700">追加</span>
                                            @endif
                                        </td>
                                        <td class="{{ $tdClass }} text-right tabular-nums">{{ number_format((float) $row->cost_price, 2) }}</td>
                                        <td class="{{ $tdClass }} text-right font-bold tabular-nums text-slate-700">{{ $this->formatQuantity($row->system_quantity) }}</td>
                                        @if ($isEditable)
                                            <td class="whitespace-nowrap border border-slate-300 px-1 py-0.5" @click.stop>
                                                <input type="text" inputmode="decimal"
                                                    :value="counted"
                                                    @input="counted = clean($event.target.value); $event.target.value = counted; notify()"
                                                    @keydown.enter.prevent="$el.closest('tr').nextElementSibling?.querySelector('input')?.focus()"
                                                    class="{{ $countInputClass }}" placeholder="-">
                                            </td>
                                            <td class="{{ $tdClass }} text-right font-bold tabular-nums"
                                                :class="{ 'text-green-700': diff > 0, 'text-red-700': diff < 0 }"
                                                x-text="diff !== null ? formatNumber(diff, 3) : '-'"></td>
                                            <td class="{{ $tdClass }} text-right tabular-nums">{{ $this->formatAmount($row->system_amount) }}</td>
                                            <td class="{{ $tdClass }} text-right tabular-nums"
                                                x-text="countedAmount !== null ? '¥' + formatNumber(countedAmount) : '-'"></td>
                                            <td class="{{ $tdClass }} text-right font-bold tabular-nums"
                                                :class="{ 'text-green-700': diffAmount > 0, 'text-red-700': diffAmount < 0 }"
                                                x-text="diffAmount !== null ? '¥' + formatNumber(diffAmount) : '-'"></td>
                                        @else
                                            <td class="{{ $tdClass }} text-right font-bold tabular-nums">{{ $this->formatQuantity($row->counted_quantity) }}</td>
                                            <td class="{{ $tdClass }} text-right font-bold tabular-nums {{ $signClass($row->difference_quantity) }}">{{ $this->formatQuantity($row->difference_quantity) }}</td>
                                            <td class="{{ $tdClass }} text-right tabular-nums">{{ $this->formatAmount($row->system_amount) }}</td>
                                            <td class="{{ $tdClass }} text-right tabular-nums">{{ $this->formatAmount($row->counted_amount) }}</td>
                                            <td class="{{ $tdClass }} text-right font-bold tabular-nums {{ $signClass($row->difference_amount) }}">{{ $this->formatAmount($row->difference_amount) }}</td>
                                        @endif
                                        <td class="{{ $tdClass }} text-slate-600">{{ $row->counted_by_name ?: '-' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                @endif

                {{-- 金額明細（在庫管理なし・中分類別） --}}
                @if ($listTab === 'amounts')
                    @if ($amountRows->isEmpty())
                        <div class="p-8 text-center text-sm text-slate-500">金額で棚卸しする中分類（在庫管理なしの商品）はありません。</div>
                    @else
                        @php
                            $reportReferences = $this->reportReferences();
                        @endphp
                        <div class="border-b border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700">
                            理論金額 = 前残金額 + 仕入 + 移入 − 移出 − 売上原価 + 調整（基準日の翌日から受払終了日まで。売上原価 = 売価 × 分類原価率）。
                            前残金額は、前回確定した棚卸し（雑貨）の実棚金額、無ければ旧システムの最終残高（2026/05/05 時点。新システムの受払は 5/6 から）です。
                            前残金額や基準日を手で直した行は、理論在庫更新でも自動の値に戻しません。
                        </div>
                        <table class="w-max min-w-full border-collapse text-xs">
                            <thead class="sticky top-0 z-10 bg-slate-100 text-slate-700">
                                <tr>
                                    <th class="{{ $thClass }} text-left">大分類</th>
                                    <th class="{{ $thClass }} text-left">中分類CD</th>
                                    <th class="{{ $thClass }} text-left">中分類名</th>
                                    <th class="{{ $thClass }} text-left">基準日</th>
                                    <th class="{{ $thClass }} text-right">前残金額</th>
                                    <th class="{{ $thClass }} text-left">前残の出所</th>
                                    <th class="{{ $thClass }} text-right">仕入</th>
                                    <th class="{{ $thClass }} text-right">移入</th>
                                    <th class="{{ $thClass }} text-right">移出</th>
                                    <th class="{{ $thClass }} text-right">売上原価</th>
                                    <th class="{{ $thClass }} text-right">調整</th>
                                    <th class="{{ $thClass }} text-right">理論金額</th>
                                    <th class="{{ $thClass }} text-right">実棚金額</th>
                                    <th class="{{ $thClass }} text-right">差異金額</th>
                                    <th class="{{ $thClass }} text-left">入力者</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($amountRows as $row)
                                    @php
                                        $initCounted = $this->inputValue($row->counted_amount);
                                        $initOpening = $this->inputValue($row->opening_amount);
                                        $initOpeningDate = $row->opening_date?->toDateString() ?? '';
                                        $flowAmount = (float) $row->purchase_amount + (float) $row->transfer_in_amount - (float) $row->transfer_out_amount - (float) $row->sales_cost_amount + (float) $row->adjustment_amount;
                                    @endphp
                                    <tr wire:key="sundry-amount-{{ $row->id }}-u{{ $row->updated_at?->timestamp ?? 0 }}"
                                        x-data="{
                                            counted: @js($initCounted),
                                            originalCounted: @js($initCounted),
                                            opening: @js($initOpening),
                                            originalOpening: @js($initOpening),
                                            openingDate: @js($initOpeningDate),
                                            originalOpeningDate: @js($initOpeningDate),
                                            flow: {{ $flowAmount }},
                                            get system() { return Math.round((toNum(this.opening) ?? 0) + this.flow); },
                                            get diff() {
                                                let amount = toNum(this.counted);
                                                return amount === null ? null : Math.round(amount - this.system);
                                            },
                                            get changed() {
                                                return this.counted !== this.originalCounted
                                                    || this.opening !== this.originalOpening
                                                    || this.openingDate !== this.originalOpeningDate;
                                            },
                                            notify() {
                                                let fields = {};
                                                if (this.counted !== this.originalCounted) fields.counted_amount = this.counted === '' ? null : this.counted;
                                                if (this.opening !== this.originalOpening) fields.opening_amount = this.opening === '' ? 0 : this.opening;
                                                if (this.openingDate !== this.originalOpeningDate) fields.opening_date = this.openingDate;
                                                $dispatch('sundry-amount-change', { id: {{ $row->id }}, fields: fields });
                                            }
                                        }"
                                        :class="changed ? 'bg-amber-50' : ($el.rowIndex % 2 === 0 ? 'bg-white' : 'bg-slate-50')"
                                        class="hover:bg-sky-50">
                                        <td class="{{ $tdClass }} text-slate-600">{{ $row->category1_code }} {{ $row->category1_name }}</td>
                                        <td class="{{ $tdClass }} font-mono">{{ $row->category2_code }}</td>
                                        <td class="{{ $tdClass }}">
                                            {{ $row->category2_name }}
                                            @if ($row->is_additional)
                                                <span class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-bold text-amber-700">追加</span>
                                            @endif
                                        </td>
                                        @if ($isEditable)
                                            <td class="whitespace-nowrap border border-slate-300 px-1 py-0.5" @click.stop>
                                                <input type="date" :value="openingDate"
                                                    @change="openingDate = $event.target.value; notify()"
                                                    class="{{ $dateInputClass }}">
                                            </td>
                                            <td class="whitespace-nowrap border border-slate-300 px-1 py-0.5" @click.stop>
                                                <input type="text" inputmode="decimal" :value="opening"
                                                    @input="opening = clean($event.target.value); $event.target.value = opening; notify()"
                                                    class="{{ $amountInputClass }}" placeholder="0">
                                            </td>
                                        @else
                                            <td class="{{ $tdClass }} tabular-nums">{{ $row->opening_date?->format('Y/m/d') ?? '-' }}</td>
                                            <td class="{{ $tdClass }} text-right tabular-nums">{{ $this->formatAmount($row->opening_amount) }}</td>
                                        @endif
                                        <td class="{{ $tdClass }} text-slate-600">{{ $row->opening_source_label }}</td>
                                        <td class="{{ $tdClass }} text-right tabular-nums">{{ $this->formatAmount($row->purchase_amount) }}</td>
                                        <td class="{{ $tdClass }} text-right tabular-nums">{{ $this->formatAmount($row->transfer_in_amount) }}</td>
                                        <td class="{{ $tdClass }} text-right tabular-nums">{{ $this->formatAmount($row->transfer_out_amount) }}</td>
                                        <td class="{{ $tdClass }} text-right tabular-nums">{{ $this->formatAmount($row->sales_cost_amount) }}</td>
                                        <td class="{{ $tdClass }} text-right tabular-nums">{{ $this->formatAmount($row->adjustment_amount) }}</td>
                                        @if ($isEditable)
                                            <td class="{{ $tdClass }} text-right font-bold tabular-nums text-slate-700"
                                                x-text="'¥' + formatNumber(system)"></td>
                                            <td class="whitespace-nowrap border border-slate-300 px-1 py-0.5" @click.stop>
                                                <input type="text" inputmode="decimal" :value="counted"
                                                    @input="counted = clean($event.target.value); $event.target.value = counted; notify()"
                                                    @keydown.enter.prevent="$el.closest('tr').nextElementSibling?.querySelector('input[inputmode]:last-of-type')?.focus()"
                                                    class="{{ $amountInputClass }}" placeholder="-">
                                            </td>
                                            <td class="{{ $tdClass }} text-right font-bold tabular-nums"
                                                :class="{ 'text-green-700': diff > 0, 'text-red-700': diff < 0 }"
                                                x-text="diff !== null ? '¥' + formatNumber(diff) : '-'"></td>
                                        @else
                                            <td class="{{ $tdClass }} text-right font-bold tabular-nums text-slate-700">{{ $this->formatAmount($row->system_amount) }}</td>
                                            <td class="{{ $tdClass }} text-right tabular-nums">{{ $this->formatAmount($row->counted_amount) }}</td>
                                            <td class="{{ $tdClass }} text-right font-bold tabular-nums {{ $signClass($row->difference_amount) }}">{{ $this->formatAmount($row->difference_amount) }}</td>
                                        @endif
                                        <td class="{{ $tdClass }} text-slate-600">{{ $row->counted_by_name ?: '-' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>

                        {{-- 在庫金額報告書との突合（大分類別） --}}
                        @if ($reportReferences !== [])
                            <div class="border-y border-slate-200 bg-slate-50 px-3 py-2 text-xs font-bold text-slate-700">
                                在庫金額報告書との突合（大分類別・参考）
                                <span class="ml-2 font-normal text-slate-600">
                                    報告書ベースの理論金額 =（報告書の月末金額 − 在庫管理あり評価額）+ 月末の翌日からの受払。中分類の理論金額の合計と比べます。
                                    報告書の在庫管理なし商品は旧システム期間（5/1〜5/5）の売上原価・移動を含まないため、その分は差に出ます。
                                </span>
                            </div>
                            <table class="w-max min-w-full border-collapse text-xs">
                                <thead class="bg-slate-100 text-slate-700">
                                    <tr>
                                        <th class="{{ $thClass }} text-left">大分類</th>
                                        <th class="{{ $thClass }} text-left">報告書</th>
                                        <th class="{{ $thClass }} text-right">報告書 月末金額</th>
                                        <th class="{{ $thClass }} text-right">うち在庫管理あり</th>
                                        <th class="{{ $thClass }} text-right">非管理品 月末残高</th>
                                        <th class="{{ $thClass }} text-right">以降の受払</th>
                                        <th class="{{ $thClass }} text-right">報告書ベース理論金額</th>
                                        <th class="{{ $thClass }} text-right">中分類の理論金額 合計</th>
                                        <th class="{{ $thClass }} text-right">差（合計 − 報告書ベース）</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach ($reportReferences as $reference)
                                        <tr class="bg-white">
                                            <td class="{{ $tdClass }}">{{ $reference['category1_code'] }} {{ $reference['category1_name'] }}</td>
                                            <td class="{{ $tdClass }}">{{ $reference['report_month'] ? $reference['report_month'].' 月末' : '報告書なし' }}</td>
                                            <td class="{{ $tdClass }} text-right tabular-nums">{{ $this->formatAmount($reference['report_amount']) }}</td>
                                            <td class="{{ $tdClass }} text-right tabular-nums">{{ $this->formatAmount($reference['managed_amount']) }}</td>
                                            <td class="{{ $tdClass }} text-right tabular-nums">{{ $this->formatAmount($reference['opening_amount']) }}</td>
                                            <td class="{{ $tdClass }} text-right tabular-nums">{{ $this->formatAmount($reference['flow_amount']) }}</td>
                                            <td class="{{ $tdClass }} text-right font-bold tabular-nums">{{ $this->formatAmount($reference['system_amount']) }}</td>
                                            <td class="{{ $tdClass }} text-right font-bold tabular-nums">{{ $this->formatAmount($reference['detail_system_amount']) }}</td>
                                            <td class="{{ $tdClass }} text-right font-bold tabular-nums {{ $signClass($reference['difference_amount']) }}">{{ $this->formatAmount($reference['difference_amount']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @endif
                    @endif
                @endif

                {{-- 集計（中分類別） --}}
                @if ($listTab === 'summary')
                    @php
                        $summaryLines = [];
                        foreach ($summary['managed'] as $line) {
                            $summaryLines[] = ['在庫管理あり（中分類・数量）', $line, false];
                        }
                        $summaryLines[] = ['', $summary['totals']['managed'], true];
                        foreach ($summary['unmanaged'] as $line) {
                            $summaryLines[] = ['在庫管理なし（中分類・金額）', $line, false];
                        }
                        $summaryLines[] = ['', $summary['totals']['unmanaged'], true];
                        $summaryLines[] = ['', $summary['totals']['total'], true];
                    @endphp
                    <table class="w-max min-w-full border-collapse text-xs">
                        <thead class="sticky top-0 z-10 bg-slate-100 text-slate-700">
                            <tr>
                                <th class="{{ $thClass }} text-left">区分</th>
                                <th class="{{ $thClass }} text-left">分類CD</th>
                                <th class="{{ $thClass }} text-left">分類名</th>
                                <th class="{{ $thClass }} text-right">明細数</th>
                                <th class="{{ $thClass }} text-right">未入力</th>
                                <th class="{{ $thClass }} text-right">理論金額</th>
                                <th class="{{ $thClass }} text-right">実棚金額</th>
                                <th class="{{ $thClass }} text-right">差異金額</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($summaryLines as [$kind, $line, $isTotalLine])
                                <tr class="{{ $isTotalLine ? 'bg-slate-100 font-bold' : ($loop->even ? 'bg-slate-50' : 'bg-white') }}">
                                    <td class="{{ $tdClass }}">{{ $kind }}</td>
                                    <td class="{{ $tdClass }} font-mono">{{ $line['code'] }}</td>
                                    <td class="{{ $tdClass }}">{{ $line['name'] }}</td>
                                    <td class="{{ $tdClass }} text-right tabular-nums">{{ number_format($line['detail_count']) }}</td>
                                    <td class="{{ $tdClass }} text-right tabular-nums">{{ number_format($line['uncounted_count']) }}</td>
                                    <td class="{{ $tdClass }} text-right tabular-nums">{{ $this->formatAmount($line['system']) }}</td>
                                    <td class="{{ $tdClass }} text-right tabular-nums">{{ $this->formatAmount($line['counted']) }}</td>
                                    <td class="{{ $tdClass }} text-right font-bold tabular-nums {{ $signClass($line['difference']) }}">{{ $this->formatAmount($line['difference']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    <div class="px-3 py-2 text-xs text-slate-600">
                        未入力の明細は実棚金額・差異金額に含めていません。
                    </div>
                @endif
            </div>

            <div class="flex shrink-0 items-center justify-between border-t border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700">
                <div class="tabular-nums">
                    @if ($listTab === 'items' && $rows)
                        {{ number_format($pageFirst) }}-{{ number_format($pageLast) }} / {{ number_format($rows->total()) }}件
                    @endif
                    @if (! $isEditable)
                        <span class="ml-2 font-bold text-slate-600">{{ $record->status_label }}のため入力できません。</span>
                    @endif
                    <span x-show="changeCount > 0" x-cloak class="ml-2 font-bold text-red-700">未反映の入力があります。反映後にページ・タブを移動できます。</span>
                </div>
                @if ($listTab === 'items' && $rows)
                    <div class="flex items-center gap-1 font-bold">
                        <button type="button"
                            @click="guard(() => $wire.previousItemPage())"
                            x-bind:class="{ 'cursor-not-allowed opacity-40': changeCount > 0 }"
                            @disabled($rows->onFirstPage())
                            class="h-8 rounded-md border border-slate-300 bg-white px-3 disabled:cursor-not-allowed disabled:opacity-40 hover:bg-slate-100">
                            前へ
                        </button>
                        <span class="px-2 tabular-nums">{{ $rows->currentPage() }} / {{ $rows->lastPage() }}</span>
                        <button type="button"
                            @click="guard(() => $wire.nextItemPage())"
                            x-bind:class="{ 'cursor-not-allowed opacity-40': changeCount > 0 }"
                            @disabled(! $rows->hasMorePages())
                            class="h-8 rounded-md border border-slate-300 bg-white px-3 disabled:cursor-not-allowed disabled:opacity-40 hover:bg-slate-100">
                            次へ
                        </button>
                    </div>
                @endif
            </div>
        </div>
    </div>
</x-filament-panels::page>
