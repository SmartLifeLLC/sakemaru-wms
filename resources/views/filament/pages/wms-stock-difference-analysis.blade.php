<x-filament-panels::page>
    @php
        $snapshots = $this->snapshotOptions();
        $warehouses = $this->warehouseOptions();
        $itemCandidates = $this->itemCandidates();
        $selectedItem = $this->selectedItem();
        $hasResult = $this->hasSearched && $selectedItem !== null;
        $comparison = $hasResult ? $this->comparison() : [
            'rows' => collect(),
            'totals' => [
                'from_current' => 0,
                'to_current' => 0,
                'diff_current' => 0,
                'from_reserved' => 0,
                'to_reserved' => 0,
                'diff_reserved' => 0,
                'from_available' => 0,
                'to_available' => 0,
                'diff_available' => 0,
                'from_incoming' => 0,
                'to_incoming' => 0,
                'diff_incoming' => 0,
            ],
        ];
        $totals = $comparison['totals'];
        $summaryRows = $comparison['rows'];
        $timeline = $hasResult ? $this->timeline() : collect();
        $lotDifferences = $hasResult ? $this->lotDifferences() : collect();

        $diffClass = fn (int $value): string => $value > 0
            ? 'text-emerald-700 dark:text-emerald-400'
            : ($value < 0 ? 'text-rose-700 dark:text-rose-400' : 'text-gray-700 dark:text-gray-300');
        $signed = fn (int $value): string => ($value > 0 ? '+' : '').number_format($value);
    @endphp

    <div class="space-y-4 rounded-lg bg-slate-100 p-4 dark:bg-gray-950">
        <div class="bg-slate-50 dark:bg-gray-900 border border-slate-300 dark:border-gray-700 rounded-lg p-4 shadow-sm">
            <div class="grid grid-cols-1 gap-3 xl:grid-cols-12">
                <div class="xl:col-span-4">
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">商品検索</label>
                    <div class="flex gap-2">
                        <input
                            type="text"
                            wire:model.live.debounce.300ms="itemSearch"
                            wire:keydown.enter="search"
                            placeholder="商品CD / 商品名"
                            class="block w-full rounded-md border-slate-300 bg-white dark:border-gray-700 dark:bg-gray-950 text-sm"
                        />
                        @if ($selectedItem)
                            <button type="button" wire:click="clearItem" class="shrink-0 rounded-md border border-gray-300 dark:border-gray-700 px-3 text-sm text-gray-700 dark:text-gray-300">
                                解除
                            </button>
                        @endif
                    </div>

                    @if ($selectedItem)
                        <div class="mt-2 rounded-md border border-slate-200 bg-white dark:border-gray-800 dark:bg-gray-950 px-3 py-2 text-sm">
                            <span class="font-mono text-gray-700 dark:text-gray-300">{{ $selectedItem->code }}</span>
                            <span class="ml-2 font-medium text-gray-900 dark:text-white">{{ $selectedItem->name }}</span>
                        </div>
                    @elseif ($itemCandidates->isNotEmpty())
                        <div class="mt-2 max-h-56 overflow-y-auto rounded-md border border-slate-300 dark:border-gray-800 bg-white dark:bg-gray-950 shadow-sm">
                            @foreach ($itemCandidates as $item)
                                <button
                                    type="button"
                                    wire:click="selectItem({{ $item->id }})"
                                    class="flex w-full items-center gap-3 px-3 py-2 text-left text-sm hover:bg-gray-50 dark:hover:bg-gray-900"
                                >
                                    <span class="w-28 shrink-0 font-mono text-gray-700 dark:text-gray-300">{{ $item->code }}</span>
                                    <span class="text-gray-900 dark:text-white">{{ $item->name }}</span>
                                </button>
                            @endforeach
                        </div>
                    @endif
                </div>

                <div class="xl:col-span-2">
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">倉庫</label>
                    <select wire:model.live="selectedWarehouseId" class="block w-full rounded-md border-slate-300 bg-white dark:border-gray-700 dark:bg-gray-950 text-sm">
                        <option value="">全倉庫</option>
                        @foreach ($warehouses as $warehouse)
                            <option value="{{ $warehouse->id }}">[{{ $warehouse->code }}]{{ $warehouse->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="xl:col-span-2">
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">開始時点</label>
                    <select wire:model.live="fromSnapshot" class="block w-full rounded-md border-slate-300 bg-white dark:border-gray-700 dark:bg-gray-950 text-sm">
                        @foreach ($snapshots as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="xl:col-span-1 flex items-end">
                    <button type="button" wire:click="swapSnapshots" class="w-full rounded-md border border-slate-300 bg-white dark:border-gray-700 dark:bg-gray-950 px-3 py-2 text-sm text-gray-700 dark:text-gray-300">
                        入替
                    </button>
                </div>

                <div class="xl:col-span-2">
                    <label class="block text-xs font-medium text-gray-600 dark:text-gray-400 mb-1">終了時点</label>
                    <select wire:model.live="toSnapshot" class="block w-full rounded-md border-slate-300 bg-white dark:border-gray-700 dark:bg-gray-950 text-sm">
                        @foreach ($snapshots as $key => $label)
                            <option value="{{ $key }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="xl:col-span-1 flex items-end">
                    <button type="button" wire:click="search" class="w-full rounded-md bg-slate-800 px-3 py-2 text-sm font-medium text-white hover:bg-slate-700 dark:bg-slate-200 dark:text-gray-950 dark:hover:bg-white">
                        検索
                    </button>
                </div>
            </div>
        </div>

        @if (! $hasResult)
            <div class="rounded-lg border border-dashed border-slate-400 bg-slate-50 dark:border-gray-700 dark:bg-gray-900 p-8 text-center text-sm text-gray-600 dark:text-gray-400">
                @if ($this->hasSearched)
                    該当する商品またはスナップショット在庫がありません
                @else
                    条件を指定して検索してください
                @endif
            </div>
        @else
            <div class="grid grid-cols-1 gap-3 md:grid-cols-4">
                <div class="rounded-lg border border-slate-300 dark:border-gray-800 bg-white dark:bg-gray-900 p-4 shadow-sm">
                    <div class="text-xs text-gray-500 dark:text-gray-400">現在庫数</div>
                    <div class="mt-1 flex items-end justify-between gap-3">
                        <div class="text-2xl font-semibold text-gray-900 dark:text-white">{{ number_format($totals['to_current']) }}</div>
                        <div class="text-sm font-semibold {{ $diffClass($totals['diff_current']) }}">{{ $signed($totals['diff_current']) }}</div>
                    </div>
                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ number_format($totals['from_current']) }} → {{ number_format($totals['to_current']) }}</div>
                </div>

                <div class="rounded-lg border border-slate-300 dark:border-gray-800 bg-white dark:bg-gray-900 p-4 shadow-sm">
                    <div class="text-xs text-gray-500 dark:text-gray-400">引当済み数</div>
                    <div class="mt-1 flex items-end justify-between gap-3">
                        <div class="text-2xl font-semibold text-gray-900 dark:text-white">{{ number_format($totals['to_reserved']) }}</div>
                        <div class="text-sm font-semibold {{ $diffClass($totals['diff_reserved']) }}">{{ $signed($totals['diff_reserved']) }}</div>
                    </div>
                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ number_format($totals['from_reserved']) }} → {{ number_format($totals['to_reserved']) }}</div>
                </div>

                <div class="rounded-lg border border-slate-300 dark:border-gray-800 bg-white dark:bg-gray-900 p-4 shadow-sm">
                    <div class="text-xs text-gray-500 dark:text-gray-400">利用可能数</div>
                    <div class="mt-1 flex items-end justify-between gap-3">
                        <div class="text-2xl font-semibold text-gray-900 dark:text-white">{{ number_format($totals['to_available']) }}</div>
                        <div class="text-sm font-semibold {{ $diffClass($totals['diff_available']) }}">{{ $signed($totals['diff_available']) }}</div>
                    </div>
                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ number_format($totals['from_available']) }} → {{ number_format($totals['to_available']) }}</div>
                </div>

                <div class="rounded-lg border border-slate-300 dark:border-gray-800 bg-white dark:bg-gray-900 p-4 shadow-sm">
                    <div class="text-xs text-gray-500 dark:text-gray-400">入荷予定数</div>
                    <div class="mt-1 flex items-end justify-between gap-3">
                        <div class="text-2xl font-semibold text-gray-900 dark:text-white">{{ number_format($totals['to_incoming']) }}</div>
                        <div class="text-sm font-semibold {{ $diffClass($totals['diff_incoming']) }}">{{ $signed($totals['diff_incoming']) }}</div>
                    </div>
                    <div class="mt-1 text-xs text-gray-500 dark:text-gray-400">{{ number_format($totals['from_incoming']) }} → {{ number_format($totals['to_incoming']) }}</div>
                </div>
            </div>

            <div class="grid grid-cols-1 gap-4 2xl:grid-cols-5">
                <div class="2xl:col-span-3 rounded-lg border border-slate-300 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-sm">
                    <div class="border-b border-slate-300 bg-slate-50 dark:border-gray-800 dark:bg-gray-950 px-4 py-3">
                        <h2 class="text-sm font-semibold text-gray-900 dark:text-white">倉庫別差分</h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 dark:bg-gray-950">
                                <tr class="text-xs text-gray-500 dark:text-gray-400">
                                    <th class="px-3 py-2 text-left font-medium">倉庫CD</th>
                                    <th class="px-3 py-2 text-left font-medium">倉庫名</th>
                                    <th class="px-3 py-2 text-right font-medium">現在庫</th>
                                    <th class="px-3 py-2 text-right font-medium">差分</th>
                                    <th class="px-3 py-2 text-right font-medium">引当済み</th>
                                    <th class="px-3 py-2 text-right font-medium">差分</th>
                                    <th class="px-3 py-2 text-right font-medium">利用可能</th>
                                    <th class="px-3 py-2 text-right font-medium">差分</th>
                                    <th class="px-3 py-2 text-right font-medium">入荷予定</th>
                                    <th class="px-3 py-2 text-right font-medium">差分</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @forelse ($summaryRows as $row)
                                    <tr>
                                        <td class="px-3 py-2 font-mono text-gray-800 dark:text-gray-200">{{ $row['warehouse_code'] }}</td>
                                        <td class="px-3 py-2 text-gray-900 dark:text-white">{{ $row['warehouse_name'] }}</td>
                                        <td class="px-3 py-2 text-right">{{ number_format($row['from_current']) }} → {{ number_format($row['to_current']) }}</td>
                                        <td class="px-3 py-2 text-right font-semibold {{ $diffClass($row['diff_current']) }}">{{ $signed($row['diff_current']) }}</td>
                                        <td class="px-3 py-2 text-right">{{ number_format($row['from_reserved']) }} → {{ number_format($row['to_reserved']) }}</td>
                                        <td class="px-3 py-2 text-right font-semibold {{ $diffClass($row['diff_reserved']) }}">{{ $signed($row['diff_reserved']) }}</td>
                                        <td class="px-3 py-2 text-right">{{ number_format($row['from_available']) }} → {{ number_format($row['to_available']) }}</td>
                                        <td class="px-3 py-2 text-right font-semibold {{ $diffClass($row['diff_available']) }}">{{ $signed($row['diff_available']) }}</td>
                                        <td class="px-3 py-2 text-right">{{ number_format($row['from_incoming']) }} → {{ number_format($row['to_incoming']) }}</td>
                                        <td class="px-3 py-2 text-right font-semibold {{ $diffClass($row['diff_incoming']) }}">{{ $signed($row['diff_incoming']) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="10" class="px-3 py-8 text-center text-gray-500 dark:text-gray-400">該当するサマリーはありません</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="2xl:col-span-2 rounded-lg border border-slate-300 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-sm">
                    <div class="border-b border-slate-300 bg-slate-50 dark:border-gray-800 dark:bg-gray-950 px-4 py-3">
                        <h2 class="text-sm font-semibold text-gray-900 dark:text-white">時系列推移</h2>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 dark:bg-gray-950">
                                <tr class="text-xs text-gray-500 dark:text-gray-400">
                                    <th class="px-3 py-2 text-left font-medium">時点</th>
                                    <th class="px-3 py-2 text-right font-medium">現在庫</th>
                                    <th class="px-3 py-2 text-right font-medium">前回差</th>
                                    <th class="px-3 py-2 text-right font-medium">引当</th>
                                    <th class="px-3 py-2 text-right font-medium">利用可能</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                                @forelse ($timeline as $row)
                                    <tr>
                                        <td class="px-3 py-2 text-gray-900 dark:text-white">{{ $row['label'] }}</td>
                                        <td class="px-3 py-2 text-right">{{ number_format($row['current_quantity']) }}</td>
                                        <td class="px-3 py-2 text-right font-semibold {{ $row['diff_from_previous'] === null ? 'text-gray-400' : $diffClass($row['diff_from_previous']) }}">
                                            {{ $row['diff_from_previous'] === null ? '-' : $signed($row['diff_from_previous']) }}
                                        </td>
                                        <td class="px-3 py-2 text-right">{{ number_format($row['reserved_quantity']) }}</td>
                                        <td class="px-3 py-2 text-right">{{ number_format($row['available_quantity']) }}</td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="px-3 py-8 text-center text-gray-500 dark:text-gray-400">該当する推移はありません</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="rounded-lg border border-slate-300 dark:border-gray-800 bg-white dark:bg-gray-900 shadow-sm">
                <div class="border-b border-slate-300 bg-slate-50 dark:border-gray-800 dark:bg-gray-950 px-4 py-3">
                    <h2 class="text-sm font-semibold text-gray-900 dark:text-white">ロット・ロケーション差分</h2>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full text-sm">
                        <thead class="bg-gray-50 dark:bg-gray-950">
                            <tr class="text-xs text-gray-500 dark:text-gray-400">
                                <th class="px-3 py-2 text-left font-medium">倉庫CD</th>
                                <th class="px-3 py-2 text-left font-medium">倉庫名</th>
                                <th class="px-3 py-2 text-left font-medium">ロケーション</th>
                                <th class="px-3 py-2 text-left font-medium">賞味期限</th>
                                <th class="px-3 py-2 text-right font-medium">ロットID</th>
                                <th class="px-3 py-2 text-right font-medium">現在庫</th>
                                <th class="px-3 py-2 text-right font-medium">差分</th>
                                <th class="px-3 py-2 text-right font-medium">引当済み</th>
                                <th class="px-3 py-2 text-right font-medium">差分</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100 dark:divide-gray-800">
                            @forelse ($lotDifferences as $row)
                                <tr>
                                    <td class="px-3 py-2 font-mono text-gray-800 dark:text-gray-200">{{ $row['warehouse_code'] }}</td>
                                    <td class="px-3 py-2 text-gray-900 dark:text-white">{{ $row['warehouse_name'] }}</td>
                                    <td class="px-3 py-2">{{ $row['location_name'] ?: '-' }}</td>
                                    <td class="px-3 py-2">{{ $row['expiration_date'] ?: '-' }}</td>
                                    <td class="px-3 py-2 text-right">{{ $row['lot_id'] ?? '-' }}</td>
                                    <td class="px-3 py-2 text-right">{{ number_format($row['from_current']) }} → {{ number_format($row['to_current']) }}</td>
                                    <td class="px-3 py-2 text-right font-semibold {{ $diffClass($row['diff_current']) }}">{{ $signed($row['diff_current']) }}</td>
                                    <td class="px-3 py-2 text-right">{{ number_format($row['from_reserved']) }} → {{ number_format($row['to_reserved']) }}</td>
                                    <td class="px-3 py-2 text-right font-semibold {{ $diffClass($row['diff_reserved']) }}">{{ $signed($row['diff_reserved']) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="9" class="px-3 py-8 text-center text-gray-500 dark:text-gray-400">ロット単位の差分はありません</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($lotDifferences->count() >= 200)
                    <div class="border-t border-gray-200 dark:border-gray-800 px-4 py-2 text-xs text-amber-700 dark:text-amber-400">
                        表示は差分の大きい順に200件までです
                    </div>
                @endif
            </div>
        @endif
    </div>
</x-filament-panels::page>
