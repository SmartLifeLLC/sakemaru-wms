<x-filament-widgets::widget>
    <x-filament::section>
        {{-- 凡例 --}}
        <div class="flex items-center gap-2 mb-3">
            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300">
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                確定済み
            </span>
            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-300">
                <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                待機中
            </span>
            <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400">
                未生成
            </span>
        </div>

        @if (empty($hubWarehouses))
            <div class="text-sm text-gray-500 dark:text-gray-400 py-4 text-center">
                HUB倉庫が設定されていません
            </div>
        @else
            <div class="space-y-5">
                @foreach ($hubWarehouses as $hub)
                    @php
                        $hubStatus = $warehouseStatuses[(string) $hub['id']] ?? 'none';
                        $confirmedCount = 0;
                        $totalCount = count($satelliteWarehouses);
                        foreach ($satelliteWarehouses as $sat) {
                            if (($warehouseStatuses[(string) $sat['id']] ?? 'none') === 'confirmed') {
                                $confirmedCount++;
                            }
                        }

                        // HUBカードのクラス
                        $hubCardClass = match ($hubStatus) {
                            'confirmed' => 'border-green-400 bg-green-50 dark:border-green-600 dark:bg-green-950',
                            'pending' => 'border-amber-300 bg-amber-50 dark:border-amber-600 dark:bg-amber-950',
                            default => 'border-gray-200 bg-gray-50 dark:border-gray-600 dark:bg-gray-800',
                        };
                        $hubBadgeClass = match ($hubStatus) {
                            'confirmed' => 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300',
                            'pending' => 'bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-300',
                            default => 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400',
                        };
                        $hubBadgeLabel = match ($hubStatus) {
                            'confirmed' => '完了',
                            'pending' => '待機中',
                            default => '未生成',
                        };
                    @endphp
                    <div class="flex gap-6 items-start">
                        {{-- 左側: HUB倉庫 --}}
                        <div class="flex-shrink-0" style="width: 260px;">
                            <div class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-2">HUB倉庫</div>
                            <div class="flex items-center gap-2">
                                <div class="flex-1 rounded-lg border-2 px-4 py-3 flex items-center justify-between {{ $hubCardClass }}">
                                    <span class="text-sm font-semibold text-gray-900 dark:text-gray-100">
                                        {{ $hub['name'] }}
                                    </span>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $hubBadgeClass }}">
                                        {{ $hubBadgeLabel }}
                                    </span>
                                </div>
                                <svg class="w-4 h-4 text-gray-400 dark:text-gray-500 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                                </svg>
                            </div>
                        </div>

                        {{-- 右側: サテライト店舗 --}}
                        <div class="flex-1 min-w-0">
                            <div class="text-xs font-medium text-gray-500 dark:text-gray-400 mb-2">
                                サテライト店舗 ({{ $confirmedCount }}/{{ $totalCount }})
                            </div>
                            <div class="flex flex-wrap gap-2">
                                @foreach ($satelliteWarehouses as $satellite)
                                    @php
                                        $satStatus = $warehouseStatuses[(string) $satellite['id']] ?? 'none';
                                        $satChipClass = match ($satStatus) {
                                            'confirmed' => 'bg-green-100 text-green-700 dark:bg-green-900 dark:text-green-300',
                                            'pending' => 'bg-amber-50 text-amber-700 dark:bg-amber-900 dark:text-amber-300',
                                            default => 'bg-gray-100 text-gray-500 dark:bg-gray-700 dark:text-gray-400',
                                        };
                                    @endphp
                                    <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-sm font-medium {{ $satChipClass }}">
                                        @if ($satStatus === 'confirmed')
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                        @elseif ($satStatus === 'pending')
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                                            </svg>
                                        @endif
                                        {{ $satellite['name'] }}
                                    </span>
                                @endforeach
                            </div>
                        </div>
                    </div>

                    @if (! $loop->last)
                        <hr class="border-gray-200 dark:border-gray-700" />
                    @endif
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
