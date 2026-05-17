<x-filament-panels::page>
    @php
        $group = $this->getWaveGroup();
        $waves = $group->waves->sortBy('wave_no')->values();
        $targetLabels = \App\Filament\Resources\Waves\Tables\WaveGroupsTable::targetDocumentTypeLabels($group);
        $result = $group->generation_result ?? [];
        $pickingLists = $group->picking_lists ?? [];
        $timeSlot = ((int) $group->created_at?->format('H')) < 12 ? '午前' : '午後';
        $statusLabels = [
            'PENDING' => '未出荷',
            'PICKING' => 'ピッキング中',
            'SHORTAGE' => '欠品あり',
            'COMPLETED' => '出荷完了',
            'CLOSED' => 'クローズ',
        ];
    @endphp

    <div class="space-y-4">
        <section class="rounded-lg border border-slate-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="grid gap-4 md:grid-cols-4">
                <div>
                    <div class="text-xs font-medium text-slate-500 dark:text-gray-400">生成グループ</div>
                    <div class="font-mono text-sm font-bold text-slate-900 dark:text-gray-100">{{ $group->group_no }}</div>
                </div>
                <div>
                    <div class="text-xs font-medium text-slate-500 dark:text-gray-400">出荷日</div>
                    <div class="text-sm font-semibold text-slate-900 dark:text-gray-100">{{ $group->shipping_date?->format('Y年m月d日') }}</div>
                </div>
                <div>
                    <div class="text-xs font-medium text-slate-500 dark:text-gray-400">生成時間帯</div>
                    <div class="text-sm font-semibold text-slate-900 dark:text-gray-100">{{ $timeSlot }} / {{ $group->created_at?->format('Y-m-d H:i') }}</div>
                </div>
                <div>
                    <div class="text-xs font-medium text-slate-500 dark:text-gray-400">倉庫</div>
                    <div class="text-sm font-semibold text-slate-900 dark:text-gray-100">{{ $group->warehouse?->name ?? '-' }}</div>
                </div>
                <div>
                    <div class="text-xs font-medium text-slate-500 dark:text-gray-400">対象</div>
                    <div class="text-sm font-semibold text-slate-900 dark:text-gray-100">{{ implode(' / ', $targetLabels) }}</div>
                </div>
                <div>
                    <div class="text-xs font-medium text-slate-500 dark:text-gray-400">生成単位</div>
                    <div class="text-sm font-semibold text-slate-900 dark:text-gray-100">{{ $group->generation_type === 'buyer' ? '得意先別' : '配送コース' }}</div>
                </div>
                <div>
                    <div class="text-xs font-medium text-slate-500 dark:text-gray-400">波動数</div>
                    <div class="text-sm font-semibold text-slate-900 dark:text-gray-100">{{ $waves->count() }}件</div>
                </div>
                <div>
                    <div class="text-xs font-medium text-slate-500 dark:text-gray-400">ピッキングリスト</div>
                    <div class="text-sm font-semibold text-slate-900 dark:text-gray-100">{{ count($pickingLists) }}件</div>
                </div>
            </div>

            <div class="mt-4 grid gap-3 md:grid-cols-3">
                <div class="rounded-md border border-slate-200 bg-slate-50 p-3 dark:border-gray-700 dark:bg-gray-800">
                    <div class="text-xs text-slate-500 dark:text-gray-400">営業出荷</div>
                    <div class="text-xl font-bold text-slate-900 dark:text-gray-100">{{ (int) ($result['earning_count'] ?? 0) }}件</div>
                </div>
                <div class="rounded-md border border-slate-200 bg-slate-50 p-3 dark:border-gray-700 dark:bg-gray-800">
                    <div class="text-xs text-slate-500 dark:text-gray-400">物流出荷</div>
                    <div class="text-xl font-bold text-purple-600 dark:text-purple-300">{{ (int) ($result['stock_transfer_count'] ?? 0) }}件</div>
                </div>
                <div class="rounded-md border border-slate-200 bg-slate-50 p-3 dark:border-gray-700 dark:bg-gray-800">
                    <div class="text-xs text-slate-500 dark:text-gray-400">完了時刻</div>
                    <div class="text-sm font-bold text-slate-900 dark:text-gray-100">{{ $result['completed_at'] ?? '-' }}</div>
                </div>
            </div>
        </section>

        <section class="rounded-lg border border-slate-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <div class="flex items-center justify-between border-b border-slate-200 px-4 py-3 dark:border-gray-700">
                <h2 class="text-base font-bold text-slate-900 dark:text-gray-100">現在の波動リスト</h2>
                <span class="text-xs text-slate-500 dark:text-gray-400">{{ $waves->count() }}件</span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="bg-slate-50 text-xs font-medium text-slate-500 dark:bg-gray-800 dark:text-gray-400">
                        <tr>
                            <th class="px-3 py-2">波動番号</th>
                            <th class="px-3 py-2">出荷日</th>
                            <th class="px-3 py-2">倉庫</th>
                            <th class="px-3 py-2">配送コース</th>
                            <th class="px-3 py-2">状況</th>
                            <th class="px-3 py-2 text-right">印刷回数</th>
                            <th class="px-3 py-2">生成時刻</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-gray-800">
                        @forelse ($waves as $wave)
                            @php
                                $course = $wave->waveSetting?->deliveryCourse;
                                $warehouse = $course?->warehouse;
                            @endphp
                            <tr class="hover:bg-slate-50 dark:hover:bg-gray-800">
                                <td class="px-3 py-2 font-mono text-xs font-semibold text-slate-900 dark:text-gray-100">{{ $wave->wave_no }}</td>
                                <td class="px-3 py-2 text-slate-700 dark:text-gray-200">{{ $wave->shipping_date?->format('Y-m-d') }}</td>
                                <td class="px-3 py-2 text-slate-700 dark:text-gray-200">{{ $warehouse?->name ?? '-' }}</td>
                                <td class="px-3 py-2 text-slate-700 dark:text-gray-200">
                                    @if ($course)
                                        [{{ $course->code }}] {{ $course->name }}
                                    @else
                                        -
                                    @endif
                                </td>
                                <td class="px-3 py-2 text-slate-700 dark:text-gray-200">{{ $statusLabels[$wave->status] ?? $wave->status }}</td>
                                <td class="px-3 py-2 text-right text-slate-700 dark:text-gray-200">{{ $wave->print_count }}</td>
                                <td class="px-3 py-2 text-slate-700 dark:text-gray-200">{{ $wave->created_at?->format('Y-m-d H:i') }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-3 py-8 text-center text-sm text-slate-500 dark:text-gray-400">
                                    この生成グループに紐づく波動はまだありません。
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>
</x-filament-panels::page>
