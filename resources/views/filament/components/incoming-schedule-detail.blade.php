<div class="space-y-3 -mt-2">
    {{-- 上段: 商品ヘッダー --}}
    <div class="flex items-center gap-3 px-3 py-2 bg-slate-700 dark:bg-slate-800 rounded-lg">
        <span @class([
            'px-2 py-0.5 rounded text-xs font-bold shrink-0',
            'bg-blue-500/20 text-blue-300' => $orderSource === '発注',
            'bg-gray-500/20 text-gray-300' => $orderSource === '手動',
            'bg-amber-500/20 text-amber-300' => $orderSource === '移動',
            'bg-emerald-500/20 text-emerald-300' => $orderSource === '受信',
        ])>{{ $orderSource }}</span>
        <span class="text-white font-mono text-sm">{{ $itemCode }}</span>
        <span class="text-white font-medium text-sm truncate">{{ $itemName }}</span>
        @if($packaging && $packaging !== '-')
            <span class="text-slate-400 text-xs shrink-0">{{ $packaging }}</span>
        @endif
        @if($capacityText && $capacityText !== '-')
            <span class="text-slate-400 text-xs shrink-0">（{{ $capacityText }}）</span>
        @endif
    </div>

    {{-- 中段: 2カラム情報 --}}
    <div class="grid grid-cols-2 gap-3">
        {{-- 左: 基本情報 --}}
        <div class="bg-gray-50 dark:bg-white/5 rounded-lg p-3">
            <table class="w-full text-sm">
                <tbody class="divide-y divide-gray-200 dark:divide-white/10">
                    <tr>
                        <td class="py-1.5 text-gray-500 dark:text-gray-400 w-24">倉庫</td>
                        <td class="py-1.5 font-medium text-gray-900 dark:text-white">{{ $warehouseName }}</td>
                    </tr>
                    <tr>
                        <td class="py-1.5 text-gray-500 dark:text-gray-400">発注先</td>
                        <td class="py-1.5 font-medium text-gray-900 dark:text-white">{{ $contractorName }}</td>
                    </tr>
                    <tr>
                        <td class="py-1.5 text-gray-500 dark:text-gray-400">発注日</td>
                        <td class="py-1.5 text-gray-900 dark:text-white">{{ $orderDate }}</td>
                    </tr>
                    <tr>
                        <td class="py-1.5 text-gray-500 dark:text-gray-400">予定日</td>
                        <td class="py-1.5 font-bold text-primary-600 dark:text-primary-400">{{ $expectedArrivalDate }}</td>
                    </tr>
                    <tr>
                        <td class="py-1.5 text-gray-500 dark:text-gray-400">ロケーション</td>
                        <td class="py-1.5">
                            @if($locationText !== '-')
                                <span class="font-mono text-xs bg-gray-200 dark:bg-white/10 px-1.5 py-0.5 rounded">{{ $locationText }}</span>
                            @else
                                <span class="text-gray-400 text-xs">未設定</span>
                            @endif
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        {{-- 右: 数量・在庫 --}}
        <div class="space-y-2">
            {{-- 数量カード --}}
            <div class="grid grid-cols-4 gap-2">
                <div class="text-center bg-gray-50 dark:bg-white/5 rounded-lg py-2 px-1">
                    <div class="text-[10px] text-gray-500 dark:text-gray-400">発注数</div>
                    <div class="text-lg font-bold text-gray-900 dark:text-white">{{ number_format($expectedQuantity) }}</div>
                </div>
                <div class="text-center bg-gray-50 dark:bg-white/5 rounded-lg py-2 px-1">
                    <div class="text-[10px] text-gray-500 dark:text-gray-400">入荷済</div>
                    <div class="text-lg font-bold text-gray-900 dark:text-white">{{ number_format($receivedQuantity) }}</div>
                </div>
                <div class="text-center rounded-lg py-2 px-1 {{ $remainingQuantity > 0 ? 'bg-red-50 dark:bg-red-900/20' : 'bg-green-50 dark:bg-green-900/20' }}">
                    <div class="text-[10px] text-gray-500 dark:text-gray-400">残数</div>
                    <div class="text-lg font-bold {{ $remainingQuantity > 0 ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400' }}">{{ number_format($remainingQuantity) }}</div>
                </div>
                <div class="text-center bg-gray-50 dark:bg-white/5 rounded-lg py-2 px-1">
                    <div class="text-[10px] text-gray-500 dark:text-gray-400">状態</div>
                    <div class="mt-0.5">
                        <span class="px-1.5 py-0.5 rounded text-xs font-medium bg-{{ $statusColor }}-100 text-{{ $statusColor }}-700 dark:bg-{{ $statusColor }}-900/40 dark:text-{{ $statusColor }}-300">{{ $status }}</span>
                    </div>
                </div>
            </div>

            {{-- 在庫カード --}}
            <div class="grid grid-cols-2 gap-2">
                <div class="text-center bg-gray-50 dark:bg-white/5 rounded-lg py-2">
                    <div class="text-[10px] text-gray-500 dark:text-gray-400">現在庫</div>
                    <div class="text-base font-semibold text-gray-900 dark:text-white">{{ number_format($currentStock) }}</div>
                </div>
                <div class="text-center bg-blue-50 dark:bg-blue-900/20 rounded-lg py-2">
                    <div class="text-[10px] text-blue-600 dark:text-blue-400">有効在庫</div>
                    <div class="text-base font-semibold text-blue-700 dark:text-blue-300">{{ number_format($availableStock) }}</div>
                </div>
            </div>

            {{-- 計算情報（コンパクト） --}}
            @if($hasOrderCandidate && $hasCalculationLog)
            <div class="bg-slate-50 dark:bg-white/5 rounded-lg p-2 text-xs">
                <div class="flex items-center gap-2 text-gray-500 dark:text-gray-400 mb-1">
                    <span>計算情報</span>
                    <span class="text-[10px]">ID:{{ $orderCandidateId }} / {{ $batchCodeFormatted }}</span>
                </div>
                <div class="font-mono text-gray-700 dark:text-gray-300 mb-1">{{ $formula }}</div>
                <div class="flex gap-3 text-gray-600 dark:text-gray-400">
                    <span>有効在庫: {{ number_format($effectiveStock) }}</span>
                    <span>入荷予定: {{ number_format($incomingStock) }}</span>
                    <span>発注点: {{ number_format($safetyStock) }}</span>
                    <span class="text-red-600 dark:text-red-400">不足: {{ number_format($shortageQty) }}</span>
                    <span class="ml-auto font-bold text-primary-600 dark:text-primary-400">発注数: {{ number_format($orderQuantity) }}</span>
                </div>
            </div>
            @elseif(!$hasOrderCandidate)
            <div class="bg-gray-50 dark:bg-white/5 rounded-lg p-2 text-xs text-gray-400">
                手動登録または移動からの入荷予定（発注計算情報なし）
            </div>
            @endif
        </div>
    </div>
</div>
