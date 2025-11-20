<div class="max-w-full -my-4">
    @php
        // データを2行に分割（最初の6項目と残りの8項目）
        $firstRow = array_slice($data, 0, 6);
        $secondRow = array_slice($data, 6);
    @endphp

    {{-- 1行目 --}}
    <table class="w-full border-collapse border border-gray-300 dark:border-gray-600 mb-1">
        <thead>
            <tr class="bg-gray-50 dark:bg-gray-800">
                @foreach ($firstRow as $item)
                    <th class="border border-gray-300 dark:border-gray-600 px-2 py-1 text-sm font-semibold text-gray-900 dark:text-gray-100 text-center">
                        {{ $item['label'] }}
                    </th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            <tr class="bg-white dark:bg-gray-900">
                @foreach ($firstRow as $item)
                    <td class="border border-gray-300 dark:border-gray-600 px-2 py-1 text-sm text-center {{ isset($item['bold']) && $item['bold'] ? 'font-bold' : '' }} {{ isset($item['color']) && $item['color'] === 'red' ? 'text-red-600 dark:text-red-400' : 'text-gray-700 dark:text-gray-300' }}">
                        {{ $item['value'] }}
                    </td>
                @endforeach
            </tr>
        </tbody>
    </table>

    {{-- 2行目 --}}
    @if (count($secondRow) > 0)
        <table class="w-full border-collapse border border-gray-300 dark:border-gray-600">
            <thead>
                <tr class="bg-gray-50 dark:bg-gray-800">
                    @foreach ($secondRow as $item)
                        <th class="border border-gray-300 dark:border-gray-600 px-2 py-1 text-sm font-semibold text-gray-900 dark:text-gray-100 text-center">
                            {{ $item['label'] }}
                        </th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                <tr class="bg-white dark:bg-gray-900">
                    @foreach ($secondRow as $item)
                        <td class="border border-gray-300 dark:border-gray-600 px-2 py-1 text-sm text-center {{ isset($item['bold']) && $item['bold'] ? 'font-bold' : '' }} {{ isset($item['color']) && $item['color'] === 'red' ? 'text-red-600 dark:text-red-400' : 'text-gray-700 dark:text-gray-300' }}">
                            {{ $item['value'] }}
                        </td>
                    @endforeach
                </tr>
            </tbody>
        </table>
    @endif
</div>
