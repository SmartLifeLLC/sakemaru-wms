<div class="space-y-4">
    <div class="grid grid-cols-2 gap-3 text-sm md:grid-cols-4">
        <div class="rounded border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-800">
            <div class="text-xs text-gray-500 dark:text-gray-400">入荷倉庫</div>
            <div class="mt-1 font-semibold text-gray-900 dark:text-gray-100">{{ $summary['warehouse'] }}</div>
        </div>
        <div class="rounded border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-800">
            <div class="text-xs text-gray-500 dark:text-gray-400">発注先</div>
            <div class="mt-1 font-semibold text-gray-900 dark:text-gray-100">{{ $summary['contractor'] }}</div>
        </div>
        <div class="rounded border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-800">
            <div class="text-xs text-gray-500 dark:text-gray-400">明細 / 商品 / 伝票</div>
            <div class="mt-1 font-semibold text-gray-900 dark:text-gray-100">
                {{ number_format($summary['detail_count']) }} / {{ number_format($summary['item_count']) }} / {{ number_format($summary['slip_count']) }}
            </div>
        </div>
        <div class="rounded border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-800">
            <div class="text-xs text-gray-500 dark:text-gray-400">仕入連携</div>
            <div class="mt-1 font-semibold text-gray-900 dark:text-gray-100">{{ $summary['transmission_state'] }}</div>
        </div>
        <div class="rounded border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-800">
            <div class="text-xs text-gray-500 dark:text-gray-400">予定日</div>
            <div class="mt-1 font-semibold text-gray-900 dark:text-gray-100">{{ $summary['expected_period'] }}</div>
        </div>
        <div class="rounded border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-800">
            <div class="text-xs text-gray-500 dark:text-gray-400">入荷日</div>
            <div class="mt-1 font-semibold text-gray-900 dark:text-gray-100">{{ $summary['actual_period'] }}</div>
        </div>
        <div class="rounded border border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-800 md:col-span-2">
            <div class="text-xs text-gray-500 dark:text-gray-400">最終データ連携時刻</div>
            <div class="mt-1 font-semibold text-gray-900 dark:text-gray-100">{{ $summary['last_confirmed_at'] }}</div>
        </div>
    </div>

    <div class="overflow-x-auto">
        <table class="w-full min-w-[1120px] border-collapse border border-gray-300 text-xs dark:border-gray-600">
            <thead>
                <tr class="bg-gray-50 dark:bg-gray-800">
                    <th class="border border-gray-300 px-2 py-1 text-center font-semibold text-gray-900 dark:border-gray-600 dark:text-gray-100">ID</th>
                    <th class="border border-gray-300 px-2 py-1 text-center font-semibold text-gray-900 dark:border-gray-600 dark:text-gray-100">伝票番号</th>
                    <th class="border border-gray-300 px-2 py-1 text-center font-semibold text-gray-900 dark:border-gray-600 dark:text-gray-100">区分</th>
                    <th class="border border-gray-300 px-2 py-1 text-center font-semibold text-gray-900 dark:border-gray-600 dark:text-gray-100">予定日</th>
                    <th class="border border-gray-300 px-2 py-1 text-center font-semibold text-gray-900 dark:border-gray-600 dark:text-gray-100">入荷日</th>
                    <th class="border border-gray-300 px-2 py-1 text-center font-semibold text-gray-900 dark:border-gray-600 dark:text-gray-100">商品CD<br><span class="text-[10px] font-normal text-gray-500 dark:text-gray-400">検索CD</span></th>
                    <th class="border border-gray-300 px-2 py-1 text-left font-semibold text-gray-900 dark:border-gray-600 dark:text-gray-100">商品名</th>
                    <th class="border border-gray-300 px-2 py-1 text-right font-semibold text-gray-900 dark:border-gray-600 dark:text-gray-100">入数</th>
                    <th class="border border-gray-300 px-2 py-1 text-right font-semibold text-gray-900 dark:border-gray-600 dark:text-gray-100">発注総バラ</th>
                    <th class="border border-gray-300 px-2 py-1 text-right font-semibold text-gray-900 dark:border-gray-600 dark:text-gray-100">入荷総バラ</th>
                    <th class="border border-gray-300 px-2 py-1 text-center font-semibold text-gray-900 dark:border-gray-600 dark:text-gray-100">ロケ</th>
                    <th class="border border-gray-300 px-2 py-1 text-center font-semibold text-gray-900 dark:border-gray-600 dark:text-gray-100">確定者</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($details as $detail)
                    <tr class="bg-white even:bg-gray-50 dark:bg-gray-900 dark:even:bg-gray-800">
                        <td class="border border-gray-300 px-1.5 py-1 text-center text-gray-700 dark:border-gray-600 dark:text-gray-300">{{ $detail['id'] }}</td>
                        <td class="border border-gray-300 px-1.5 py-1 text-center font-semibold text-gray-900 dark:border-gray-600 dark:text-gray-100">{{ $detail['slip_number'] }}</td>
                        <td class="border border-gray-300 px-1.5 py-1 text-center text-gray-700 dark:border-gray-600 dark:text-gray-300">{{ $detail['order_source'] }}</td>
                        <td class="border border-gray-300 px-1.5 py-1 text-center text-gray-700 dark:border-gray-600 dark:text-gray-300">{{ $detail['expected_arrival_date'] }}</td>
                        <td class="border border-gray-300 px-1.5 py-1 text-center text-gray-700 dark:border-gray-600 dark:text-gray-300">{{ $detail['actual_arrival_date'] }}</td>
                        <td class="border border-gray-300 px-1.5 py-1 text-center text-gray-700 dark:border-gray-600 dark:text-gray-300">
                            <div class="font-semibold text-gray-900 dark:text-gray-100">{{ $detail['item_code'] }}</div>
                            <div class="text-[10px] leading-tight text-gray-500 dark:text-gray-400">{{ $detail['search_code'] }}</div>
                        </td>
                        <td class="min-w-[300px] border border-gray-300 px-1.5 py-1 text-gray-700 dark:border-gray-600 dark:text-gray-300">{{ $detail['item_name'] }}</td>
                        <td class="border border-gray-300 px-1.5 py-1 text-right text-gray-700 dark:border-gray-600 dark:text-gray-300">{{ $detail['capacity_case'] }}</td>
                        <td class="border border-gray-300 px-1.5 py-1 text-right font-semibold text-gray-900 dark:border-gray-600 dark:text-gray-100">{{ $detail['expected_total_piece_quantity'] }}</td>
                        <td class="border border-gray-300 px-1.5 py-1 text-right font-semibold text-gray-900 dark:border-gray-600 dark:text-gray-100">{{ $detail['received_total_piece_quantity'] }}</td>
                        <td class="border border-gray-300 px-1.5 py-1 text-center text-gray-700 dark:border-gray-600 dark:text-gray-300">{{ $detail['location'] }}</td>
                        <td class="border border-gray-300 px-1.5 py-1 text-center text-gray-700 dark:border-gray-600 dark:text-gray-300">{{ $detail['confirmed_by'] }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="12" class="border border-gray-300 px-2 py-4 text-center text-gray-500 dark:border-gray-600 dark:text-gray-400">
                            明細データなし
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
