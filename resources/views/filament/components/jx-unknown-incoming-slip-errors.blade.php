<div class="overflow-x-auto -my-2">
    <table class="w-full border-collapse border border-gray-300 text-sm dark:border-gray-600">
        <thead>
            <tr class="bg-gray-50 dark:bg-gray-800">
                <th class="border border-gray-300 px-2 py-1 text-center font-semibold text-gray-900 dark:border-gray-600 dark:text-gray-100">種別</th>
                <th class="border border-gray-300 px-2 py-1 text-center font-semibold text-gray-900 dark:border-gray-600 dark:text-gray-100">確認CD</th>
                <th class="border border-gray-300 px-2 py-1 text-left font-semibold text-gray-900 dark:border-gray-600 dark:text-gray-100">内容</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($errors as $error)
                @php
                    $isError = $error->error_type === 'ERROR';
                    $rowClass = $isError ? 'bg-red-50 dark:bg-red-900/20' : 'bg-yellow-50 dark:bg-yellow-900/20';
                    $typeClass = $isError ? 'text-red-700 dark:text-red-300' : 'text-yellow-700 dark:text-yellow-300';
                @endphp
                <tr class="{{ $rowClass }}">
                    <td class="border border-gray-300 px-2 py-1 text-center font-semibold dark:border-gray-600 {{ $typeClass }}">{{ $error->error_type }}</td>
                    <td class="border border-gray-300 px-2 py-1 text-center text-gray-700 dark:border-gray-600 dark:text-gray-300">{{ $error->error_code }}</td>
                    <td class="border border-gray-300 px-2 py-1 text-gray-700 dark:border-gray-600 dark:text-gray-300">{{ $error->error_message }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="3" class="border border-gray-300 px-2 py-4 text-center text-gray-500 dark:border-gray-600 dark:text-gray-400">
                        確認内容なし
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>
