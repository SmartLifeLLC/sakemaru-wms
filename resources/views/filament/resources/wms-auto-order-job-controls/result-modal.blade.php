<div class="space-y-4">
    {{-- サマリーカード（常に表示） --}}
    <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
        <div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
            <div class="text-2xl font-bold text-primary-600 dark:text-primary-400">{{ number_format($result['summary']['total_candidates'] ?? 0) }}</div>
            <div class="text-xs text-gray-500 dark:text-gray-400">発注候補総数</div>
        </div>
        <div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
            <div class="text-2xl font-bold text-info-600 dark:text-info-400">{{ number_format($result['summary']['internal_candidates'] ?? 0) }}</div>
            <div class="text-xs text-gray-500 dark:text-gray-400">移動候補</div>
        </div>
        <div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
            <div class="text-2xl font-bold text-warning-600 dark:text-warning-400">{{ number_format($result['summary']['external_candidates'] ?? 0) }}</div>
            <div class="text-xs text-gray-500 dark:text-gray-400">外部発注候補</div>
        </div>
        <div class="p-3 bg-gray-50 dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
            <div class="text-2xl font-bold text-gray-900 dark:text-white">{{ number_format($result['summary']['total_quantity'] ?? 0) }}</div>
            <div class="text-xs text-gray-500 dark:text-gray-400">合計数量</div>
        </div>
    </div>

    {{-- タブセクション --}}
    <div x-data="{ activeTab: 'warehouse' }">
        {{-- タブナビゲーション --}}
        <div class="border-b border-gray-200 dark:border-gray-700">
            <nav class="flex gap-4" aria-label="Tabs">
                @if (!empty($result['by_warehouse']))
                    <button
                        type="button"
                        @click="activeTab = 'warehouse'"
                        :class="activeTab === 'warehouse' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400'"
                        class="py-2 px-1 border-b-2 font-medium text-sm whitespace-nowrap"
                    >
                        倉庫別 <span class="text-xs text-gray-400">({{ count($result['by_warehouse']) }})</span>
                    </button>
                @endif
                @if (!empty($result['by_contractor']))
                    <button
                        type="button"
                        @click="activeTab = 'contractor'"
                        :class="activeTab === 'contractor' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400'"
                        class="py-2 px-1 border-b-2 font-medium text-sm whitespace-nowrap"
                    >
                        発注先別 <span class="text-xs text-gray-400">({{ count($result['by_contractor']) }})</span>
                    </button>
                @endif
                @if (!empty($result['cross_summary']))
                    <button
                        type="button"
                        @click="activeTab = 'cross'"
                        :class="activeTab === 'cross' ? 'border-primary-500 text-primary-600 dark:text-primary-400' : 'border-transparent text-gray-500 hover:text-gray-700 dark:text-gray-400'"
                        class="py-2 px-1 border-b-2 font-medium text-sm whitespace-nowrap"
                    >
                        倉庫×発注先 <span class="text-xs text-gray-400">({{ count($result['cross_summary']) }})</span>
                    </button>
                @endif
            </nav>
        </div>

        {{-- タブコンテンツ --}}
        <div class="pt-3">
            {{-- 倉庫別タブ --}}
            @if (!empty($result['by_warehouse']))
                <div x-show="activeTab === 'warehouse'" x-cloak>
                    <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700 max-h-[50vh] overflow-y-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-800 sticky top-0">
                                <tr>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">コード</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">倉庫名</th>
                                    <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">件数</th>
                                    <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">数量</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-700">
                                @foreach ($result['by_warehouse'] as $item)
                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-800">
                                        <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400 font-mono">{{ $item['warehouse_code'] ?? '-' }}</td>
                                        <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900 dark:text-white">{{ $item['warehouse_name'] }}</td>
                                        <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900 dark:text-white text-right font-medium">{{ number_format($item['count']) }}</td>
                                        <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900 dark:text-white text-right">{{ number_format($item['quantity']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="bg-gray-100 dark:bg-gray-800 sticky bottom-0">
                                <tr>
                                    <td colspan="2" class="px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300">合計</td>
                                    <td class="px-4 py-2 text-sm font-bold text-gray-900 dark:text-white text-right">{{ number_format(collect($result['by_warehouse'])->sum('count')) }}</td>
                                    <td class="px-4 py-2 text-sm font-bold text-gray-900 dark:text-white text-right">{{ number_format(collect($result['by_warehouse'])->sum('quantity')) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            @endif

            {{-- 発注先別タブ --}}
            @if (!empty($result['by_contractor']))
                <div x-show="activeTab === 'contractor'" x-cloak>
                    <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700 max-h-[50vh] overflow-y-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-800 sticky top-0">
                                <tr>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">コード</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">発注先名</th>
                                    <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">件数</th>
                                    <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">数量</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-700">
                                @foreach ($result['by_contractor'] as $item)
                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-800">
                                        <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400 font-mono">{{ $item['contractor_code'] ?? '-' }}</td>
                                        <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900 dark:text-white">{{ $item['contractor_name'] }}</td>
                                        <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900 dark:text-white text-right font-medium">{{ number_format($item['count']) }}</td>
                                        <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900 dark:text-white text-right">{{ number_format($item['quantity']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="bg-gray-100 dark:bg-gray-800 sticky bottom-0">
                                <tr>
                                    <td colspan="2" class="px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300">合計</td>
                                    <td class="px-4 py-2 text-sm font-bold text-gray-900 dark:text-white text-right">{{ number_format(collect($result['by_contractor'])->sum('count')) }}</td>
                                    <td class="px-4 py-2 text-sm font-bold text-gray-900 dark:text-white text-right">{{ number_format(collect($result['by_contractor'])->sum('quantity')) }}</td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>
            @endif

            {{-- 倉庫×発注先タブ --}}
            @if (!empty($result['cross_summary']))
                <div x-show="activeTab === 'cross'" x-cloak>
                    <div class="overflow-hidden rounded-lg border border-gray-200 dark:border-gray-700 max-h-[50vh] overflow-y-auto">
                        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                            <thead class="bg-gray-50 dark:bg-gray-800 sticky top-0">
                                <tr>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">倉庫</th>
                                    <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">発注先</th>
                                    <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">件数</th>
                                    <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">数量</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-700">
                                @foreach ($result['cross_summary'] as $item)
                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-800">
                                        <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                            <span class="text-gray-400 mr-1">{{ $item['warehouse_code'] ?? '' }}</span>
                                            {{ $item['warehouse_name'] }}
                                        </td>
                                        <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900 dark:text-white">
                                            <span class="text-gray-400 mr-1">{{ $item['contractor_code'] ?? '' }}</span>
                                            {{ $item['contractor_name'] }}
                                        </td>
                                        <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900 dark:text-white text-right font-medium">{{ number_format($item['count']) }}</td>
                                        <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900 dark:text-white text-right">{{ number_format($item['quantity']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                            <tfoot class="bg-gray-100 dark:bg-gray-800 sticky bottom-0">
                                <tr>
                                    <td colspan="2" class="px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-300">合計</td>
                                    <td class="px-4 py-2 text-sm font-bold text-gray-900 dark:text-white text-right">{{ number_format(collect($result['cross_summary'])->sum('count')) }}</td>
                                    <td class="px-4 py-2 text-sm font-bold text-gray-900 dark:text-white text-right">{{ number_format(collect($result['cross_summary'])->sum('quantity')) }}</td>
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
        <div class="text-xs text-gray-400 dark:text-gray-500 text-right">
            生成日時: {{ \Carbon\Carbon::parse($result['generated_at'])->format('Y-m-d H:i:s') }}
        </div>
    @endif
</div>
