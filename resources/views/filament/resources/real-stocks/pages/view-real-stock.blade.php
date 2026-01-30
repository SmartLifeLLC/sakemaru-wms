<x-filament-panels::page>
    @php
        $item = $this->record->item;
        $warehouse = $this->record->warehouse;
        $incoming = $this->getTodayIncoming();
        $outgoing = $this->getTodayOutgoing();
        $summary = $this->getStockSummary();
        $lots = $this->getLots();
    @endphp

    <div class="flex flex-col gap-4">
        {{-- Main Panel --}}
        <div class="bg-white dark:bg-gray-900 shadow border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
            {{-- Header: Item Basic Info --}}
            <div class="border-b border-gray-200 dark:border-gray-700 p-4 bg-gray-50 dark:bg-gray-800">
                <div class="flex items-start gap-4">
                    <div class="flex-grow min-w-0">
                        <div class="flex items-center gap-3 mb-2 flex-wrap">
                            @if($item?->item_category1)
                                <span class="px-2.5 py-1 rounded text-sm font-bold text-white bg-gray-600 dark:bg-gray-500">
                                    {{ $item->item_category1->name }}
                                </span>
                            @endif
                            <span class="font-mono text-base text-primary-700 dark:text-primary-400 font-bold bg-primary-50 dark:bg-primary-900/30 px-2 py-0.5 rounded border border-primary-200 dark:border-primary-700">
                                {{ $item?->code ?? '-' }}
                            </span>
                            <span class="text-sm text-gray-500 dark:text-gray-400">|</span>
                            <span class="text-sm font-bold text-gray-700 dark:text-gray-300">
                                {{ $item?->manufacturer?->name ?? '-' }}
                            </span>
                        </div>
                        <h3 class="text-xl font-bold text-gray-900 dark:text-white leading-tight">
                            {{ $item?->name ?? '-' }}
                        </h3>
                        <div class="mt-3 flex flex-wrap gap-x-6 gap-y-1 text-sm text-gray-600 dark:text-gray-400 bg-white dark:bg-gray-800 p-2 rounded border border-gray-200 dark:border-gray-700 inline-block">
                            @if($item?->item_category2)
                                <div class="flex gap-1">
                                    <span class="text-gray-400 dark:text-gray-500">中分類:</span>
                                    <span class="font-bold">{{ $item->item_category2->name }}</span>
                                </div>
                            @endif
                            @if($item?->item_category3)
                                <div class="flex gap-1 border-l border-gray-200 dark:border-gray-700 pl-3">
                                    <span class="text-gray-400 dark:text-gray-500">小分類:</span>
                                    <span class="font-bold">{{ $item->item_category3->name }}</span>
                                </div>
                            @endif
                            @if($item?->capacity_case)
                                <div class="flex gap-1 border-l border-gray-200 dark:border-gray-700 pl-3">
                                    <span class="text-gray-400 dark:text-gray-500">入数:</span>
                                    <span class="font-bold">{{ $item->capacity_case }}</span>
                                </div>
                            @endif
                            @if($item?->getAnotherCode(\App\Enums\EItemSearchCodeType::JAN, \App\Enums\QuantityType::CASE))
                                <div class="flex gap-1 font-mono border-l border-gray-200 dark:border-gray-700 pl-3">
                                    <span class="text-gray-400 dark:text-gray-500">ケースJAN:</span>
                                    {{ $item->getAnotherCode(\App\Enums\EItemSearchCodeType::JAN, \App\Enums\QuantityType::CASE) }}
                                </div>
                            @endif
                            @if($item?->getAnotherCode(\App\Enums\EItemSearchCodeType::JAN, \App\Enums\QuantityType::PIECE))
                                <div class="flex gap-1 font-mono border-l border-gray-200 dark:border-gray-700 pl-3">
                                    <span class="text-gray-400 dark:text-gray-500">バラJAN:</span>
                                    {{ $item->getAnotherCode(\App\Enums\EItemSearchCodeType::JAN, \App\Enums\QuantityType::PIECE) }}
                                </div>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            {{-- Middle: 3-Column Grid --}}
            <div class="grid grid-cols-1 md:grid-cols-3 divide-y md:divide-y-0 md:divide-x divide-gray-200 dark:divide-gray-700">
                {{-- 1. Today Incoming/Outgoing --}}
                <div class="bg-white dark:bg-gray-900 flex flex-col">
                    <div class="bg-gray-200 dark:bg-gray-800 text-gray-700 dark:text-gray-300 font-bold px-3 py-1.5 border-b border-gray-300 dark:border-gray-700 flex items-center text-xs">
                        <x-heroicon-o-truck class="w-4 h-4 mr-1.5 text-gray-500 dark:text-gray-400" />
                        本日入出荷
                    </div>
                    <div class="p-3 flex-1 flex flex-col justify-between">
                        {{-- Today Incoming --}}
                        <div class="bg-blue-50 dark:bg-blue-900/30 rounded border border-blue-100 dark:border-blue-800 p-2 flex-1 flex flex-col justify-center">
                            <div class="text-xs font-bold text-blue-700 dark:text-blue-400 mb-2">
                                <x-heroicon-o-arrow-down class="w-3 h-3 inline mr-1" />
                                本日仕入数(バラ)
                            </div>
                            <div class="grid grid-cols-3 divide-x divide-blue-200 dark:divide-blue-700 text-center">
                                <div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400 mb-1">仕入入荷</div>
                                    <div class="text-xl font-bold font-mono text-gray-800 dark:text-white">{{ number_format($incoming['purchase_incoming']) }}</div>
                                </div>
                                <div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400 mb-1">直送</div>
                                    <div class="text-xl font-bold font-mono text-gray-800 dark:text-white">{{ number_format($incoming['direct_incoming']) }}</div>
                                </div>
                                <div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400 mb-1">移動入荷</div>
                                    <div class="text-xl font-bold font-mono text-gray-800 dark:text-white">{{ number_format($incoming['transfer_incoming']) }}</div>
                                </div>
                            </div>
                        </div>
                        {{-- Today Outgoing --}}
                        <div class="bg-orange-50 dark:bg-orange-900/30 rounded border border-orange-100 dark:border-orange-800 p-2 flex-1 flex flex-col justify-center mt-2">
                            <div class="text-xs font-bold text-orange-700 dark:text-orange-400 mb-2">
                                <x-heroicon-o-arrow-up class="w-3 h-3 inline mr-1" />
                                本日出荷予定(バラ)
                            </div>
                            <div class="grid grid-cols-3 divide-x divide-orange-200 dark:divide-orange-700 text-center">
                                <div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400 mb-1">出荷予定</div>
                                    <div class="text-xl font-bold font-mono text-gray-800 dark:text-white">{{ number_format($outgoing['total_reserved']) }}</div>
                                </div>
                                <div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400 mb-1">出荷済</div>
                                    <div class="text-xl font-bold font-mono text-gray-800 dark:text-white">{{ number_format($outgoing['total_shipped']) }}</div>
                                </div>
                                <div>
                                    <div class="text-xs text-gray-500 dark:text-gray-400 mb-1">移動出荷</div>
                                    <div class="text-xl font-bold font-mono text-gray-800 dark:text-white">{{ number_format($outgoing['transfer_outgoing']) }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- 2. Stock Summary --}}
                <div class="bg-white dark:bg-gray-900 flex flex-col">
                    <div class="bg-gray-200 dark:bg-gray-800 text-gray-700 dark:text-gray-300 font-bold px-3 py-1.5 border-b border-gray-300 dark:border-gray-700 flex items-center justify-between text-xs">
                        <span>
                            <x-heroicon-o-chart-pie class="w-4 h-4 inline mr-1.5 text-gray-500 dark:text-gray-400" />
                            在庫状況
                        </span>
                        <span class="font-normal text-gray-600 dark:text-gray-400">
                            {{ $warehouse?->code }} {{ $warehouse?->name }}
                        </span>
                    </div>
                    <div class="p-3 flex-1 flex flex-col justify-between">
                        {{-- Current | Available | Reserved --}}
                        <div class="grid grid-cols-3 divide-x divide-gray-200 dark:divide-gray-700 bg-gray-50 dark:bg-gray-800 rounded border border-gray-200 dark:border-gray-700 flex-1">
                            <div class="p-3 text-center flex flex-col justify-center">
                                <div class="text-xs text-gray-500 dark:text-gray-400 mb-1">現在在庫</div>
                                <div class="text-2xl font-bold text-gray-800 dark:text-white font-mono">{{ number_format($summary['current_quantity']) }}</div>
                            </div>
                            <div class="p-3 text-center bg-primary-50 dark:bg-primary-900/30 flex flex-col justify-center">
                                <div class="text-xs text-primary-600 dark:text-primary-400 font-bold mb-1">引当可能</div>
                                <div class="text-2xl font-bold text-primary-700 dark:text-primary-400 font-mono">{{ number_format($summary['available_quantity']) }}</div>
                            </div>
                            <div class="p-3 text-center bg-orange-50/50 dark:bg-orange-900/20 flex flex-col justify-center">
                                <div class="text-xs text-orange-600 dark:text-orange-400 mb-1">出荷待ち</div>
                                <div class="text-2xl font-bold text-orange-700 dark:text-orange-400 font-mono">{{ number_format($summary['reserved_quantity']) }}</div>
                            </div>
                        </div>
                    </div>
                </div>

                {{-- 3. Warehouse Info --}}
                <div class="bg-white dark:bg-gray-900 flex flex-col">
                    <div class="bg-gray-200 dark:bg-gray-800 text-gray-700 dark:text-gray-300 font-bold px-3 py-1.5 border-b border-gray-300 dark:border-gray-700 flex items-center text-xs">
                        <x-heroicon-o-adjustments-horizontal class="w-4 h-4 mr-1.5 text-gray-500 dark:text-gray-400" />
                        在庫情報
                    </div>
                    <div class="p-3 bg-blue-50/30 dark:bg-blue-900/10 flex-1 flex flex-col justify-between">
                        <div class="space-y-3 flex-1 flex flex-col justify-center">
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="text-sm font-medium text-gray-500 dark:text-gray-400">在庫ID</label>
                                    <div class="font-mono text-lg font-bold text-gray-900 dark:text-white">{{ $this->record->id }}</div>
                                </div>
                                <div>
                                    <label class="text-sm font-medium text-gray-500 dark:text-gray-400">ロット数</label>
                                    <div class="font-mono text-lg font-bold text-gray-900 dark:text-white">{{ $lots['active']->count() }}</div>
                                </div>
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="text-sm font-medium text-gray-500 dark:text-gray-400">登録日時</label>
                                    <div class="font-mono text-sm text-gray-700 dark:text-gray-300">{{ $this->record->created_at?->format('Y-m-d H:i') }}</div>
                                </div>
                                <div>
                                    <label class="text-sm font-medium text-gray-500 dark:text-gray-400">更新日時</label>
                                    <div class="font-mono text-sm text-gray-700 dark:text-gray-300">{{ $this->record->updated_at?->format('Y-m-d H:i') }}</div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Bottom: Lot List Table --}}
            <div class="flex-1 flex flex-col min-h-0 border-t border-gray-300 dark:border-gray-700">
                <div class="overflow-auto flex-1">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 table-fixed">
                        <thead class="bg-gray-700 dark:bg-gray-800 text-white sticky top-0 z-10">
                            <tr>
                                <th class="py-2.5 pl-3 text-left text-sm font-semibold tracking-wide w-24">ロットID</th>
                                <th class="py-2.5 px-2 text-left text-sm font-semibold tracking-wide w-20 border-l border-gray-600">フロア</th>
                                <th class="py-2.5 px-2 text-left text-sm font-semibold tracking-wide w-28">ロケーション</th>
                                <th class="py-2.5 px-2 text-left text-sm font-semibold tracking-wide w-28 border-l border-gray-600">賞味期限</th>
                                <th class="py-2.5 px-2 text-left text-sm font-semibold tracking-wide w-32 border-l border-gray-600">仕入</th>
                                <th class="py-2.5 px-2 text-left text-sm font-semibold tracking-wide w-28 border-l border-gray-600">仕入日</th>
                                <th class="py-2.5 px-2 text-right text-sm font-semibold tracking-wide w-24 border-l border-gray-600">仕入価格</th>
                                <th class="py-2.5 px-2 text-left text-sm font-semibold tracking-wide border-l border-gray-600 min-w-[200px]">引当得意先制限</th>
                                <th class="py-2.5 px-2 text-right text-sm font-semibold tracking-wide w-20 border-l border-gray-600">現在数</th>
                                <th class="py-2.5 px-2 text-right text-sm font-semibold tracking-wide w-20">引当可能</th>
                                <th class="py-2.5 px-2 text-right text-sm font-semibold tracking-wide w-20 bg-orange-900/30 text-orange-100">出荷待ち</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700 bg-white dark:bg-gray-900">
                            @forelse($lots['active'] as $lot)
                                <tr class="hover:bg-blue-50 dark:hover:bg-blue-900/20 transition-colors group {{ $loop->even ? 'bg-gray-50/30 dark:bg-gray-800/30' : '' }}">
                                    <td class="py-2.5 pl-3 text-sm font-bold text-gray-700 dark:text-gray-300 font-mono truncate">{{ $lot->id }}</td>
                                    <td class="py-2.5 px-2 text-sm text-gray-600 dark:text-gray-400 border-l border-gray-100 dark:border-gray-800 group-hover:border-blue-100 dark:group-hover:border-blue-900 truncate">{{ $lot->floor?->name ?? '-' }}</td>
                                    <td class="py-2.5 px-2 text-sm text-gray-600 dark:text-gray-400 font-mono truncate">{{ $lot->location?->code ?? '-' }}</td>
                                    <td class="py-2.5 px-2 text-sm text-gray-600 dark:text-gray-400 font-mono truncate border-l border-gray-100 dark:border-gray-800 group-hover:border-blue-100 dark:group-hover:border-blue-900">{{ $lot->expiration_date?->format('Y-m-d') ?? '-' }}</td>
                                    <td class="py-2.5 px-2 text-sm text-gray-500 dark:text-gray-500 border-l border-gray-100 dark:border-gray-800 group-hover:border-blue-100 dark:group-hover:border-blue-900 truncate">{{ $lot->purchase?->code ?? '-' }}</td>
                                    <td class="py-2.5 px-2 text-sm text-gray-500 dark:text-gray-500 border-l border-gray-100 dark:border-gray-800 group-hover:border-blue-100 dark:group-hover:border-blue-900 font-mono truncate">{{ $lot->created_at?->format('Y-m-d') ?? '-' }}</td>
                                    <td class="py-2.5 px-2 text-sm text-gray-500 dark:text-gray-500 border-l border-gray-100 dark:border-gray-800 group-hover:border-blue-100 dark:group-hover:border-blue-900 font-mono text-right truncate">{{ $lot->price ? '¥'.number_format($lot->price) : '-' }}</td>
                                    <td class="py-2.5 px-2 border-l border-gray-100 dark:border-gray-800 group-hover:border-blue-100 dark:group-hover:border-blue-900">
                                        @if($lot->buyerRestrictions->count() > 0)
                                            <div class="flex flex-col">
                                                <span class="inline-flex w-fit items-center rounded bg-red-50 dark:bg-red-900/30 px-2 py-1 text-sm font-medium text-red-700 dark:text-red-400 ring-1 ring-inset ring-red-600/20 dark:ring-red-500/30 mb-0.5">
                                                    制限あり({{ $lot->buyerRestrictions->count() }})
                                                </span>
                                                <span class="text-xs text-gray-400 dark:text-gray-500 font-mono truncate" title="{{ $lot->buyerRestrictions->pluck('buyer.name')->join(', ') }}">
                                                    {{ $lot->buyerRestrictions->pluck('buyer.code')->join(', ') }}
                                                </span>
                                            </div>
                                        @else
                                            <span class="text-sm text-gray-400 dark:text-gray-500">-</span>
                                        @endif
                                    </td>
                                    <td class="py-2.5 px-2 text-base text-right text-gray-500 dark:text-gray-400 font-mono border-l border-gray-100 dark:border-gray-800 group-hover:border-blue-100 dark:group-hover:border-blue-900">{{ number_format($lot->current_quantity) }}</td>
                                    <td class="py-2.5 px-2 text-base text-right font-bold text-primary-600 dark:text-primary-400 bg-primary-50/20 dark:bg-primary-900/20 font-mono">{{ number_format($lot->available_quantity) }}</td>
                                    <td class="py-2.5 px-2 text-base text-right font-bold text-orange-600 dark:text-orange-400 bg-orange-50/30 dark:bg-orange-900/20 font-mono">{{ number_format($lot->reserved_quantity) }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="11" class="py-8 text-center text-gray-500 dark:text-gray-400">
                                        アクティブなロットがありません
                                    </td>
                                </tr>
                            @endforelse

                            {{-- Depleted Lots --}}
                            @foreach($lots['depleted'] as $lot)
                                <tr class="hover:bg-blue-50 dark:hover:bg-blue-900/20 transition-colors group bg-gray-100 dark:bg-gray-800 text-gray-400 dark:text-gray-500">
                                    <td class="py-2.5 pl-3 text-sm font-bold font-mono truncate">{{ $lot->id }}</td>
                                    <td class="py-2.5 px-2 text-sm border-l border-gray-100 dark:border-gray-700 truncate">{{ $lot->floor?->name ?? '-' }}</td>
                                    <td class="py-2.5 px-2 text-sm font-mono truncate">{{ $lot->location?->code ?? '-' }}</td>
                                    <td class="py-2.5 px-2 text-sm font-mono truncate border-l border-gray-100 dark:border-gray-700">{{ $lot->expiration_date?->format('Y-m-d') ?? '-' }}</td>
                                    <td class="py-2.5 px-2 text-sm border-l border-gray-100 dark:border-gray-700 truncate">{{ $lot->purchase?->code ?? '-' }}</td>
                                    <td class="py-2.5 px-2 text-sm border-l border-gray-100 dark:border-gray-700 font-mono truncate">{{ $lot->created_at?->format('Y-m-d') ?? '-' }}</td>
                                    <td class="py-2.5 px-2 text-sm border-l border-gray-100 dark:border-gray-700 font-mono text-right truncate">{{ $lot->price ? '¥'.number_format($lot->price) : '-' }}</td>
                                    <td class="py-2.5 px-2 border-l border-gray-100 dark:border-gray-700">
                                        <span class="text-sm">-</span>
                                    </td>
                                    <td class="py-2.5 px-2 text-base text-right font-mono border-l border-gray-100 dark:border-gray-700">0</td>
                                    <td class="py-2.5 px-2 text-base text-right font-mono">0</td>
                                    <td class="py-2.5 px-2 text-base text-right font-mono">0</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="bg-gray-50 dark:bg-gray-800 border-t border-gray-300 dark:border-gray-700">
                            <tr>
                                <td colspan="8" class="py-2 px-3 text-sm font-bold text-right text-gray-600 dark:text-gray-400">合計:</td>
                                <td class="py-2 px-2 text-base text-right font-bold text-gray-800 dark:text-white font-mono">{{ number_format($summary['current_quantity']) }}</td>
                                <td class="py-2 px-2 text-base text-right font-bold text-primary-700 dark:text-primary-400 font-mono">{{ number_format($summary['available_quantity']) }}</td>
                                <td class="py-2 px-2 text-base text-right font-bold text-orange-700 dark:text-orange-400 font-mono bg-orange-50 dark:bg-orange-900/20">{{ number_format($summary['reserved_quantity']) }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
