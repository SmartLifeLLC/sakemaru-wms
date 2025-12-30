<div class="space-y-4">
    {{-- Header Info --}}
    @php
        $state = $getState();
        // We assume the first item has the delivery course info or we pass it separately.
        // Actually, ViewField operates on the 'items' array.
        // We might need to pass the record or delivery course info via view data.
        // But ViewField only holds the field value.
        // Let's check how to pass extra data. ->viewData([])
    @endphp

    @if($deliveryCourse)
        <div class="px-4 py-2 bg-gray-100 rounded-lg dark:bg-gray-800">
            <span class="font-bold">配送コース:</span>
            <span class="ml-2">{{ $deliveryCourse->code }} : {{ $deliveryCourse->name }}</span>
        </div>
    @endif

    {{-- Table --}}
    <div class="overflow-x-auto border rounded-lg dark:border-gray-700">
        <table class="w-full text-sm text-left text-gray-500 dark:text-gray-400">
            <thead class="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-gray-700 dark:text-gray-400">
                <tr>
                    <th scope="col" class="px-3 py-2">得意先コード</th>
                    <th scope="col" class="px-3 py-2">得意先名</th>
                    <th scope="col" class="px-3 py-2">商品名</th>
                    <th scope="col" class="px-3 py-2">容量</th>
                    <th scope="col" class="px-3 py-2">入数</th>
                    <th scope="col" class="px-3 py-2 text-right">受注数</th>
                    <th scope="col" class="px-3 py-2 text-right w-32">引当数</th>
                    <th scope="col" class="px-3 py-2 text-right">欠品数</th>
                </tr>
            </thead>
            <tbody>
                @foreach($state as $uuid => $item)
                    <tr class="bg-white border-b dark:bg-gray-800 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-600">
                        <td class="px-3 py-2 font-medium text-gray-900 whitespace-nowrap dark:text-white">
                            {{ $item['partner_code'] }}
                        </td>
                        <td class="px-3 py-2">
                            {{ $item['partner_name'] }}
                        </td>
                        <td class="px-3 py-2">
                            {{ $item['item_name'] }}
                        </td>
                        <td class="px-3 py-2">
                            {{ $item['item_volume'] }}
                        </td>
                        <td class="px-3 py-2">
                            {{ $item['item_capacity'] }}
                        </td>
                        <td class="px-3 py-2 text-right">
                            {{ $item['ordered_qty'] }} {{ $item['ordered_qty_unit'] }}
                        </td>
                        <td class="px-3 py-2 text-right">
                            <input
                                type="number"
                                wire:model="{{ $getStatePath() }}.{{ $uuid }}.planned_qty"
                                class="bg-gray-50 border border-gray-300 text-gray-900 text-sm rounded-lg focus:ring-blue-500 focus:border-blue-500 block w-full p-2.5 dark:bg-gray-700 dark:border-gray-600 dark:placeholder-gray-400 dark:text-white dark:focus:ring-blue-500 dark:focus:border-blue-500 text-right"
                                min="0"
                                required
                            >
                        </td>
                        <td class="px-3 py-2 text-right text-red-600 font-medium">
                            {{ $item['shortage_qty'] }}
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
