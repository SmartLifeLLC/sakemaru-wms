<div class="fi-wi-stats-overview !w-full !max-w-none">
    @if($summary)
        <div class="overflow-hidden rounded-lg bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 !w-full !max-w-none">
            <div class="overflow-x-auto !w-full">
                <table class="text-sm !w-full" style="table-layout: fixed;">
                    <tbody class="divide-y divide-gray-200 dark:divide-white/5">
                        <tr>
                            <td class="px-3 py-2 font-medium text-gray-950 dark:text-white whitespace-nowrap bg-gray-50 dark:bg-white/5" style="width: 10%;">ウェーブNo.</td>
                            <td class="px-3 py-2 text-gray-500 dark:text-gray-400" style="width: 15%;">{{ $summary['wave_no'] }}</td>
                            <td class="px-3 py-2 font-medium text-gray-950 dark:text-white whitespace-nowrap bg-gray-50 dark:bg-white/5" style="width: 10%;">ウェーブ名</td>
                            <td class="px-3 py-2 text-gray-500 dark:text-gray-400" style="width: 15%;">{{ $summary['wave_name'] }}</td>
                            <td class="px-3 py-2 font-medium text-gray-950 dark:text-white whitespace-nowrap bg-gray-50 dark:bg-white/5" style="width: 10%;">出荷日</td>
                            <td class="px-3 py-2 text-gray-500 dark:text-gray-400" style="width: 15%;">{{ $summary['shipping_date'] }}</td>
                            <td class="px-3 py-2 font-medium text-gray-950 dark:text-white whitespace-nowrap bg-gray-50 dark:bg-white/5" style="width: 10%;">ピッカー</td>
                            <td class="px-3 py-2" style="width: 15%;">
                                <span class="inline-flex items-center gap-x-1.5 rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset {{ $summary['picker_assigned'] ? 'bg-green-50 text-green-700 ring-green-600/20 dark:bg-green-400/10 dark:text-green-400 dark:ring-green-400/30' : 'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-400/10 dark:text-red-400 dark:ring-red-400/30' }}">
                                    {{ $summary['picker'] }}
                                </span>
                            </td>
                        </tr>
                        <tr>
                            <td class="px-3 py-2 font-medium text-gray-950 dark:text-white whitespace-nowrap bg-gray-50 dark:bg-white/5">倉庫</td>
                            <td class="px-3 py-2 text-gray-500 dark:text-gray-400">{{ $summary['warehouse'] }}</td>
                            <td class="px-3 py-2 font-medium text-gray-950 dark:text-white whitespace-nowrap bg-gray-50 dark:bg-white/5">フロア</td>
                            <td class="px-3 py-2 text-gray-500 dark:text-gray-400">{{ $summary['floor'] }}</td>
                            <td class="px-3 py-2 font-medium text-gray-950 dark:text-white whitespace-nowrap bg-gray-50 dark:bg-white/5">ピッキングエリア</td>
                            <td class="px-3 py-2 text-gray-500 dark:text-gray-400">{{ $summary['picking_area'] }}</td>
                            <td class="px-3 py-2 font-medium text-gray-950 dark:text-white whitespace-nowrap bg-gray-50 dark:bg-white/5">温度帯</td>
                            <td class="px-3 py-2 text-gray-500 dark:text-gray-400">{{ $summary['temperature_type'] }}</td>
                        </tr>
                        <tr>
                            <td class="px-3 py-2 font-medium text-gray-950 dark:text-white whitespace-nowrap bg-gray-50 dark:bg-white/5">配送コース</td>
                            <td class="px-3 py-2 text-gray-500 dark:text-gray-400">{{ $summary['delivery_course'] }}</td>
                            <td class="px-3 py-2 font-medium text-gray-950 dark:text-white whitespace-nowrap bg-gray-50 dark:bg-white/5">タスク数</td>
                            <td class="px-3 py-2 text-gray-500 dark:text-gray-400">{{ number_format($summary['task_count']) }}件</td>
                            <td class="px-3 py-2 font-medium text-gray-950 dark:text-white whitespace-nowrap bg-gray-50 dark:bg-white/5">ステータス</td>
                            <td class="px-3 py-2 text-gray-500 dark:text-gray-400">{{ $summary['status_summary'] }}</td>
                            <td class="px-3 py-2 font-medium text-gray-950 dark:text-white whitespace-nowrap bg-gray-50 dark:bg-white/5">伝票締切時刻</td>
                            <td class="px-3 py-2 text-gray-500 dark:text-gray-400">{{ $summary['picking_start_time'] }}</td>
                        </tr>
                        <tr>
                            <td class="px-3 py-2 font-medium text-gray-950 dark:text-white whitespace-nowrap bg-gray-50 dark:bg-white/5">ピッキング開始時刻</td>
                            <td class="px-3 py-2 text-gray-500 dark:text-gray-400">{{ $summary['started_at'] }}</td>
                            <td class="px-3 py-2 font-medium text-gray-950 dark:text-white whitespace-nowrap bg-gray-50 dark:bg-white/5">ピッキング完了時刻</td>
                            <td class="px-3 py-2 text-gray-500 dark:text-gray-400">{{ $summary['completed_at'] }}</td>
                            <td class="px-3 py-2 font-medium text-gray-950 dark:text-white whitespace-nowrap bg-gray-50 dark:bg-white/5"></td>
                            <td class="px-3 py-2 text-gray-500 dark:text-gray-400"></td>
                            <td class="px-3 py-2 font-medium text-gray-950 dark:text-white whitespace-nowrap bg-gray-50 dark:bg-white/5"></td>
                            <td class="px-3 py-2 text-gray-500 dark:text-gray-400"></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    @endif
</div>
