<div class="overflow-x-auto overflow-y-visible max-w-full">
    <table class="min-w-full border-collapse border border-gray-300 dark:border-gray-600">
        <thead>
            <tr class="bg-gray-50 dark:bg-gray-800">
                @foreach ($data as $item)
                    <th class="border border-gray-300 dark:border-gray-600 px-3 py-2 text-sm font-semibold text-gray-900 dark:text-gray-100 text-center whitespace-nowrap">
                        {{ $item['label'] }}
                    </th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            <tr class="bg-white dark:bg-gray-900">
                @foreach ($data as $item)
                    <td class="border border-gray-300 dark:border-gray-600 px-3 py-2 text-sm text-gray-700 dark:text-gray-300 text-center whitespace-nowrap {{ isset($item['bold']) && $item['bold'] ? 'font-bold' : '' }}">
                        {{ $item['value'] }}
                    </td>
                @endforeach
            </tr>
        </tbody>
    </table>
</div>
