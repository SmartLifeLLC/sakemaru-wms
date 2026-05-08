<x-filament-widgets::widget>
    <x-filament::section>
        @php
            $num = fn ($key) => number_format((float) ($summary[$key] ?? 0));
            $money = fn ($key) => '¥' . number_format((float) ($summary[$key] ?? 0));
            $completionRate = (($summary['picking_task_count'] ?? 0) > 0)
                ? round((($summary['completed_task_count'] ?? 0) / max(1, $summary['picking_task_count'])) * 100, 1)
                : 0;
        @endphp

        <div class="space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <div class="text-sm font-bold text-gray-900 dark:text-gray-100">当日の出荷情報</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400">
                        対象日: {{ $filterDate }}
                        @if ($lastCalculatedAt)
                            / 最終集計: {{ $lastCalculatedAt }}
                        @endif
                    </div>
                </div>
                <button
                    type="button"
                    wire:click="loadStats(true)"
                    class="inline-flex h-8 items-center rounded-md border border-gray-300 bg-white px-3 text-xs font-bold text-gray-700 shadow-sm hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                >
                    再集計
                </button>
            </div>

            @if ($loadError)
                <div class="rounded-md border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700 dark:border-red-800 dark:bg-red-950 dark:text-red-300">
                    統計の取得に失敗しました: {{ $loadError }}
                </div>
            @endif

            <div class="grid gap-2 md:grid-cols-2 xl:grid-cols-6">
                <div class="rounded-md border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
                    <div class="text-xs font-bold text-gray-500">受注伝票状況</div>
                    <div class="mt-1 text-2xl font-black text-gray-950 dark:text-white">{{ $num('total_slip_count') }}</div>
                    <div class="mt-2 flex gap-2 text-xs font-bold">
                        <span class="text-green-700 dark:text-green-300">出荷済 {{ $num('shipped_slip_count') }}</span>
                        <span class="text-amber-700 dark:text-amber-300">出荷前 {{ $num('unshipped_slip_count') }}</span>
                    </div>
                </div>

                <div class="rounded-md border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
                    <div class="text-xs font-bold text-gray-500">売上金額合計（税抜）</div>
                    <div class="mt-1 text-2xl font-black text-gray-950 dark:text-white">{{ $money('total_amount_ex') }}</div>
                    <div class="mt-2 text-xs font-bold text-gray-500">税込 {{ $money('total_amount_in') }}</div>
                </div>

                <div class="rounded-md border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
                    <div class="text-xs font-bold text-gray-500">出荷商品項目数</div>
                    <div class="mt-1 text-2xl font-black text-gray-950 dark:text-white">{{ $num('picking_item_count') }}</div>
                    <div class="mt-2 text-xs font-bold text-gray-500">ユニーク商品 {{ $num('unique_item_count') }}</div>
                </div>

                <div class="rounded-md border border-red-200 bg-red-50 p-3 dark:border-red-800 dark:bg-red-950">
                    <div class="text-xs font-bold text-red-700 dark:text-red-300">引当欠品数</div>
                    <div class="mt-1 text-2xl font-black text-red-800 dark:text-red-200">{{ $num('allocation_shortage_qty') }}</div>
                    <div class="mt-2 text-xs font-bold text-red-600 dark:text-red-300">欠品あり伝票 {{ $num('shortage_slip_count') }}</div>
                </div>

                <div class="rounded-md border border-orange-200 bg-orange-50 p-3 dark:border-orange-800 dark:bg-orange-950">
                    <div class="text-xs font-bold text-orange-700 dark:text-orange-300">欠品確定数</div>
                    <div class="mt-1 text-2xl font-black text-orange-800 dark:text-orange-200">{{ $num('confirmed_shortage_qty') }}</div>
                    <div class="mt-2 text-xs font-bold text-orange-600 dark:text-orange-300">確定明細 {{ $num('confirmed_shortage_count') }}</div>
                </div>

                <div class="rounded-md border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
                    <div class="text-xs font-bold text-gray-500">ユニーク顧客数</div>
                    <div class="mt-1 text-2xl font-black text-gray-950 dark:text-white">{{ $num('unique_buyer_count') }}</div>
                    <div class="mt-2 text-xs font-bold text-gray-500">作業完了率 {{ number_format($completionRate, 1) }}%</div>
                </div>
            </div>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
