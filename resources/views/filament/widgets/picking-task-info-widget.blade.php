<div class="fi-wi-stats-overview">
    @if($task)
        <div class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <tbody class="divide-y divide-gray-200 dark:divide-white/5">
                        <tr>
                            <td class="px-4 py-2 font-medium text-gray-950 dark:text-white whitespace-nowrap bg-gray-50 dark:bg-white/5" style="width: 120px;">
                                ウェーブ
                            </td>
                            <td class="px-4 py-2 text-gray-500 dark:text-gray-400">
                                {{ $waveText }}
                            </td>
                            <td class="px-4 py-2 font-medium text-gray-950 dark:text-white whitespace-nowrap bg-gray-50 dark:bg-white/5" style="width: 120px;">
                                出荷日
                            </td>
                            <td class="px-4 py-2 text-gray-500 dark:text-gray-400">
                                {{ $shippingDate }}
                            </td>
                            <td class="px-4 py-2 font-medium text-gray-950 dark:text-white whitespace-nowrap bg-gray-50 dark:bg-white/5" style="width: 120px;">
                                配送コース
                            </td>
                            <td class="px-4 py-2 text-gray-500 dark:text-gray-400">
                                {{ $deliveryCourse }}
                            </td>
                        </tr>
                        <tr>
                            <td class="px-4 py-2 font-medium text-gray-950 dark:text-white whitespace-nowrap bg-gray-50 dark:bg-white/5">
                                倉庫
                            </td>
                            <td class="px-4 py-2 text-gray-500 dark:text-gray-400">
                                {{ $warehouse }}
                            </td>
                            <td class="px-4 py-2 font-medium text-gray-950 dark:text-white whitespace-nowrap bg-gray-50 dark:bg-white/5">
                                ピッキングエリア
                            </td>
                            <td class="px-4 py-2 text-gray-500 dark:text-gray-400">
                                {{ $pickingArea }}
                            </td>
                            <td class="px-4 py-2 font-medium text-gray-950 dark:text-white whitespace-nowrap bg-gray-50 dark:bg-white/5">
                                エリア制限
                            </td>
                            <td class="px-4 py-2">
                                <span class="inline-flex items-center gap-x-1.5 rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset {{ $isRestricted ? 'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-400/10 dark:text-red-400 dark:ring-red-400/30' : 'bg-green-50 text-green-700 ring-green-600/20 dark:bg-green-400/10 dark:text-green-400 dark:ring-green-400/30' }}">
                                    {{ $restrictedArea }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
