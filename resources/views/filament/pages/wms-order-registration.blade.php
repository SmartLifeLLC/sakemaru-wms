<x-filament-panels::page>
    @if (! empty($completionResult))
        <div class="space-y-3">
            <div class="rounded-md border border-emerald-200 bg-white p-4 shadow-sm dark:border-emerald-800 dark:bg-gray-900">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="text-lg font-bold text-emerald-700 dark:text-emerald-300">発注確定完了</div>
                        <div class="mt-1 text-sm text-slate-600 dark:text-gray-300">
                            実行CD:
                            <span class="font-mono font-semibold text-slate-900 dark:text-white">{{ $completionResult['batch_code'] ?? '-' }}</span>
                        </div>
                    </div>
                    <button
                        type="button"
                        wire:click="startNewRegistration"
                        class="inline-flex items-center gap-1 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-gray-800"
                    >
                        <x-heroicon-o-plus class="h-4 w-4" />
                        続けて発注登録
                    </button>
                </div>

                <div class="mt-4 grid grid-cols-1 gap-2 sm:grid-cols-4">
                    <div class="rounded-md bg-slate-50 px-3 py-2 dark:bg-gray-800">
                        <div class="text-xs font-semibold text-slate-500 dark:text-gray-400">発注明細</div>
                        <div class="font-mono text-xl font-bold text-slate-900 dark:text-white">{{ number_format((int) ($completionResult['candidate_count'] ?? 0)) }}</div>
                    </div>
                    <div class="rounded-md bg-slate-50 px-3 py-2 dark:bg-gray-800">
                        <div class="text-xs font-semibold text-slate-500 dark:text-gray-400">入荷予定</div>
                        <div class="font-mono text-xl font-bold text-slate-900 dark:text-white">{{ number_format((int) ($completionResult['incoming_schedule_count'] ?? 0)) }}</div>
                    </div>
                    <div class="rounded-md bg-slate-50 px-3 py-2 dark:bg-gray-800">
                        <div class="text-xs font-semibold text-slate-500 dark:text-gray-400">生成PDF</div>
                        <div class="font-mono text-xl font-bold text-slate-900 dark:text-white">{{ number_format((int) ($completionResult['file_count'] ?? 0)) }}</div>
                    </div>
                    <div class="rounded-md bg-slate-50 px-3 py-2 dark:bg-gray-800">
                        <div class="text-xs font-semibold text-slate-500 dark:text-gray-400">倉庫</div>
                        <div class="truncate text-sm font-semibold text-slate-900 dark:text-white">{{ $this->selectedWarehouseLabel() }}</div>
                    </div>
                </div>
            </div>

            <div class="overflow-hidden rounded-md border border-slate-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
                <div class="flex items-center justify-between border-b border-slate-200 px-3 py-2 dark:border-gray-700">
                    <div class="text-sm font-semibold text-slate-800 dark:text-gray-100">今回生成したFAX/PDF</div>
                    <div class="text-xs text-slate-500 dark:text-gray-400">仕入先・入荷予定日ごと</div>
                </div>

                <div class="overflow-auto">
                    <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-gray-700">
                        <thead class="bg-slate-50 text-xs text-slate-600 dark:bg-gray-800 dark:text-gray-300">
                            <tr>
                                <th class="whitespace-nowrap px-3 py-2 text-left">種別</th>
                                <th class="whitespace-nowrap px-3 py-2 text-left">仕入先</th>
                                <th class="whitespace-nowrap px-3 py-2 text-center">入荷予定日</th>
                                <th class="whitespace-nowrap px-3 py-2 text-right">明細数</th>
                                <th class="whitespace-nowrap px-3 py-2 text-right">総バラ数</th>
                                <th class="whitespace-nowrap px-3 py-2 text-left">状態</th>
                                <th class="whitespace-nowrap px-3 py-2 text-center">操作</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 dark:divide-gray-800">
                            @forelse (($completionResult['files'] ?? []) as $file)
                                <tr class="odd:bg-[#f5f9ff] even:bg-white dark:odd:bg-[#1e2a3b] dark:even:bg-gray-900">
                                    <td class="whitespace-nowrap px-3 py-2">
                                        <span class="rounded bg-blue-100 px-2 py-1 text-xs font-semibold text-blue-700 dark:bg-blue-900/40 dark:text-blue-200">{{ $file['order_channel_label'] ?? 'FAX発注' }}</span>
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2 font-semibold text-slate-800 dark:text-gray-100">{{ $file['supplier_name'] ?? $file['contractor_name'] ?? '-' }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-center font-mono">{{ $file['expected_arrival_date'] ?? '-' }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right font-mono">{{ number_format((int) ($file['order_count'] ?? 0)) }}</td>
                                    <td class="whitespace-nowrap px-3 py-2 text-right font-mono">{{ number_format((int) ($file['total_quantity'] ?? 0)) }}</td>
                                    <td class="px-3 py-2">
                                        @if (filled($file['fax_error'] ?? null))
                                            <span class="text-xs font-semibold text-danger-700 dark:text-danger-300">{{ $file['fax_error'] }}</span>
                                        @elseif (filled($file['fax_file_path'] ?? null))
                                            <span class="text-xs font-semibold text-emerald-700 dark:text-emerald-300">生成済み</span>
                                        @else
                                            <span class="text-xs font-semibold text-amber-700 dark:text-amber-300">PDF未生成</span>
                                        @endif
                                    </td>
                                    <td class="whitespace-nowrap px-3 py-2 text-center">
                                        <button
                                            type="button"
                                            wire:click="downloadCompletionFax({{ (int) ($file['id'] ?? 0) }})"
                                            @disabled(blank($file['fax_file_path'] ?? null))
                                            class="inline-flex items-center gap-1 rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm hover:bg-emerald-500 disabled:cursor-not-allowed disabled:bg-slate-300 dark:disabled:bg-gray-700"
                                        >
                                            <x-heroicon-o-arrow-down-tray class="h-4 w-4" />
                                            ダウンロード
                                        </button>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="px-3 py-12 text-center text-sm text-slate-400 dark:text-gray-500">生成されたPDFはありません</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @else
    <div class="space-y-3">
        <div class="rounded-md border border-slate-200 bg-white p-3 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="flex flex-wrap items-end gap-3">
                <div class="min-w-72 flex-1">
                    <div class="mb-1 text-xs font-semibold text-slate-600 dark:text-gray-300">倉庫</div>
                    <div class="rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-xl font-black text-emerald-700 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300">
                        {{ $this->selectedWarehouseLabel() }}
                    </div>
                </div>

                <div class="ml-auto flex flex-wrap items-end justify-end gap-2">
                    <button
                        type="button"
                        wire:click="openCandidateSearchModal"
                        class="inline-flex items-center gap-1 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-gray-800"
                    >
                        <x-heroicon-o-magnifying-glass class="h-4 w-4" />
                        発注候補検索
                    </button>
                    <button
                        type="button"
                        wire:click="openSalesHistoryModal"
                        class="inline-flex items-center gap-1 rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm hover:bg-slate-50 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-gray-800"
                    >
                        <x-heroicon-o-chart-bar class="h-4 w-4" />
                        販売履歴から生成
                    </button>
                    <button
                        type="button"
                        wire:click="confirmOrders"
                        wire:loading.attr="disabled"
                        @disabled(empty($lines))
                        class="inline-flex items-center gap-1 rounded-md bg-danger-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-danger-500 disabled:cursor-not-allowed disabled:bg-slate-300 dark:disabled:bg-gray-700"
                    >
                        <x-heroicon-o-check-circle class="h-4 w-4" />
                        確定
                    </button>
                </div>
            </div>

            <div class="mt-2 text-right text-xs font-semibold text-amber-700 dark:text-amber-300">明細 {{ count($lines) }}件</div>
        </div>

        <div class="overflow-hidden rounded-md border border-slate-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="flex items-center justify-between border-b border-slate-200 px-3 py-2 dark:border-gray-700">
                <div class="text-sm font-semibold text-slate-800 dark:text-gray-100">登録リスト</div>
                <div class="text-xs text-slate-500 dark:text-gray-400">確定時に発注確定済みデータ・入荷予定・PDFを作成</div>
            </div>

            <div class="max-h-[calc(100vh-280px)] overflow-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-gray-700">
                    <thead class="sticky top-0 z-10 bg-slate-50 text-xs text-slate-600 dark:bg-gray-800 dark:text-gray-300">
                        <tr>
                            <th class="whitespace-nowrap px-3 py-2 text-center">発注区分</th>
                            <th class="whitespace-nowrap px-3 py-2 text-center">入荷予定</th>
                            <th class="whitespace-nowrap px-3 py-2 text-left">発注先CD</th>
                            <th class="whitespace-nowrap px-3 py-2 text-left">発注先名</th>
                            <th class="whitespace-nowrap px-3 py-2 text-left">商品CD</th>
                            <th class="min-w-72 px-3 py-2 text-left">商品名</th>
                            <th class="whitespace-nowrap px-3 py-2 text-right">入数</th>
                            <th class="whitespace-nowrap px-3 py-2 text-center">単位</th>
                            <th class="whitespace-nowrap px-3 py-2 text-right">発注数</th>
                            <th class="whitespace-nowrap px-3 py-2 text-right">総バラ数</th>
                            <th class="whitespace-nowrap px-3 py-2 text-center">生成元</th>
                            <th class="sticky right-0 bg-slate-50 px-3 py-2 text-center dark:bg-gray-800">操作</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-gray-800">
                        @forelse ($lines as $index => $line)
                            @php
                                $orderQuantity = (int) ($line['order_quantity'] ?? 0);
                                $capacityCase = max(1, (int) ($line['capacity_case'] ?? 1));
                                $totalPieces = ($line['quantity_type'] ?? '') === \App\Enums\QuantityType::CASE->value
                                    ? $orderQuantity * $capacityCase
                                    : $orderQuantity;
                                $isEosAvailable = (bool) ($line['is_eos_available'] ?? false);
                            @endphp
                            <tr wire:key="registration-line-{{ $index }}" class="odd:bg-[#f5f9ff] even:bg-white dark:odd:bg-[#1e2a3b] dark:even:bg-gray-900">
                                <td class="whitespace-nowrap px-3 py-2 text-center">
                                    @if ($isEosAvailable)
                                        <select
                                            wire:model.live="lines.{{ $index }}.order_channel"
                                            class="w-28 rounded-md border-slate-300 text-xs font-semibold dark:border-gray-600 dark:bg-gray-900"
                                        >
                                            <option value="{{ \App\Enums\AutoOrder\OrderChannel::EOS->value }}">EOS発注</option>
                                            <option value="{{ \App\Enums\AutoOrder\OrderChannel::FAX->value }}">FAX発注</option>
                                        </select>
                                    @else
                                        <span class="inline-flex items-center rounded bg-amber-100 px-2 py-1 text-xs font-semibold text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">FAX固定</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 text-center">
                                    <input
                                        type="date"
                                        min="{{ now()->toDateString() }}"
                                        wire:model.live="lines.{{ $index }}.expected_arrival_date"
                                        class="w-36 rounded-md border-slate-300 text-sm dark:border-gray-600 dark:bg-gray-900"
                                    >
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 font-mono text-xs">{{ $line['contractor_code'] }}</td>
                                <td class="whitespace-nowrap px-3 py-2">{{ $line['contractor_name'] }}</td>
                                <td class="whitespace-nowrap px-3 py-2 font-mono text-xs">{{ $line['item_code'] }}</td>
                                <td class="px-3 py-2">{{ $line['item_name'] }}</td>
                                <td class="whitespace-nowrap px-3 py-2 text-right">{{ number_format($capacityCase) }}</td>
                                <td class="whitespace-nowrap px-3 py-2 text-center">{{ $line['quantity_type_label'] }}</td>
                                <td class="whitespace-nowrap px-3 py-2 text-right">
                                    <input
                                        type="number"
                                        min="1"
                                        wire:model.live="lines.{{ $index }}.order_quantity"
                                        class="w-20 rounded-md border-slate-300 text-right text-sm dark:border-gray-600 dark:bg-gray-900"
                                    >
                                </td>
                                <td class="whitespace-nowrap px-3 py-2 text-right font-semibold">{{ number_format($totalPieces) }}</td>
                                <td class="whitespace-nowrap px-3 py-2 text-center">
                                    <span class="rounded bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-700 dark:bg-gray-800 dark:text-gray-300">{{ $line['entry_source_label'] }}</span>
                                </td>
                                <td class="sticky right-0 bg-inherit px-3 py-2 text-center">
                                    <button
                                        type="button"
                                        wire:click="removeLine({{ $index }})"
                                        class="inline-flex items-center rounded-md border border-danger-200 bg-white px-2 py-1 text-xs font-semibold text-danger-700 hover:bg-danger-50 dark:border-danger-700 dark:bg-gray-900 dark:text-danger-300"
                                    >
                                        <x-heroicon-o-trash class="h-4 w-4" />
                                    </button>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="12" class="px-3 py-12 text-center text-sm text-slate-400 dark:text-gray-500">登録リストは空です</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    @if ($showCandidateSearchModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-3">
            <div class="flex max-h-[96vh] flex-col overflow-hidden rounded-lg bg-white shadow-xl dark:bg-gray-900" style="width: min(1680px, 98vw);">
                <div class="flex items-center justify-between bg-slate-800 px-4 py-3 text-white">
                    <div class="flex items-center gap-2 text-sm font-semibold">
                        <x-heroicon-o-magnifying-glass class="h-5 w-5" />
                        発注候補検索
                    </div>
                    <button type="button" wire:click="closeCandidateSearchModal" class="rounded p-1 text-white hover:bg-white/10">
                        <x-heroicon-o-x-mark class="h-5 w-5" />
                    </button>
                </div>

                <div class="overflow-auto p-4">
                    @include('filament.components.order-registration-candidate-create-items', ['lw' => $this])
                </div>

                <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-4 py-3 dark:border-gray-700 dark:bg-gray-800">
                    <button type="button" wire:click="closeCandidateSearchModal" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">追加せず閉じる</button>
                    <button type="button" wire:click="addOrderCandidateItems" class="rounded-md bg-danger-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-danger-500">
                        追加する
                    </button>
                </div>
            </div>
        </div>
    @endif

    @if ($showSalesHistoryModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-4">
            <div class="flex max-h-[92vh] flex-col overflow-hidden rounded-lg bg-white shadow-xl dark:bg-gray-900" style="width: min(1280px, 96vw);">
                <div class="flex items-center justify-between bg-slate-800 px-4 py-3 text-white">
                    <div class="flex items-center gap-2 text-sm font-semibold">
                        <x-heroicon-o-chart-bar class="h-5 w-5" />
                        外部発注候補生成（{{ $this->selectedWarehouseLabel() }}）
                    </div>
                    <button type="button" wire:click="closeSalesHistoryModal" class="rounded p-1 text-white hover:bg-white/10">
                        <x-heroicon-o-x-mark class="h-5 w-5" />
                    </button>
                </div>

                <div class="overflow-auto p-4">
                    <div class="mb-3">
                        <label class="mb-1 block text-xs font-semibold text-slate-600 dark:text-gray-300">発注区分</label>
                        <div class="grid max-w-xl grid-cols-2 gap-1 rounded-md bg-slate-100 p-1 dark:bg-gray-800">
                            @foreach ([\App\Enums\AutoOrder\OrderChannel::EOS, \App\Enums\AutoOrder\OrderChannel::FAX] as $channel)
                                <button
                                    type="button"
                                    wire:click="setSalesGenerationOrderChannel('{{ $channel->value }}')"
                                    @class([
                                        'inline-flex items-center justify-center gap-1 rounded px-3 py-2 text-sm font-semibold transition',
                                        'bg-white text-blue-700 shadow-sm dark:bg-gray-950 dark:text-blue-300' => $salesGenerationOrderChannel === $channel->value,
                                        'text-slate-600 hover:bg-white/70 dark:text-gray-300 dark:hover:bg-gray-700' => $salesGenerationOrderChannel !== $channel->value,
                                    ])
                                >
                                    @if ($channel === \App\Enums\AutoOrder\OrderChannel::EOS)
                                        <x-heroicon-o-cloud-arrow-up class="h-4 w-4" />
                                    @else
                                        <x-heroicon-o-document-text class="h-4 w-4" />
                                    @endif
                                    {{ $channel->label() }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3 external-order-generation-layout">
                        <div class="space-y-3 lg:col-span-1">
                            <div class="grid grid-cols-2 gap-2">
                                <label class="block">
                                    <span class="mb-1 block text-xs font-semibold text-slate-600 dark:text-gray-300">販売実績 開始日</span>
                                    <input type="date" wire:model.live="salesStartDate" class="w-full rounded-md border-slate-300 text-sm dark:border-gray-600 dark:bg-gray-900">
                                </label>
                                <label class="block">
                                    <span class="mb-1 block text-xs font-semibold text-slate-600 dark:text-gray-300">販売実績 終了日</span>
                                    <input type="date" wire:model.live="salesEndDate" class="w-full rounded-md border-slate-300 text-sm dark:border-gray-600 dark:bg-gray-900">
                                </label>
                            </div>

                            @include('filament.components.order-registration-category-selection', [
                                'lw' => $this,
                                'categoriesProperty' => 'externalOrderCategory2Data',
                                'fallbackMethod' => 'getExternalOrderCategory2ForSalesBasedGeneration',
                                'selectedProperty' => 'selectedExternalOrderCategory2Ids',
                                'label' => '中分類',
                                'compactListHeight' => true,
                                'layout' => 'externalOrderGeneration',
                            ])
                        </div>

                        <div class="lg:col-span-2">
                            @include('filament.components.order-registration-contractor-selection', [
                                'lw' => $this,
                                'grouped' => true,
                                'primaryContractorsProperty' => 'externalOrderJxContractorsData',
                                'primaryFallbackMethod' => 'getExternalOrderJxContractorsForSalesBasedGeneration',
                                'secondaryContractorsProperty' => 'externalOrderOtherContractorsData',
                                'secondaryFallbackMethod' => 'getExternalOrderOtherContractorsForSalesBasedGeneration',
                                'selectedProperty' => 'selectedExternalOrderContractorIds',
                                'primaryLabel' => 'EOS仕入先',
                                'secondaryLabel' => 'FAX仕入先',
                                'compactListHeight' => true,
                                'layout' => 'externalOrderGeneration',
                            ])
                        </div>
                    </div>
                </div>

                <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-4 py-3 dark:border-gray-700 dark:bg-gray-800">
                    <button type="button" wire:click="closeSalesHistoryModal" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">表示せず閉じる</button>
                    <button type="button" wire:click="calculateSalesBasedExternalOrderPreview" class="rounded-md bg-danger-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-danger-500">
                        候補表示
                    </button>
                </div>
            </div>
        </div>
    @endif

    @if ($showSalesBasedExternalOrderPreviewModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center bg-slate-950/50 p-3">
            <div class="flex max-h-[96vh] flex-col overflow-hidden rounded-lg bg-white shadow-xl dark:bg-gray-900" style="width: min(1680px, 98vw);">
                <div class="flex items-center justify-between bg-slate-800 px-4 py-3 text-white">
                    <div class="flex items-center gap-2 text-sm font-semibold">
                        <x-heroicon-o-chart-bar class="h-5 w-5" />
                        外部発注候補リスト
                    </div>
                    <button
                        type="button"
                        x-data
                        x-on:click="if (confirm('外部発注候補リストを閉じますか？入力中の内容は破棄されます。')) { $wire.closeSalesBasedExternalOrderPreviewModal() }"
                        class="rounded p-1 text-white hover:bg-white/10"
                    >
                        <x-heroicon-o-x-mark class="h-5 w-5" />
                    </button>
                </div>

                <div class="overflow-auto p-4">
                    @include('filament.components.order-registration-sales-preview-edit', ['lw' => $this])
                </div>

                <div class="flex justify-end gap-2 border-t border-slate-200 bg-slate-50 px-4 py-3 dark:border-gray-700 dark:bg-gray-800">
                    <button
                        type="button"
                        x-data
                        x-on:click="if (confirm('外部発注候補リストを閉じますか？入力中の内容は破棄されます。')) { $wire.closeSalesBasedExternalOrderPreviewModal() }"
                        class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200"
                    >
                        閉じる
                    </button>
                    <button type="button" wire:click="addSalesBasedExternalOrderPreviewRowsToRegistration" class="rounded-md bg-danger-600 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-danger-500">
                        登録リストに追加
                    </button>
                </div>
            </div>
        </div>
    @endif
    @endif
</x-filament-panels::page>
