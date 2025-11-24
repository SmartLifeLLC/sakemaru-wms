<div class="fi-wi-stats-overview !w-full !max-w-none">
    @if($task)
        <div class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 !w-full !max-w-none">
            <div class="overflow-x-auto !w-full">
                <table class="text-sm !w-full" style="table-layout: fixed;">
                    <tbody class="divide-y divide-gray-200 dark:divide-white/5">
                        {{-- 1段目: ウェーブNo. / ウェーブ名 / 出荷日 / ピッカー --}}
                        <tr>
                            <td class="px-3 py-2 font-medium text-gray-950 dark:text-white whitespace-nowrap bg-gray-50 dark:bg-white/5" style="width: 10%;">
                                ウェーブNo.
                            </td>
                            <td class="px-3 py-2 text-gray-500 dark:text-gray-400" style="width: 15%;">
                                {{ $waveNo }}
                            </td>
                            <td class="px-3 py-2 font-medium text-gray-950 dark:text-white whitespace-nowrap bg-gray-50 dark:bg-white/5" style="width: 10%;">
                                ウェーブ名
                            </td>
                            <td class="px-3 py-2 text-gray-500 dark:text-gray-400" style="width: 15%;">
                                {{ $waveName }}
                            </td>
                            <td class="px-3 py-2 font-medium text-gray-950 dark:text-white whitespace-nowrap bg-gray-50 dark:bg-white/5" style="width: 10%;">
                                出荷日
                            </td>
                            <td class="px-3 py-2 text-gray-500 dark:text-gray-400" style="width: 15%;">
                                {{ $shippingDate }}
                            </td>
                            <td class="px-3 py-2 font-medium text-gray-950 dark:text-white whitespace-nowrap bg-gray-50 dark:bg-white/5" style="width: 10%;">
                                ピッカー
                            </td>
                            <td class="px-3 py-2" style="width: 15%;">
                                <span class="inline-flex items-center gap-x-1.5 rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset {{ $pickerAssigned ? 'bg-green-50 text-green-700 ring-green-600/20 dark:bg-green-400/10 dark:text-green-400 dark:ring-green-400/30' : 'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-400/10 dark:text-red-400 dark:ring-red-400/30' }}">
                                    {{ $picker }}
                                </span>
                            </td>
                        </tr>
                        {{-- 2段目: 倉庫 / Floor / ピッキングエリア(制限エリア) / 温度帯 --}}
                        <tr>
                            <td class="px-3 py-2 font-medium text-gray-950 dark:text-white whitespace-nowrap bg-gray-50 dark:bg-white/5">
                                倉庫
                            </td>
                            <td class="px-3 py-2 text-gray-500 dark:text-gray-400">
                                {{ $warehouse }}
                            </td>
                            <td class="px-3 py-2 font-medium text-gray-950 dark:text-white whitespace-nowrap bg-gray-50 dark:bg-white/5">
                                フロア
                            </td>
                            <td class="px-3 py-2 text-gray-500 dark:text-gray-400">
                                {{ $floor }}
                            </td>
                            <td class="px-3 py-2 font-medium text-gray-950 dark:text-white whitespace-nowrap bg-gray-50 dark:bg-white/5">
                                ピッキングエリア
                            </td>
                            <td class="px-3 py-2 text-gray-500 dark:text-gray-400">
                                {{ $pickingArea }}
                            </td>
                            <td class="px-3 py-2 font-medium text-gray-950 dark:text-white whitespace-nowrap bg-gray-50 dark:bg-white/5">
                                温度帯
                            </td>
                            <td class="px-3 py-2">
                                <span class="inline-flex items-center gap-x-1.5 rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset
                                    @if($temperatureColor === 'gray') bg-gray-50 text-gray-700 ring-gray-600/20 dark:bg-gray-400/10 dark:text-gray-400 dark:ring-gray-400/30
                                    @elseif($temperatureColor === 'info') bg-blue-50 text-blue-700 ring-blue-600/20 dark:bg-blue-400/10 dark:text-blue-400 dark:ring-blue-400/30
                                    @elseif($temperatureColor === 'primary') bg-indigo-50 text-indigo-700 ring-indigo-600/20 dark:bg-indigo-400/10 dark:text-indigo-400 dark:ring-indigo-400/30
                                    @endif">
                                    {{ $temperatureType }}
                                </span>
                            </td>
                        </tr>
                        {{-- 3段目: 配送コース / 伝票締切時刻 / ピッキング開始時刻 / ピッキング完了時刻 --}}
                        <tr>
                            <td class="px-3 py-2 font-medium text-gray-950 dark:text-white whitespace-nowrap bg-gray-50 dark:bg-white/5">
                                配送コース
                            </td>
                            <td class="px-3 py-2 text-gray-500 dark:text-gray-400">
                                {{ $deliveryCourse }}
                            </td>
                            <td class="px-3 py-2 font-medium text-gray-950 dark:text-white whitespace-nowrap bg-gray-50 dark:bg-white/5">
                                伝票締切時刻
                            </td>
                            <td class="px-3 py-2 text-gray-500 dark:text-gray-400">
                                {{ $pickingStartTime }}
                            </td>
                            <td class="px-3 py-2 font-medium text-gray-950 dark:text-white whitespace-nowrap bg-gray-50 dark:bg-white/5">
                                ピッキング開始時刻
                            </td>
                            <td class="px-3 py-2 text-gray-500 dark:text-gray-400">
                                {{ $startedAt }}
                            </td>
                            <td class="px-3 py-2 font-medium text-gray-950 dark:text-white whitespace-nowrap bg-gray-50 dark:bg-white/5">
                                ピッキング完了時刻
                            </td>
                            <td class="px-3 py-2 text-gray-500 dark:text-gray-400">
                                {{ $completedAt }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
