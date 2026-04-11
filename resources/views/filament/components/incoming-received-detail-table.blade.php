<div class="overflow-x-auto -my-2">
    <table class="w-full border-collapse border border-gray-300 dark:border-gray-600 text-sm">
        <thead>
            <tr class="bg-gray-50 dark:bg-gray-800">
                <th class="border border-gray-300 dark:border-gray-600 px-2 py-1 text-center font-semibold text-gray-900 dark:text-gray-100">行</th>
                <th class="border border-gray-300 dark:border-gray-600 px-2 py-1 text-center font-semibold text-gray-900 dark:text-gray-100">自社CD</th>
                <th class="border border-gray-300 dark:border-gray-600 px-2 py-1 font-semibold text-gray-900 dark:text-gray-100">品名</th>
                <th class="border border-gray-300 dark:border-gray-600 px-2 py-1 text-center font-semibold text-gray-900 dark:text-gray-100">JAN</th>
                <th class="border border-gray-300 dark:border-gray-600 px-2 py-1 text-right font-semibold text-gray-900 dark:text-gray-100">CS数</th>
                <th class="border border-gray-300 dark:border-gray-600 px-2 py-1 text-right font-semibold text-gray-900 dark:text-gray-100">バラ数</th>
                <th class="border border-gray-300 dark:border-gray-600 px-2 py-1 text-right font-semibold text-gray-900 dark:text-gray-100">出荷総数</th>
                <th class="border border-gray-300 dark:border-gray-600 px-2 py-1 text-right font-semibold text-gray-900 dark:text-gray-100">発注数</th>
                <th class="border border-gray-300 dark:border-gray-600 px-2 py-1 text-center font-semibold text-gray-900 dark:text-gray-100">照合</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($details as $detail)
                @php
                    $rowClass = match ($detail['match_status']) {
                        '欠品' => 'bg-red-50 dark:bg-red-900/20',
                        '一部欠品' => 'bg-yellow-50 dark:bg-yellow-900/20',
                        '一致' => 'bg-green-50 dark:bg-green-900/20',
                        default => 'bg-white dark:bg-gray-900',
                    };
                    $statusColor = match ($detail['match_status']) {
                        '欠品' => 'text-red-600 dark:text-red-400 font-bold',
                        '一部欠品' => 'text-yellow-600 dark:text-yellow-400 font-bold',
                        '一致' => 'text-green-600 dark:text-green-400',
                        default => 'text-gray-500 dark:text-gray-400',
                    };
                @endphp
                <tr class="{{ $rowClass }}">
                    <td class="border border-gray-300 dark:border-gray-600 px-2 py-1 text-center text-gray-700 dark:text-gray-300">{{ $detail['line'] }}</td>
                    <td class="border border-gray-300 dark:border-gray-600 px-2 py-1 text-center text-gray-700 dark:text-gray-300">{{ $detail['item_code'] }}</td>
                    <td class="border border-gray-300 dark:border-gray-600 px-2 py-1 text-gray-700 dark:text-gray-300 max-w-[200px] truncate">{{ $detail['product_name'] }}</td>
                    <td class="border border-gray-300 dark:border-gray-600 px-2 py-1 text-center text-gray-700 dark:text-gray-300">{{ $detail['jan_code'] }}</td>
                    <td class="border border-gray-300 dark:border-gray-600 px-2 py-1 text-right text-gray-700 dark:text-gray-300">{{ $detail['case_qty'] }}</td>
                    <td class="border border-gray-300 dark:border-gray-600 px-2 py-1 text-right text-gray-700 dark:text-gray-300">{{ $detail['piece_qty'] }}</td>
                    <td class="border border-gray-300 dark:border-gray-600 px-2 py-1 text-right font-semibold text-gray-900 dark:text-gray-100">{{ $detail['total_qty'] }}</td>
                    <td class="border border-gray-300 dark:border-gray-600 px-2 py-1 text-right text-gray-700 dark:text-gray-300">{{ $detail['expected_qty'] }}</td>
                    <td class="border border-gray-300 dark:border-gray-600 px-2 py-1 text-center {{ $statusColor }}">{{ $detail['match_status'] }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="9" class="border border-gray-300 dark:border-gray-600 px-2 py-4 text-center text-gray-500 dark:text-gray-400">
                        明細データなし
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
