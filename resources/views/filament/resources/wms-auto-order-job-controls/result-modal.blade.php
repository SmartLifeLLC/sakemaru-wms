<div class="space-y-4">
    @php
        $isSnapshot = ($record->process_name ?? null)?->value === 'STOCK_SNAPSHOT';
    @endphp

    @if ($isSnapshot)
        {{-- 在庫スナップショット結果 --}}
        <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
            <div class="p-3 bg-slate-50 dark:bg-gray-900 rounded-lg border border-slate-200 dark:border-gray-700">
                <div class="text-2xl font-bold text-primary-600 dark:text-primary-400">{{ number_format($record->processed_records ?? 0) }}</div>
                <div class="text-xs text-slate-500 dark:text-gray-400">スナップショット件数</div>
            </div>
            <div class="p-3 bg-slate-50 dark:bg-gray-900 rounded-lg border border-slate-200 dark:border-gray-700">
                <div class="text-2xl font-bold text-slate-800 dark:text-white">{{ $record->started_at?->format('Y-m-d H:i:s') ?? '-' }}</div>
                <div class="text-xs text-slate-500 dark:text-gray-400">開始日時</div>
            </div>
            <div class="p-3 bg-slate-50 dark:bg-gray-900 rounded-lg border border-slate-200 dark:border-gray-700">
                <div class="text-2xl font-bold text-slate-800 dark:text-white">{{ $record->finished_at?->format('Y-m-d H:i:s') ?? '-' }}</div>
                <div class="text-xs text-slate-500 dark:text-gray-400">終了日時</div>
            </div>
        </div>

        {{-- スナップショット一覧へのリンク --}}
        <div class="mt-4 p-4 bg-blue-50 dark:bg-blue-900/20 rounded-lg border border-blue-200 dark:border-blue-800">
            <div class="flex items-center justify-between">
                <div>
                    <div class="font-medium text-blue-800 dark:text-blue-200">在庫スナップショット一覧</div>
                    <div class="text-sm text-blue-600 dark:text-blue-400">このジョブで生成されたスナップショットを確認できます</div>
                </div>
                <a
                    href="{{ route('filament.admin.resources.wms-item-stock-snapshots.index', ['tableFilters' => ['job_control_id' => ['value' => $record->id]]]) }}"
                    class="inline-flex items-center gap-2 px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-sm font-medium rounded-lg transition-colors"
                >
                    <x-heroicon-o-arrow-top-right-on-square class="w-4 h-4" />
                    スナップショットを見る
                </a>
            </div>
        </div>
    @else
        {{-- 発注・移動候補生成結果 --}}
        {{-- サマリーカード --}}
        <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
            <div class="p-3 bg-slate-50 dark:bg-gray-900 rounded-lg border border-slate-200 dark:border-gray-700">
                <div class="text-2xl font-bold text-primary-600 dark:text-primary-400">{{ number_format($result['summary']['total_candidates'] ?? 0) }}</div>
                <div class="text-xs text-slate-500 dark:text-gray-400">発注候補総数</div>
            </div>
            <div class="p-3 bg-slate-50 dark:bg-gray-900 rounded-lg border border-slate-200 dark:border-gray-700">
                <div class="text-2xl font-bold text-info-600 dark:text-info-400">{{ number_format($result['summary']['internal_candidates'] ?? 0) }}</div>
                <div class="text-xs text-slate-500 dark:text-gray-400">移動候補</div>
            </div>
            <div class="p-3 bg-slate-50 dark:bg-gray-900 rounded-lg border border-slate-200 dark:border-gray-700">
                <div class="text-2xl font-bold text-warning-600 dark:text-warning-400">{{ number_format($result['summary']['external_candidates'] ?? 0) }}</div>
                <div class="text-xs text-slate-500 dark:text-gray-400">外部発注候補</div>
            </div>
            <div class="p-3 bg-slate-50 dark:bg-gray-900 rounded-lg border border-slate-200 dark:border-gray-700">
                <div class="text-2xl font-bold text-slate-800 dark:text-white">{{ number_format($result['summary']['total_quantity'] ?? 0) }}</div>
                <div class="text-xs text-slate-500 dark:text-gray-400">合計数量</div>
            </div>
        </div>

        {{-- 倉庫別内訳セクション --}}
        <div x-data="{ activeTab: 'warehouse' }">
            {{-- タブナビゲーション --}}
            <div class="border-b border-slate-200 dark:border-gray-700">
                <nav class="flex gap-4 px-1" aria-label="Tabs">
                    @if (!empty($result['by_warehouse']))
                        <button
                            type="button"
                            @click="activeTab = 'warehouse'"
                            class="px-4 py-2 text-sm font-medium transition-colors"
                            :class="activeTab === 'warehouse'
                                ? 'text-blue-600 border-b-2 border-blue-600 bg-blue-50/30 dark:text-blue-400 dark:bg-blue-900/20'
                                : 'text-slate-500 dark:text-gray-400 hover:text-slate-700 dark:hover:text-gray-200'"
                        >
                            倉庫別 <span class="text-xs text-slate-400 dark:text-gray-500">({{ count($result['by_warehouse']) }})</span>
                        </button>
                    @endif
                    @if (!empty($result['by_contractor']))
                        <button
                            type="button"
                            @click="activeTab = 'contractor'"
                            class="px-4 py-2 text-sm font-medium transition-colors"
                            :class="activeTab === 'contractor'
                                ? 'text-blue-600 border-b-2 border-blue-600 bg-blue-50/30 dark:text-blue-400 dark:bg-blue-900/20'
                                : 'text-slate-500 dark:text-gray-400 hover:text-slate-700 dark:hover:text-gray-200'"
                        >
                            発注先別 <span class="text-xs text-slate-400 dark:text-gray-500">({{ count($result['by_contractor']) }})</span>
                        </button>
                    @endif
                    @if (!empty($result['cross_summary']))
                        <button
                            type="button"
                            @click="activeTab = 'cross'"
                            class="px-4 py-2 text-sm font-medium transition-colors"
                            :class="activeTab === 'cross'
                                ? 'text-blue-600 border-b-2 border-blue-600 bg-blue-50/30 dark:text-blue-400 dark:bg-blue-900/20'
                                : 'text-slate-500 dark:text-gray-400 hover:text-slate-700 dark:hover:text-gray-200'"
                        >
                            倉庫×発注先 <span class="text-xs text-slate-400 dark:text-gray-500">({{ count($result['cross_summary']) }})</span>
                        </button>
                    @endif
                </nav>
            </div>

            {{-- タブコンテンツ --}}
            <div class="pt-3">
                {{-- 倉庫別タブ --}}
                @if (!empty($result['by_warehouse']))
                    <div x-show="activeTab === 'warehouse'" x-cloak>
                        <div class="rounded-lg border border-slate-200 dark:border-gray-700" style="max-height: 200px; overflow-y: auto;">
                            <table class="min-w-full divide-y divide-slate-200 dark:divide-gray-700">
                                <thead class="bg-slate-50 dark:bg-gray-900 sticky top-0">
                                    <tr>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-slate-600 dark:text-gray-400">コード</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-slate-600 dark:text-gray-400">倉庫名</th>
                                        <th class="px-4 py-2 text-right text-xs font-medium text-slate-600 dark:text-gray-400">件数</th>
                                        <th class="px-4 py-2 text-right text-xs font-medium text-slate-600 dark:text-gray-400">数量</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white dark:bg-gray-800 divide-y divide-slate-200 dark:divide-gray-700">
                                    @foreach ($result['by_warehouse'] as $item)
                                        <tr class="hover:bg-slate-50 dark:hover:bg-gray-700 transition-colors">
                                            <td class="px-4 py-2 whitespace-nowrap text-sm text-slate-500 dark:text-gray-400 font-mono">{{ $item['warehouse_code'] ?? '-' }}</td>
                                            <td class="px-4 py-2 whitespace-nowrap text-sm text-slate-800 dark:text-white">{{ $item['warehouse_name'] }}</td>
                                            <td class="px-4 py-2 whitespace-nowrap text-sm text-slate-800 dark:text-white text-right font-medium">{{ number_format($item['count']) }}</td>
                                            <td class="px-4 py-2 whitespace-nowrap text-sm text-slate-800 dark:text-white text-right">{{ number_format($item['quantity']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot class="bg-slate-50 dark:bg-gray-900">
                                    <tr>
                                        <td colspan="2" class="px-4 py-2 text-sm font-medium text-slate-700 dark:text-gray-300">合計</td>
                                        <td class="px-4 py-2 text-sm font-bold text-slate-800 dark:text-white text-right">{{ number_format(collect($result['by_warehouse'])->sum('count')) }}</td>
                                        <td class="px-4 py-2 text-sm font-bold text-slate-800 dark:text-white text-right">{{ number_format(collect($result['by_warehouse'])->sum('quantity')) }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                @endif

                {{-- 発注先別タブ --}}
                @if (!empty($result['by_contractor']))
                    <div x-show="activeTab === 'contractor'" x-cloak>
                        <div class="rounded-lg border border-slate-200 dark:border-gray-700" style="max-height: 200px; overflow-y: auto;">
                            <table class="min-w-full divide-y divide-slate-200 dark:divide-gray-700">
                                <thead class="bg-slate-50 dark:bg-gray-900 sticky top-0">
                                    <tr>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-slate-600 dark:text-gray-400">コード</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-slate-600 dark:text-gray-400">発注先名</th>
                                        <th class="px-4 py-2 text-right text-xs font-medium text-slate-600 dark:text-gray-400">件数</th>
                                        <th class="px-4 py-2 text-right text-xs font-medium text-slate-600 dark:text-gray-400">数量</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white dark:bg-gray-800 divide-y divide-slate-200 dark:divide-gray-700">
                                    @foreach ($result['by_contractor'] as $item)
                                        <tr class="hover:bg-slate-50 dark:hover:bg-gray-700 transition-colors">
                                            <td class="px-4 py-2 whitespace-nowrap text-sm text-slate-500 dark:text-gray-400 font-mono">{{ $item['contractor_code'] ?? '-' }}</td>
                                            <td class="px-4 py-2 whitespace-nowrap text-sm text-slate-800 dark:text-white">{{ $item['contractor_name'] }}</td>
                                            <td class="px-4 py-2 whitespace-nowrap text-sm text-slate-800 dark:text-white text-right font-medium">{{ number_format($item['count']) }}</td>
                                            <td class="px-4 py-2 whitespace-nowrap text-sm text-slate-800 dark:text-white text-right">{{ number_format($item['quantity']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot class="bg-slate-50 dark:bg-gray-900">
                                    <tr>
                                        <td colspan="2" class="px-4 py-2 text-sm font-medium text-slate-700 dark:text-gray-300">合計</td>
                                        <td class="px-4 py-2 text-sm font-bold text-slate-800 dark:text-white text-right">{{ number_format(collect($result['by_contractor'])->sum('count')) }}</td>
                                        <td class="px-4 py-2 text-sm font-bold text-slate-800 dark:text-white text-right">{{ number_format(collect($result['by_contractor'])->sum('quantity')) }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                @endif

                {{-- 倉庫×発注先タブ --}}
                @if (!empty($result['cross_summary']))
                    <div x-show="activeTab === 'cross'" x-cloak>
                        <div class="rounded-lg border border-slate-200 dark:border-gray-700" style="max-height: 200px; overflow-y: auto;">
                            <table class="min-w-full divide-y divide-slate-200 dark:divide-gray-700">
                                <thead class="bg-slate-50 dark:bg-gray-900 sticky top-0">
                                    <tr>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-slate-600 dark:text-gray-400">倉庫</th>
                                        <th class="px-4 py-2 text-left text-xs font-medium text-slate-600 dark:text-gray-400">発注先</th>
                                        <th class="px-4 py-2 text-right text-xs font-medium text-slate-600 dark:text-gray-400">件数</th>
                                        <th class="px-4 py-2 text-right text-xs font-medium text-slate-600 dark:text-gray-400">数量</th>
                                    </tr>
                                </thead>
                                <tbody class="bg-white dark:bg-gray-800 divide-y divide-slate-200 dark:divide-gray-700">
                                    @foreach ($result['cross_summary'] as $item)
                                        <tr class="hover:bg-slate-50 dark:hover:bg-gray-700 transition-colors">
                                            <td class="px-4 py-2 whitespace-nowrap text-sm text-slate-800 dark:text-white">
                                                <span class="text-slate-400 dark:text-gray-500 mr-1">{{ $item['warehouse_code'] ?? '' }}</span>
                                                {{ $item['warehouse_name'] }}
                                            </td>
                                            <td class="px-4 py-2 whitespace-nowrap text-sm text-slate-800 dark:text-white">
                                                <span class="text-slate-400 dark:text-gray-500 mr-1">{{ $item['contractor_code'] ?? '' }}</span>
                                                {{ $item['contractor_name'] }}
                                            </td>
                                            <td class="px-4 py-2 whitespace-nowrap text-sm text-slate-800 dark:text-white text-right font-medium">{{ number_format($item['count']) }}</td>
                                            <td class="px-4 py-2 whitespace-nowrap text-sm text-slate-800 dark:text-white text-right">{{ number_format($item['quantity']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                                <tfoot class="bg-slate-50 dark:bg-gray-900">
                                    <tr>
                                        <td colspan="2" class="px-4 py-2 text-sm font-medium text-slate-700 dark:text-gray-300">合計</td>
                                        <td class="px-4 py-2 text-sm font-bold text-slate-800 dark:text-white text-right">{{ number_format(collect($result['cross_summary'])->sum('count')) }}</td>
                                        <td class="px-4 py-2 text-sm font-bold text-slate-800 dark:text-white text-right">{{ number_format(collect($result['cross_summary'])->sum('quantity')) }}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                @endif
            </div>
        </div>

        {{-- 生成日時 --}}
        @if (!empty($result['generated_at']))
            <div class="text-xs text-slate-400 dark:text-gray-500 text-right">
                生成日時: {{ \Carbon\Carbon::parse($result['generated_at'])->format('Y-m-d H:i:s') }}
            </div>
        @endif
    @endif
</div>
