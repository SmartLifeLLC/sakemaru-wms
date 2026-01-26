<div class="space-y-6" wire:poll.500ms="$refresh">
    {{-- ステップインジケーター --}}
    <div class="flex items-center justify-center space-x-4">
        @foreach ([
            ['num' => 1, 'label' => '削除確認'],
            ['num' => 2, 'label' => 'スナップショット'],
            ['num' => 3, 'label' => '発注生成'],
            ['num' => 4, 'label' => '完了'],
        ] as $s)
            <div class="flex items-center">
                <div @class([
                    'w-8 h-8 rounded-full flex items-center justify-center text-sm font-medium',
                    'bg-primary-500 text-white' => $step >= $s['num'] - 1,
                    'bg-gray-200 text-gray-600 dark:bg-gray-700 dark:text-gray-400' => $step < $s['num'] - 1,
                ])>
                    @if ($step > $s['num'] - 1)
                        <x-heroicon-s-check class="w-5 h-5" />
                    @else
                        {{ $s['num'] }}
                    @endif
                </div>
                <span @class([
                    'ml-2 text-sm',
                    'text-primary-600 dark:text-primary-400 font-medium' => $step == $s['num'] - 1,
                    'text-gray-500 dark:text-gray-400' => $step != $s['num'] - 1,
                ])>{{ $s['label'] }}</span>
            </div>
            @if (!$loop->last)
                <div @class([
                    'w-12 h-0.5',
                    'bg-primary-500' => $step >= $s['num'],
                    'bg-gray-200 dark:bg-gray-700' => $step < $s['num'],
                ])></div>
            @endif
        @endforeach
    </div>

    {{-- エラー表示 --}}
    @if ($errorMessage)
        <div class="p-4 bg-danger-50 dark:bg-danger-900/20 border border-danger-200 dark:border-danger-800 rounded-lg">
            <div class="flex items-center gap-2 text-danger-600 dark:text-danger-400">
                <x-heroicon-s-exclamation-circle class="w-5 h-5" />
                <span class="font-medium">エラーが発生しました</span>
            </div>
            <p class="mt-2 text-sm text-danger-600 dark:text-danger-400">{{ $errorMessage }}</p>
        </div>
    @endif

    {{-- プログレスバー --}}
    @if ($isProcessing)
        <div class="space-y-2">
            <div class="flex items-center justify-between text-sm">
                <span class="text-gray-600 dark:text-gray-400">{{ $progressMessage }}</span>
                <span class="text-gray-600 dark:text-gray-400">処理中...</span>
            </div>
            <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-2.5">
                <div class="bg-primary-500 h-2.5 rounded-full transition-all duration-300 animate-pulse" style="width: 100%"></div>
            </div>
        </div>
    @endif

    {{-- ステップ0: 削除確認 --}}
    @if ($step === 0 && !$isProcessing)
        <div class="p-6 bg-gray-50 dark:bg-gray-800 rounded-lg">
            <div class="flex items-start gap-4">
                <div class="p-3 bg-warning-100 dark:bg-warning-900/30 rounded-full">
                    <x-heroicon-o-trash class="w-6 h-6 text-warning-600 dark:text-warning-400" />
                </div>
                <div class="flex-1">
                    <h3 class="text-lg font-medium text-gray-900 dark:text-white">未承認の発注候補を削除</h3>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        現在 <span class="font-bold text-warning-600 dark:text-warning-400">{{ $pendingCount }}件</span> の未承認発注候補があります。
                    </p>
                    <p class="mt-2 text-sm text-gray-500 dark:text-gray-500">
                        新しい発注候補を生成する前に、既存の未承認データを削除することをお勧めします。
                    </p>
                </div>
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <x-filament::button
                    color="gray"
                    wire:click="skipStep1Delete"
                >
                    スキップ
                </x-filament::button>
                <x-filament::button
                    color="danger"
                    wire:click="executeStep1Delete"
                    :disabled="$pendingCount === 0"
                >
                    削除して続行
                </x-filament::button>
            </div>
        </div>
    @endif

    {{-- ステップ1: スナップショット作成 --}}
    @if ($step === 1 && !$isProcessing)
        <div class="p-6 bg-gray-50 dark:bg-gray-800 rounded-lg">
            <div class="flex items-start gap-4">
                <div class="p-3 bg-info-100 dark:bg-info-900/30 rounded-full">
                    <x-heroicon-o-camera class="w-6 h-6 text-info-600 dark:text-info-400" />
                </div>
                <div class="flex-1">
                    <h3 class="text-lg font-medium text-gray-900 dark:text-white">在庫スナップショットの作成</h3>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        現在の在庫データのスナップショットを生成します。
                    </p>
                    @if (isset($results['deleted']))
                        <p class="mt-2 text-sm text-success-600 dark:text-success-400">
                            ✓ {{ $results['deleted'] }}件の未承認発注候補を削除しました
                        </p>
                    @endif
                </div>
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <x-filament::button
                    color="primary"
                    wire:click="executeStep2Snapshot"
                >
                    スナップショット実行
                </x-filament::button>
            </div>
        </div>
    @endif

    {{-- ステップ2: 発注候補生成 --}}
    @if ($step === 2 && !$isProcessing)
        <div class="p-6 bg-gray-50 dark:bg-gray-800 rounded-lg">
            <div class="flex items-start gap-4">
                <div class="p-3 bg-success-100 dark:bg-success-900/30 rounded-full">
                    <x-heroicon-o-calculator class="w-6 h-6 text-success-600 dark:text-success-400" />
                </div>
                <div class="flex-1">
                    <h3 class="text-lg font-medium text-gray-900 dark:text-white">発注候補の生成</h3>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        スナップショットを基に発注候補を計算・生成します。
                    </p>
                    @if (isset($results['snapshot']))
                        <p class="mt-2 text-sm text-success-600 dark:text-success-400">
                            ✓ {{ $results['snapshot'] }}件のスナップショットを作成しました
                        </p>
                    @endif
                </div>
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <x-filament::button
                    color="success"
                    wire:click="executeStep3Calculate"
                >
                    発注候補を生成
                </x-filament::button>
            </div>
        </div>
    @endif

    {{-- ステップ3: 完了 --}}
    @if ($step === 3 && !$isProcessing)
        <div class="p-6 bg-success-50 dark:bg-success-900/20 rounded-lg">
            <div class="flex items-start gap-4">
                <div class="p-3 bg-success-100 dark:bg-success-900/30 rounded-full">
                    <x-heroicon-o-check-circle class="w-6 h-6 text-success-600 dark:text-success-400" />
                </div>
                <div class="flex-1">
                    <h3 class="text-lg font-medium text-success-800 dark:text-success-200">発注生成が完了しました</h3>
                    <p class="mt-1 text-sm text-success-700 dark:text-success-300">
                        バッチコード: <span class="font-mono font-bold">{{ $results['batchCode'] ?? '-' }}</span>
                    </p>
                </div>
            </div>

            {{-- 結果サマリー --}}
            <div class="mt-6 grid grid-cols-3 gap-4">
                <div class="p-4 bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
                    <div class="text-2xl font-bold text-gray-900 dark:text-white">{{ $results['deleted'] ?? 0 }}</div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">削除済み</div>
                </div>
                <div class="p-4 bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
                    <div class="text-2xl font-bold text-gray-900 dark:text-white">{{ $results['snapshot'] ?? 0 }}</div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">スナップショット</div>
                </div>
                <div class="p-4 bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
                    <div class="text-2xl font-bold text-primary-600 dark:text-primary-400">{{ $results['calculated'] ?? 0 }}</div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">発注候補</div>
                </div>
            </div>

            {{-- 倉庫別内訳 --}}
            @if (!empty($results['byWarehouse']))
                <div class="mt-6">
                    <h4 class="text-sm font-medium text-gray-700 dark:text-gray-300 mb-3">倉庫別内訳</h4>
                    <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach ($results['byWarehouse'] as $item)
                            <div class="flex items-center justify-between px-4 py-3">
                                <span class="text-sm text-gray-600 dark:text-gray-400">{{ $item['warehouse_name'] }}</span>
                                <span class="text-sm font-medium text-gray-900 dark:text-white">{{ $item['count'] }}件</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            <div class="mt-6 flex justify-end gap-3">
                <x-filament::button
                    color="gray"
                    wire:click="closeWizard"
                >
                    閉じる
                </x-filament::button>
                <x-filament::button
                    color="primary"
                    tag="a"
                    href="{{ route('filament.admin.resources.wms-order-candidates.index') }}"
                >
                    発注候補一覧へ
                </x-filament::button>
            </div>
        </div>
    @endif
</div>
