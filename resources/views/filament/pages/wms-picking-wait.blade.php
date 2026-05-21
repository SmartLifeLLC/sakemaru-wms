<x-filament-panels::page class="overflow-hidden">
    @php
        $waveGroup = $this->latestWaveGroup();
        $rows = $this->rows();
        $totalCount = $this->totalCount();
        $allCount = $this->countForTab('all');
        $shortageCount = $this->countForTab('shortage');
        $hasMore = $rows->count() < $totalCount;
        $adjustmentRows = $adjustModalOpen ? $this->adjustmentRows() : collect();
        $adjustmentRow = $adjustmentRows->first();
        $filterInputClass = 'h-8 w-full rounded-md border border-slate-300 bg-slate-50 px-2 text-xs text-slate-900 shadow-inner outline-none transition placeholder:text-slate-400 focus:border-sky-500 focus:bg-white focus:ring-1 focus:ring-sky-500';
        $filterSelectClass = 'h-8 w-full rounded-md border border-slate-300 bg-slate-50 px-2 text-xs text-slate-900 shadow-inner outline-none transition focus:border-sky-500 focus:bg-white focus:ring-1 focus:ring-sky-500';
    @endphp

    <div x-data="{ filtersOpen: true }" class="flex h-[calc(100vh-72px)] min-h-0 flex-col gap-2">
        <div class="shrink-0 overflow-hidden rounded-lg border border-slate-300 bg-slate-100 shadow-sm">
            <div class="flex items-center justify-between border-b border-slate-200 bg-slate-800 px-3 py-2 text-white">
                <div class="min-w-0">
                    <div class="truncate text-xs text-slate-300">
                        @if ($waveGroup)
                            選択WaveGroup: {{ $waveGroup->group_no }} / 生成 {{ $waveGroup->created_at?->format('Y/m/d H:i') }} / 対象 {{ number_format($allCount) }}件
                        @else
                            対象WaveGroupなし
                        @endif
                    </div>
                </div>
                <button type="button"
                    class="inline-flex items-center gap-1 rounded-md border border-slate-500 px-2 py-1 text-xs font-semibold text-slate-100 hover:bg-slate-700"
                    @click="filtersOpen = ! filtersOpen">
                    <x-filament::icon icon="heroicon-m-magnifying-glass" class="h-4 w-4" />
                    <span>検索条件</span>
                    <x-filament::icon icon="heroicon-m-chevron-down" class="h-4 w-4 transition" x-bind:class="{ 'rotate-180': filtersOpen }" />
                </button>
            </div>

            <form wire:submit.prevent="search" x-show="filtersOpen" x-collapse x-cloak class="bg-slate-100 p-2">
                <div class="grid grid-cols-2 items-end gap-2 md:grid-cols-6 xl:grid-cols-12">
                    <label class="space-y-1">
                        <span class="text-xs font-semibold text-slate-700">Wave生成日付</span>
                        <input type="date" wire:model="waveGeneratedDate" class="{{ $filterInputClass }}">
                    </label>
                    <label class="space-y-1 md:col-span-2">
                        <span class="text-xs font-semibold text-slate-700">WaveGroup <span class="text-red-600">*</span></span>
                        <select wire:model="waveGroupId" required class="{{ $filterSelectClass }}">
                            <option value="">指定してください</option>
                            @foreach ($this->waveGroupOptions() as $option)
                                <option value="{{ $option->id }}">{{ $option->group_no }} / 出荷 {{ $option->shipping_date?->format('m/d') }} / 生成 {{ $option->created_at?->format('m/d H:i') }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="space-y-1 md:col-span-2 xl:col-span-2">
                        <span class="text-xs font-semibold text-slate-700">出荷倉庫</span>
                        <select wire:model="warehouseId" class="{{ $filterSelectClass }}">
                            <option value="">すべて</option>
                            @foreach ($this->warehouseOptions() as $warehouse)
                                <option value="{{ $warehouse->id }}">[{{ $warehouse->code }}]{{ $warehouse->name }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="space-y-1 md:col-span-2 xl:col-span-2">
                        <span class="text-xs font-semibold text-slate-700">得意先</span>
                        <input type="text" wire:model.defer="customerSearch" placeholder="CD・名称" class="{{ $filterInputClass }}">
                    </label>
                    <label class="space-y-1 md:col-span-2 xl:col-span-2">
                        <span class="text-xs font-semibold text-slate-700">移動先</span>
                        <input type="text" wire:model.defer="destinationSearch" placeholder="倉庫CD・名称" class="{{ $filterInputClass }}">
                    </label>
                    <label class="space-y-1">
                        <span class="text-xs font-semibold text-slate-700">欠品</span>
                        <select wire:model.defer="shortage" class="{{ $filterSelectClass }}">
                            <option value="">すべて</option>
                            <option value="with">引当欠品あり</option>
                            <option value="without">引当欠品なし</option>
                        </select>
                    </label>
                    <label class="space-y-1">
                        <span class="text-xs font-semibold text-slate-700">商品CD</span>
                        <input type="text" wire:model.defer="itemCode" class="{{ $filterInputClass }}">
                    </label>
                    <label class="space-y-1">
                        <span class="text-xs font-semibold text-slate-700">棚番</span>
                        <input type="text" wire:model.defer="locationSearch" class="{{ $filterInputClass }}">
                    </label>
                    <label class="space-y-1">
                        <span class="text-xs font-semibold text-slate-700">伝票番号</span>
                        <input type="text" wire:model.defer="serialNo" class="{{ $filterInputClass }}">
                    </label>
                    <label class="space-y-1">
                        <span class="text-xs font-semibold text-slate-700">配送コース</span>
                        <input type="text" wire:model.defer="deliveryCourseSearch" placeholder="CD・名称" class="{{ $filterInputClass }}">
                    </label>
                    <label class="space-y-1 md:col-span-2 xl:col-span-2">
                        <span class="text-xs font-semibold text-slate-700">商品</span>
                        <input type="text" wire:model.defer="itemSearch" placeholder="商品CD・商品名" class="{{ $filterInputClass }}">
                    </label>
                    <div class="flex justify-end gap-2 xl:col-span-2">
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

        <div class="flex min-h-0 flex-1 flex-col overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
            <div class="flex flex-wrap items-end justify-between gap-2 border-b border-slate-200 bg-green-700 px-3 pt-2 text-white">
                <div class="flex items-end gap-1">
                    <button type="button"
                        wire:click="setListTab('all')"
                        class="relative inline-flex h-10 items-center gap-2 rounded-t-md border px-3 text-xs font-bold transition {{ $this->listTab === 'all' ? 'border-slate-200 border-b-white bg-white text-green-800 shadow-sm' : 'border-green-700 bg-green-800 text-white/85 hover:bg-green-900 hover:text-white' }}">
                        @if ($this->listTab === 'all')
                            <span class="absolute inset-x-2 top-0 h-0.5 rounded-full bg-green-600"></span>
                        @endif
                        <span>ピッキング商品リスト</span>
                        <span class="rounded-full px-2 py-0.5 text-[11px] font-black tabular-nums {{ $this->listTab === 'all' ? 'bg-green-100 text-green-800' : 'bg-white/15 text-white ring-1 ring-white/25' }}">
                            {{ number_format($allCount) }}
                        </span>
                    </button>
                    <button type="button"
                        wire:click="setListTab('shortage')"
                        class="relative inline-flex h-10 items-center gap-2 rounded-t-md border px-3 text-xs font-bold transition {{ $this->listTab === 'shortage' ? 'border-slate-200 border-b-white bg-white text-red-700 shadow-sm' : 'border-green-700 bg-green-800 text-white/85 hover:bg-green-900 hover:text-white' }}">
                        @if ($this->listTab === 'shortage')
                            <span class="absolute inset-x-2 top-0 h-0.5 rounded-full bg-red-600"></span>
                        @endif
                        <span>欠品リスト</span>
                        <span class="rounded-full px-2 py-0.5 text-[11px] font-black tabular-nums {{ $this->listTab === 'shortage' ? 'bg-red-100 text-red-700' : 'bg-white/15 text-white ring-1 ring-white/25' }}">
                            {{ number_format($shortageCount) }}
                        </span>
                    </button>
                    <button type="button"
                        wire:click="sortBySecondaryV2"
                        class="ml-2 inline-flex h-8 items-center rounded-md px-3 text-xs font-bold transition {{ $this->secondaryV2Ordering ? 'bg-white text-sky-700 shadow-sm' : 'bg-green-800 text-white/90 hover:bg-green-900 hover:text-white' }}">
                        2次リスト整列
                    </button>
                </div>
                <div class="flex items-center gap-3 pb-2">
                    <div class="rounded-full bg-green-900/40 px-3 py-1 text-sm font-black text-white tabular-nums">{{ number_format($rows->count()) }} / {{ number_format($totalCount) }}件</div>
                    {{ $this->getAction('assignPickers') }}
                    {{ $this->getAction('forcePicking') }}
                    {{ $this->getAction('completePicking') }}
                    <div wire:loading class="text-xs">読込中...</div>
                </div>
            </div>

            <div x-data="pickingWaitTableScroll()" x-ref="scrollArea" @mousedown="startDrag" @mouseup="stopDrag" @mouseleave="stopDrag" @mousemove="drag"
                class="min-h-0 flex-1 overflow-auto cursor-grab">
                @if (! $waveGroup)
                    <div class="p-8 text-center text-sm text-slate-500">指定日のWaveGroupがありません。</div>
                @elseif ($rows->isEmpty())
                    <div class="p-8 text-center text-sm text-slate-500">条件に一致する商品はありません。</div>
                @else
                    <table class="w-max min-w-full border-collapse text-xs">
                        <thead class="sticky top-0 z-10 bg-slate-100 text-slate-700">
                            <tr>
                                <th class="border border-slate-300 px-2 py-2 text-left">状態</th>
                                <th class="border border-slate-300 px-2 py-2 text-left">欠品</th>
                                <th class="border border-slate-300 px-2 py-2 text-left">
                                    <button type="button" wire:click="sortBy('serial_id')" class="inline-flex items-center gap-1 font-bold hover:text-sky-700">
                                        <span>伝票番号</span>
                                        <span class="text-[10px]">{{ $this->sortIndicator('serial_id') }}</span>
                                    </button>
                                </th>
                                <th class="border border-slate-300 px-2 py-2 text-left">得意先/移動先</th>
                                <th class="border border-slate-300 px-2 py-2 text-left">棚番</th>
                                <th class="border border-slate-300 px-2 py-2 text-left">packaging</th>
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
                                <th class="border border-slate-300 px-2 py-2 text-right">受注<br><span class="text-[10px] font-normal">ケース/バラ/総バラ</span></th>
                                <th class="border border-slate-300 px-2 py-2 text-right">引当<br><span class="text-[10px] font-normal">ケース/バラ/総バラ</span></th>
                                <th class="border border-slate-300 px-2 py-2 text-right">ピック<br><span class="text-[10px] font-normal">ケース/バラ/総バラ</span></th>
                                <th class="border border-slate-300 px-2 py-2 text-right">欠品数<br><span class="text-[10px] font-normal">ケース/バラ/総バラ</span></th>
                                <th class="border border-slate-300 px-2 py-2 text-left">
                                    <button type="button" wire:click="sortBy('delivery_course')" class="inline-flex items-center gap-1 font-bold hover:text-sky-700">
                                        <span>配送コース</span>
                                        <span class="text-[10px]">{{ $this->sortIndicator('delivery_course') }}</span>
                                    </button>
                                </th>
                                <th class="border border-slate-300 px-2 py-2 text-left">出荷倉庫</th>
                                <th class="border border-slate-300 px-2 py-2 text-left">Wave</th>
                                <th class="border border-slate-300 px-2 py-2 text-left">タスク</th>
                            </tr>
                        </thead>
                        <tbody>
                            @php
                                $previousSecondaryV2Group = null;
                            @endphp
                            @foreach ($rows as $row)
                                @php
                                    $currentSecondaryV2Group = $row->secondary_v2_group_label ?? '';
                                    $orderedBreakdown = $this->quantityBreakdown($row->ordered_qty, $row->ordered_qty_type, $row->capacity_case);
                                    $plannedBreakdown = $this->quantityBreakdown($row->planned_qty, $row->planned_qty_type, $row->capacity_case);
                                    $pickedBreakdown = $this->quantityBreakdown($row->picked_qty, $row->picked_qty_type ?? $row->planned_qty_type, $row->capacity_case);
                                    $shortageBreakdown = $this->quantityBreakdown($row->shortage_qty, \App\Enums\QuantityType::PIECE->value, $row->capacity_case, false);
                                    $isCompletedRow = $row->task_status === \App\Models\WmsPickingTask::STATUS_COMPLETED;
                                @endphp
                                @if ($this->secondaryV2Ordering && $currentSecondaryV2Group !== $previousSecondaryV2Group)
                                    <tr class="bg-slate-800 text-white" wire:key="secondary-v2-group-{{ $row->secondary_v2_group_sort ?? 'none' }}-{{ $loop->index }}">
                                        <td colspan="16" class="sticky left-0 z-[6] border border-slate-700 px-3 py-1.5 text-sm font-bold">
                                            {{ $currentSecondaryV2Group ?: 'フロア未設定' }}
                                        </td>
                                    </tr>
                                    @php
                                        $previousSecondaryV2Group = $currentSecondaryV2Group;
                                    @endphp
                                @endif
                                <tr wire:key="pick-wait-row-{{ $row->id }}" wire:click="openPlannedQtyModal({{ $row->id }})" class="cursor-pointer {{ $isCompletedRow ? 'bg-slate-200 text-slate-500 hover:bg-slate-300' : 'odd:bg-white even:bg-slate-50 hover:bg-sky-50' }}">
                                    <td class="sticky left-0 z-[5] whitespace-nowrap border border-slate-300 bg-inherit px-2 py-1">
                                        <span class="rounded px-2 py-0.5 text-xs font-bold {{ $isCompletedRow ? 'bg-slate-500 text-white' : 'bg-slate-100 text-slate-700' }}">{{ $this->formatPickingStatus($row->task_status) }}</span>
                                    </td>
                                    <td class="whitespace-nowrap border border-slate-300 px-2 py-1">
                                        @if ($row->has_soft_shortage)
                                            <span class="rounded bg-red-100 px-2 py-0.5 font-bold text-red-700">引当欠品</span>
                                        @elseif ($row->has_shortage || (int) $row->shortage_qty > 0)
                                            <span class="rounded bg-orange-100 px-2 py-0.5 font-bold text-orange-700">ピッキング欠品</span>
                                        @else
                                            <span class="text-slate-400">-</span>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap border border-slate-300 px-2 py-1 font-mono">{{ $row->serial_id ?: '-' }}</td>
                                    <td class="whitespace-nowrap border border-slate-300 px-2 py-1">
                                        @if ($row->source_type === \App\Models\WmsPickingItemResult::SOURCE_TYPE_STOCK_TRANSFER)
                                            [移動]{{ $row->destination_code ? '['.$row->destination_code.']' : '' }}{{ $row->destination_name ?: '-' }}
                                        @else
                                            {{ $row->customer_code ? '['.$row->customer_code.']' : '' }}{{ $row->customer_name ?: '-' }}
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap border border-slate-300 px-2 py-1 font-mono">{{ $row->location_code ?: '-' }}</td>
                                    <td class="whitespace-nowrap border border-slate-300 px-2 py-1">{{ $row->packaging ?: '-' }}</td>
                                    <td class="whitespace-nowrap border border-slate-300 px-2 py-1 font-mono">{{ $row->item_code ?: '-' }}</td>
                                    <td class="min-w-[320px] border border-slate-300 px-2 py-1">{{ $row->item_name ?: '-' }}</td>
                                    <td class="whitespace-nowrap border border-slate-300 px-2 py-1 text-right">
                                        <div class="grid min-w-[108px] grid-cols-3 gap-1 text-[11px] font-semibold text-slate-500">
                                            <span>ケース</span><span>バラ</span><span>総</span>
                                        </div>
                                        <div class="grid min-w-[108px] grid-cols-3 gap-1 text-base font-bold text-slate-900">
                                            <span>{{ number_format($orderedBreakdown['case']) }}</span><span>{{ number_format($orderedBreakdown['piece']) }}</span><span>{{ number_format($orderedBreakdown['total']) }}</span>
                                        </div>
                                    </td>
                                    <td class="whitespace-nowrap border border-slate-300 px-2 py-1 text-right">
                                        <div class="grid min-w-[108px] grid-cols-3 gap-1 text-[11px] font-semibold text-sky-600">
                                            <span>ケース</span><span>バラ</span><span>総</span>
                                        </div>
                                        <div class="grid min-w-[108px] grid-cols-3 gap-1 text-base font-bold text-sky-700">
                                            <span>{{ number_format($plannedBreakdown['case']) }}</span><span>{{ number_format($plannedBreakdown['piece']) }}</span><span>{{ number_format($plannedBreakdown['total']) }}</span>
                                        </div>
                                    </td>
                                    <td class="whitespace-nowrap border border-slate-300 px-2 py-1 text-right">
                                        @if ($pickedBreakdown['total'] === null)
                                            <span class="text-base font-bold text-slate-400">-</span>
                                        @else
                                            <div class="grid min-w-[108px] grid-cols-3 gap-1 text-[11px] font-semibold text-slate-500">
                                                <span>ケース</span><span>バラ</span><span>総</span>
                                            </div>
                                            <div class="grid min-w-[108px] grid-cols-3 gap-1 text-base font-bold text-slate-900">
                                                <span>{{ number_format($pickedBreakdown['case']) }}</span><span>{{ number_format($pickedBreakdown['piece']) }}</span><span>{{ number_format($pickedBreakdown['total']) }}</span>
                                            </div>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap border border-slate-300 px-2 py-1 text-right">
                                        <div class="grid min-w-[108px] grid-cols-3 gap-1 text-[11px] font-semibold {{ (int) $row->shortage_qty > 0 ? 'text-red-600' : 'text-slate-500' }}">
                                            <span>ケース</span><span>バラ</span><span>総</span>
                                        </div>
                                        <div class="grid min-w-[108px] grid-cols-3 gap-1 text-base font-bold {{ (int) $row->shortage_qty > 0 ? 'text-red-700' : 'text-slate-500' }}">
                                            <span>{{ number_format($shortageBreakdown['case']) }}</span><span>{{ number_format($shortageBreakdown['piece']) }}</span><span>{{ number_format($shortageBreakdown['total']) }}</span>
                                        </div>
                                    </td>
                                    <td class="whitespace-nowrap border border-slate-300 px-2 py-1">{{ $row->delivery_course_code ? '['.$row->delivery_course_code.']'.$row->delivery_course_name : '-' }}</td>
                                    <td class="whitespace-nowrap border border-slate-300 px-2 py-1">{{ $row->warehouse_code ? '['.$row->warehouse_code.']'.$row->warehouse_name : '-' }}</td>
                                    <td class="whitespace-nowrap border border-slate-300 px-2 py-1 font-mono">{{ $row->wave_no ?: '-' }}</td>
                                    <td class="whitespace-nowrap border border-slate-300 px-2 py-1">
                                        <a href="{{ \App\Filament\Resources\WmsPickingTasks\WmsPickingItemEditResource::getUrl('index', ['tableFilters' => ['picking_task_id' => ['value' => $row->picking_task_id]]]) }}" onclick="event.stopPropagation()" class="font-semibold text-sky-700 hover:underline">
                                            #{{ $row->picking_task_id }}
                                        </a>
                                    </td>
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

    @if ($adjustModalOpen)
        <div class="fixed inset-0 z-[80] flex items-center justify-center p-4" wire:keydown.escape.window="closePlannedQtyModal">
            <button type="button" class="absolute inset-0 bg-black/40" @if (! $quantityQueueWaiting) wire:click="closePlannedQtyModal" @endif aria-label="閉じる"></button>

            <div class="relative flex max-h-[86vh] w-full max-w-4xl flex-col overflow-hidden rounded-lg bg-white shadow-xl lg:max-w-[50vw]">
                <div class="flex items-center justify-between bg-slate-800 px-4 py-3 text-white">
                    <div>
                        <div class="text-sm font-bold">引当数修正</div>
                        <div class="text-xs text-slate-300">選択した明細を調節します</div>
                    </div>
                    <button type="button" wire:click="closePlannedQtyModal" @disabled($quantityQueueWaiting) class="rounded p-1 text-slate-200 hover:bg-slate-700 hover:text-white disabled:cursor-not-allowed disabled:opacity-40" aria-label="閉じる">
                        <x-filament::icon icon="heroicon-m-x-mark" class="h-5 w-5" />
                    </button>
                </div>

                <div class="min-h-0 flex-1 overflow-auto p-4">
                    @if ($adjustmentRow)
                        @php
                            $adjustmentKey = (string) $adjustmentRow->id;
                            $selectedOrderedQtyType = $orderedQtyTypeInputs[$adjustmentKey] ?? $adjustmentRow->ordered_qty_type;
                            $displayPlannedQtyType = $selectedOrderedQtyType;
                            $displayOrderedQty = (int) ($orderedQtyInputs[$adjustmentKey] ?? $adjustmentRow->ordered_qty);
                            $displayPlannedQty = (int) ($plannedQtyInputs[$adjustmentKey] ?? $adjustmentRow->planned_qty);
                            $displayPickedQty = (int) ($pickedQtyInputs[$adjustmentKey] ?? $adjustmentRow->picked_qty ?? 0);
                            $capacityCase = max(1, (int) ($adjustmentRow->capacity_case ?? 1));
                            $capacityCarton = max(1, (int) ($adjustmentRow->capacity_carton ?? 1));
                            $displayOrderedPieces = match ($selectedOrderedQtyType) {
                                \App\Enums\QuantityType::CASE->value => $displayOrderedQty * $capacityCase,
                                \App\Enums\QuantityType::CARTON->value => $displayOrderedQty * $capacityCarton,
                                default => $displayOrderedQty,
                            };
                            $displayPlannedPieces = match ($displayPlannedQtyType) {
                                \App\Enums\QuantityType::CASE->value => $displayPlannedQty * $capacityCase,
                                \App\Enums\QuantityType::CARTON->value => $displayPlannedQty * $capacityCarton,
                                default => $displayPlannedQty,
                            };
                            $displayShortageQty = max(0, $displayOrderedPieces - $displayPlannedPieces);
                            $displayOrderedBreakdown = $this->quantityBreakdown($displayOrderedQty, $selectedOrderedQtyType, $adjustmentRow->capacity_case);
                            $displayPlannedBreakdown = $this->quantityBreakdown($displayPlannedQty, $displayPlannedQtyType, $adjustmentRow->capacity_case);
                            $displayPickedBreakdown = $this->quantityBreakdown($displayPickedQty, $displayPlannedQtyType, $adjustmentRow->capacity_case);
                            $displayShortageBreakdown = $this->quantityBreakdown($displayShortageQty, \App\Enums\QuantityType::PIECE->value, $adjustmentRow->capacity_case, false);
                        @endphp
                        <div class="mb-3 grid grid-cols-1 gap-2 text-xs md:grid-cols-3">
                            <div class="rounded border border-slate-200 bg-slate-50 p-2">
                                <div class="text-slate-500">伝票番号</div>
                                <div class="font-mono font-bold text-slate-800">{{ $adjustmentRow->serial_id ?: '-' }}</div>
                            </div>
                            <div class="rounded border border-slate-200 bg-slate-50 p-2">
                                <div class="text-slate-500">商品CD</div>
                                <div class="font-mono font-bold text-slate-800">{{ $adjustmentRow->item_code ?: '-' }}</div>
                            </div>
                            <div class="rounded border border-slate-200 bg-slate-50 p-2">
                                <div class="text-slate-500">棚番</div>
                                <div class="font-mono font-bold text-slate-800">{{ $adjustmentRow->location_code ?: '-' }}</div>
                            </div>
                            <div class="rounded border border-slate-200 bg-slate-50 p-2">
                                <div class="text-slate-500">配送コース</div>
                                <div class="font-bold text-slate-800">{{ $adjustmentRow->delivery_course_code ? '['.$adjustmentRow->delivery_course_code.']'.$adjustmentRow->delivery_course_name : '-' }}</div>
                            </div>
                            <div class="rounded border border-slate-200 bg-slate-50 p-2">
                                <div class="text-slate-500">packaging</div>
                                <div class="font-bold text-slate-800">{{ $adjustmentRow->packaging ?: '-' }}</div>
                            </div>
                            <div class="rounded border border-slate-200 bg-slate-50 p-2">
                                <div class="text-slate-500">状態</div>
                                <div class="font-bold text-slate-800">{{ $this->formatPickingStatus($adjustmentRow->task_status) }}</div>
                            </div>
                        </div>

                        <div class="mb-4 rounded border border-slate-200 p-3">
                            <div class="text-xs text-slate-500">商品名</div>
                            <div class="text-sm font-bold text-slate-900">{{ $adjustmentRow->item_name ?: '-' }}</div>
                        </div>

                        <div class="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4">
                            <div class="rounded border border-slate-200 bg-white p-3 text-right">
                                <div class="text-xs font-semibold text-slate-500">受注</div>
                                <div class="mt-1 grid grid-cols-3 gap-2 text-[11px] font-semibold text-slate-500"><span>ケース</span><span>バラ</span><span>総バラ</span></div>
                                <div class="grid grid-cols-3 gap-2 text-2xl font-bold text-slate-900"><span>{{ number_format($displayOrderedBreakdown['case']) }}</span><span>{{ number_format($displayOrderedBreakdown['piece']) }}</span><span>{{ number_format($displayOrderedBreakdown['total']) }}</span></div>
                            </div>
                            <div class="rounded border border-sky-200 bg-sky-50 p-3 text-right">
                                <div class="text-xs font-semibold text-sky-700">引当</div>
                                <div class="mt-1 grid grid-cols-3 gap-2 text-[11px] font-semibold text-sky-600"><span>ケース</span><span>バラ</span><span>総バラ</span></div>
                                <div class="grid grid-cols-3 gap-2 text-2xl font-bold text-sky-800"><span>{{ number_format($displayPlannedBreakdown['case']) }}</span><span>{{ number_format($displayPlannedBreakdown['piece']) }}</span><span>{{ number_format($displayPlannedBreakdown['total']) }}</span></div>
                            </div>
                            <div class="rounded border border-slate-200 bg-white p-3 text-right">
                                <div class="text-xs font-semibold text-slate-500">ピック</div>
                                @if ($displayPickedBreakdown['total'] === null)
                                    <div class="text-3xl font-bold text-slate-400">-</div>
                                @else
                                    <div class="mt-1 grid grid-cols-3 gap-2 text-[11px] font-semibold text-slate-500"><span>ケース</span><span>バラ</span><span>総バラ</span></div>
                                    <div class="grid grid-cols-3 gap-2 text-2xl font-bold text-slate-900"><span>{{ number_format($displayPickedBreakdown['case']) }}</span><span>{{ number_format($displayPickedBreakdown['piece']) }}</span><span>{{ number_format($displayPickedBreakdown['total']) }}</span></div>
                                @endif
                            </div>
                            <div class="rounded border border-red-200 bg-red-50 p-3 text-right">
                                <div class="text-xs font-semibold text-red-700">欠品数</div>
                                <div class="mt-1 grid grid-cols-3 gap-2 text-[11px] font-semibold text-red-600"><span>ケース</span><span>バラ</span><span>総バラ</span></div>
                                <div class="grid grid-cols-3 gap-2 text-2xl font-bold text-red-700"><span>{{ number_format($displayShortageBreakdown['case']) }}</span><span>{{ number_format($displayShortageBreakdown['piece']) }}</span><span>{{ number_format($displayShortageBreakdown['total']) }}</span></div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 gap-3 md:grid-cols-4">
                            <label class="space-y-2 rounded-lg border-2 border-amber-200 bg-amber-50 p-3">
                                <span class="block text-sm font-bold text-slate-800">受注単位</span>
                                <select wire:model.live="orderedQtyTypeInputs.{{ $adjustmentRow->id }}" class="h-12 w-full rounded-md border-2 border-amber-400 bg-white px-3 text-base font-bold text-slate-900 shadow-sm focus:border-amber-600 focus:ring-2 focus:ring-amber-300">
                                    <option value="{{ \App\Enums\QuantityType::PIECE->value }}">{{ \App\Enums\QuantityType::PIECE->name() }}</option>
                                    <option value="{{ \App\Enums\QuantityType::CASE->value }}">{{ \App\Enums\QuantityType::CASE->name() }}</option>
                                </select>
                            </label>
                            <label class="space-y-2 rounded-lg border-2 border-amber-200 bg-amber-50 p-3">
                                <span class="block text-sm font-bold text-slate-800">受注数量</span>
                                <input type="text" inputmode="numeric" pattern="[0-9]*" wire:model.live.debounce.300ms="orderedQtyInputs.{{ $adjustmentRow->id }}" x-on:wheel.prevent.stop x-on:keydown="if (['e','E','+','-','.'].includes($event.key)) $event.preventDefault()" x-on:input="$event.target.value = $event.target.value.replace(/[０-９]/g, char => String.fromCharCode(char.charCodeAt(0) - 0xFEE0)).replace(/[^0-9]/g, '')" x-on:paste.prevent="$event.target.value = ($event.clipboardData.getData('text') || '').replace(/[０-９]/g, char => String.fromCharCode(char.charCodeAt(0) - 0xFEE0)).replace(/[^0-9]/g, ''); $event.target.dispatchEvent(new Event('input', { bubbles: true }))" class="h-12 w-full rounded-md border-2 border-amber-400 bg-white px-3 text-right text-xl font-bold text-slate-900 shadow-sm focus:border-amber-600 focus:ring-2 focus:ring-amber-300">
                            </label>
                            <label class="space-y-2 rounded-lg border-2 border-sky-200 bg-sky-50 p-3">
                                <span class="block text-sm font-bold text-slate-800">引当数</span>
                                <input type="text" inputmode="numeric" pattern="[0-9]*" wire:model.live.debounce.300ms="plannedQtyInputs.{{ $adjustmentRow->id }}" x-on:wheel.prevent.stop x-on:keydown="if (['e','E','+','-','.'].includes($event.key)) $event.preventDefault()" x-on:input="$event.target.value = $event.target.value.replace(/[０-９]/g, char => String.fromCharCode(char.charCodeAt(0) - 0xFEE0)).replace(/[^0-9]/g, '')" x-on:paste.prevent="$event.target.value = ($event.clipboardData.getData('text') || '').replace(/[０-９]/g, char => String.fromCharCode(char.charCodeAt(0) - 0xFEE0)).replace(/[^0-9]/g, ''); $event.target.dispatchEvent(new Event('input', { bubbles: true }))" class="h-12 w-full rounded-md border-2 border-sky-400 bg-white px-3 text-right text-xl font-bold text-slate-900 shadow-sm focus:border-sky-600 focus:ring-2 focus:ring-sky-300">
                            </label>
                            <label class="space-y-2 rounded-lg border-2 border-slate-300 bg-slate-50 p-3">
                                <span class="block text-sm font-bold text-slate-800">ピック数</span>
                                <input type="text" inputmode="numeric" pattern="[0-9]*" wire:model.live.debounce.300ms="pickedQtyInputs.{{ $adjustmentRow->id }}" x-on:wheel.prevent.stop x-on:keydown="if (['e','E','+','-','.'].includes($event.key)) $event.preventDefault()" x-on:input="$event.target.value = $event.target.value.replace(/[０-９]/g, char => String.fromCharCode(char.charCodeAt(0) - 0xFEE0)).replace(/[^0-9]/g, '')" x-on:paste.prevent="$event.target.value = ($event.clipboardData.getData('text') || '').replace(/[０-９]/g, char => String.fromCharCode(char.charCodeAt(0) - 0xFEE0)).replace(/[^0-9]/g, ''); $event.target.dispatchEvent(new Event('input', { bubbles: true }))" class="h-12 w-full rounded-md border-2 border-slate-400 bg-white px-3 text-right text-xl font-bold text-slate-900 shadow-sm focus:border-slate-600 focus:ring-2 focus:ring-slate-300">
                            </label>
                        </div>

                        <div class="mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
                            受注数量・受注単位は既存queueへ登録し、売上伝票・移動伝票の手動修正ロジックで更新します。
                        </div>
                    @else
                        <div class="p-8 text-center text-sm text-slate-500">対象明細が見つかりません。</div>
                    @endif
                </div>

                <div class="flex justify-end gap-2 bg-slate-50 px-4 py-3">
                    <button type="button" wire:click="closePlannedQtyModal" @disabled($quantityQueueWaiting) class="rounded-md border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700 hover:bg-white disabled:cursor-not-allowed disabled:opacity-50">
                        修正せず閉じる
                    </button>
                    <button type="button" wire:click="saveAdjustmentChanges" @disabled($quantityQueueWaiting) class="rounded-md bg-amber-600 px-4 py-1.5 text-sm font-bold text-white hover:bg-amber-700 disabled:cursor-not-allowed disabled:opacity-50">
                        修正する
                    </button>
                    <button type="button" wire:click="saveAdjustmentChanges(true)" @disabled($quantityQueueWaiting) class="rounded-md bg-green-700 px-4 py-1.5 text-sm font-bold text-white hover:bg-green-800 disabled:cursor-not-allowed disabled:opacity-50">
                        ピッキングする
                    </button>
                </div>

                @if ($quantityQueueWaiting)
                    <div class="absolute inset-0 z-10 flex items-center justify-center bg-white/80 backdrop-blur-[1px]" wire:poll.1s="pollQuantityUpdateQueue">
                        <div class="w-[min(24rem,90%)] rounded-lg border border-slate-200 bg-white p-5 text-center shadow-xl">
                            <div class="mx-auto mb-3 h-10 w-10 animate-spin rounded-full border-4 border-slate-200 border-t-slate-800"></div>
                            <div class="text-sm font-bold text-slate-900">queue処理中</div>
                            <div class="mt-1 text-xs leading-5 text-slate-600">{{ $quantityQueueMessage ?: '完了までお待ちください。' }}</div>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    @endif

    <script>
        function pickingWaitTableScroll() {
            return {
                isDragging: false,
                startX: 0,
                startY: 0,
                scrollLeft: 0,
                scrollTop: 0,
                startDrag(e) {
                    if (['INPUT', 'BUTTON', 'A', 'SELECT'].includes(e.target.tagName)) return;
                    this.isDragging = true;
                    this.$refs.scrollArea.style.cursor = 'grabbing';
                    this.startX = e.pageX;
                    this.startY = e.pageY;
                    this.scrollLeft = this.$refs.scrollArea.scrollLeft;
                    this.scrollTop = this.$refs.scrollArea.scrollTop;
                },
                stopDrag() {
                    this.isDragging = false;
                    this.$refs.scrollArea.style.cursor = 'grab';
                },
                drag(e) {
                    if (!this.isDragging) return;
                    this.$refs.scrollArea.scrollLeft = this.scrollLeft - (e.pageX - this.startX);
                    this.$refs.scrollArea.scrollTop = this.scrollTop - (e.pageY - this.startY);
                },
            };
        }
    </script>
</x-filament-panels::page>
