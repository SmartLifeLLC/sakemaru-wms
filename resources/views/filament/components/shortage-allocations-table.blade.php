<div class="max-w-full">
    @if (count($allocations) > 0)
        <table class="w-full border-collapse border border-gray-300 dark:border-gray-600">
            <thead>
                <tr class="bg-gray-50 dark:bg-gray-800">
                    <th class="border border-gray-300 dark:border-gray-600 px-3 py-2 text-sm font-semibold text-gray-900 dark:text-gray-100 text-center">横持ち出荷倉庫</th>
                    <th class="border border-gray-300 dark:border-gray-600 px-3 py-2 text-sm font-semibold text-gray-900 dark:text-gray-100 text-center">横持ち出荷数量</th>
                    <th class="border border-gray-300 dark:border-gray-600 px-3 py-2 text-sm font-semibold text-gray-900 dark:text-gray-100 text-center">単位</th>
                    <th class="border border-gray-300 dark:border-gray-600 px-3 py-2 text-sm font-semibold text-gray-900 dark:text-gray-100 text-center">ステータス</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($allocations as $allocation)
                    <tr class="bg-white dark:bg-gray-900">
                        <td class="border border-gray-300 dark:border-gray-600 px-3 py-2 text-sm text-center text-gray-700 dark:text-gray-300">
                            {{ $allocation['warehouse_name'] }}
                        </td>
                        <td class="border border-gray-300 dark:border-gray-600 px-3 py-2 text-sm text-center text-gray-700 dark:text-gray-300">
                            {{ $allocation['assign_qty'] }}
                        </td>
                        <td class="border border-gray-300 dark:border-gray-600 px-3 py-2 text-sm text-center text-gray-700 dark:text-gray-300">
                            {{ $allocation['qty_type_label'] }}
                        </td>
                        <td class="border border-gray-300 dark:border-gray-600 px-3 py-2 text-sm text-center text-gray-700 dark:text-gray-300">
                            {{ $allocation['status_label'] }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @else
        <p class="text-sm text-gray-500 dark:text-gray-400 text-center py-4">横持ち出荷指示なし</p>
    @endif
</div>
