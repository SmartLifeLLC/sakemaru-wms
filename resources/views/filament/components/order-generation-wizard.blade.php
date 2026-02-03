<div class="space-y-6">
    {{-- ステップインジケーター --}}
    <div class="flex items-center justify-center space-x-4">
        @foreach ([
            ['num' => 1, 'label' => '削除確認'],
            ['num' => 2, 'label' => '生成処理'],
            ['num' => 3, 'label' => '完了'],
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
            <div class="mt-4 flex justify-end">
                <x-filament::button
                    color="gray"
                    wire:click="closeWizard"
                >
                    閉じる
                </x-filament::button>
            </div>
        </div>
    @endif

    {{-- プログレスバー（%表示付き） --}}
    @if ($isProcessing)
        <div class="space-y-2"
            wire:poll.1s="pollJobProgress"
        >
            <div class="flex items-center justify-between text-sm">
                <span class="text-gray-600 dark:text-gray-400">{{ $progressMessage }}</span>
                <span class="text-gray-600 dark:text-gray-400 font-mono">{{ $progress }}%</span>
            </div>
            <div class="w-full bg-gray-200 dark:bg-gray-700 rounded-full h-3 overflow-hidden">
                <div
                    class="bg-primary-500 h-3 rounded-full transition-all duration-300 ease-out"
                    style="width: {{ $progress }}%"
                ></div>
            </div>
        </div>
    @endif

    {{-- 発注確定待ちがある場合のブロック表示 --}}
    @if ($approvedCount > 0 && !$errorMessage)
        <div class="p-6 bg-danger-50 dark:bg-danger-900/20 border border-danger-200 dark:border-danger-800 rounded-lg">
            <div class="flex items-start gap-4">
                <div class="p-3 bg-danger-100 dark:bg-danger-900/30 rounded-full">
                    <x-heroicon-o-exclamation-triangle class="w-6 h-6 text-danger-600 dark:text-danger-400" />
                </div>
                <div class="flex-1">
                    <h3 class="text-lg font-medium text-danger-800 dark:text-danger-200">発注・移動候補の生成ができません</h3>
                    <p class="mt-1 text-sm text-danger-700 dark:text-danger-300">
                        現在 <span class="font-bold">{{ $approvedCount }}件</span> の発注確定待ちがあります。
                    </p>
                    <p class="mt-2 text-sm text-danger-600 dark:text-danger-400">
                        新しい発注・移動候補を生成する前に、発注確定待ちの処理を完了してください。
                    </p>
                </div>
            </div>
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
                    href="{{ route('filament.admin.resources.wms-order-confirmation-waiting.index') }}"
                >
                    発注確定待ちへ
                </x-filament::button>
            </div>
        </div>
    @elseif ($step === 0 && !$isProcessing && !$errorMessage)
    {{-- ステップ0: 削除確認 --}}
        <div class="p-6 bg-gray-50 dark:bg-gray-800 rounded-lg">
            <div class="flex items-start gap-4">
                <div class="p-3 bg-warning-100 dark:bg-warning-900/30 rounded-full">
                    <x-heroicon-o-trash class="w-6 h-6 text-warning-600 dark:text-warning-400" />
                </div>
                <div class="flex-1">
                    <h3 class="text-lg font-medium text-gray-900 dark:text-white">未承認の発注・移動候補を削除</h3>
                    @if ($pendingCount > 0 || $pendingTransferCount > 0)
                        <div class="mt-2 space-y-1">
                            @if ($pendingCount > 0)
                                <p class="text-sm text-gray-600 dark:text-gray-400">
                                    発注候補: <span class="font-bold text-warning-600 dark:text-warning-400">{{ $pendingCount }}件</span>
                                </p>
                            @endif
                            @if ($pendingTransferCount > 0)
                                <p class="text-sm text-gray-600 dark:text-gray-400">
                                    移動候補: <span class="font-bold text-warning-600 dark:text-warning-400">{{ $pendingTransferCount }}件</span>
                                </p>
                            @endif
                        </div>
                        <p class="mt-2 text-sm text-gray-500 dark:text-gray-500">
                            新しい発注・移動候補を生成する前に、既存の未承認データを削除することをお勧めします。
                        </p>
                    @else
                        <p class="mt-1 text-sm text-success-600 dark:text-success-400">
                            未承認の発注・移動候補はありません。
                        </p>
                        <p class="mt-2 text-sm text-gray-500 dark:text-gray-500">
                            次のステップに進んでください。
                        </p>
                    @endif
                </div>
            </div>
            <div class="mt-6 flex justify-end gap-3">
                @if ($pendingCount > 0 || $pendingTransferCount > 0)
                    <x-filament::button
                        color="gray"
                        wire:click="skipStep1Delete"
                    >
                        スキップ
                    </x-filament::button>
                    <x-filament::button
                        color="danger"
                        wire:click="executeStep1Delete"
                    >
                        削除して続行
                    </x-filament::button>
                @else
                    <x-filament::button
                        color="primary"
                        wire:click="skipStep1Delete"
                    >
                        続行
                    </x-filament::button>
                @endif
            </div>
        </div>
    @endif

    {{-- ステップ1: 生成開始確認 --}}
    @if ($step === 1 && !$isProcessing && !$errorMessage)
        <div class="p-6 bg-gray-50 dark:bg-gray-800 rounded-lg">
            <div class="flex items-start gap-4">
                <div class="p-3 bg-success-100 dark:bg-success-900/30 rounded-full">
                    <x-heroicon-o-sparkles class="w-6 h-6 text-success-600 dark:text-success-400" />
                </div>
                <div class="flex-1">
                    <h3 class="text-lg font-medium text-gray-900 dark:text-white">発注・移動候補を生成</h3>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        在庫スナップショットの作成と発注・移動候補の計算を実行します。
                    </p>
                    @if ((isset($results['deleted']) && $results['deleted'] > 0) || (isset($results['deletedTransfers']) && $results['deletedTransfers'] > 0))
                        <div class="mt-2 space-y-1">
                            @if (isset($results['deleted']) && $results['deleted'] > 0)
                                <p class="text-sm text-success-600 dark:text-success-400">
                                    ✓ {{ $results['deleted'] }}件の未承認発注候補を削除しました
                                </p>
                            @endif
                            @if (isset($results['deletedTransfers']) && $results['deletedTransfers'] > 0)
                                <p class="text-sm text-success-600 dark:text-success-400">
                                    ✓ {{ $results['deletedTransfers'] }}件の未承認移動候補を削除しました
                                </p>
                            @endif
                        </div>
                    @endif
                    <div class="mt-4 p-3 bg-info-50 dark:bg-info-900/20 rounded-lg border border-info-200 dark:border-info-800">
                        <p class="text-sm text-info-700 dark:text-info-300">
                            <x-heroicon-o-information-circle class="w-4 h-4 inline-block mr-1" />
                            この処理はバックグラウンドで実行されます。処理中は進捗が表示されます。
                        </p>
                    </div>
                </div>
            </div>
            <div class="mt-6 flex justify-end gap-3">
                <x-filament::button
                    color="gray"
                    wire:click="closeWizard"
                >
                    キャンセル
                </x-filament::button>
                <x-filament::button
                    color="success"
                    wire:click="startGenerationJob"
                >
                    生成を開始
                </x-filament::button>
            </div>
        </div>
    @endif

    {{-- ステップ2: 処理中（プログレスバーが表示される） --}}
    @if ($step === 2 && $isProcessing && !$errorMessage)
        <div class="p-6 bg-gray-50 dark:bg-gray-800 rounded-lg">
            <div class="flex items-start gap-4">
                <div class="p-3 bg-info-100 dark:bg-info-900/30 rounded-full">
                    <x-heroicon-o-arrow-path class="w-6 h-6 text-info-600 dark:text-info-400 animate-spin" />
                </div>
                <div class="flex-1">
                    <h3 class="text-lg font-medium text-gray-900 dark:text-white">発注・移動候補を生成中...</h3>
                    <p class="mt-1 text-sm text-gray-600 dark:text-gray-400">
                        処理が完了するまでお待ちください。このモーダルを閉じても処理は継続されます。
                    </p>
                </div>
            </div>
        </div>
    @endif

    {{-- ステップ3: 完了 --}}
    @if ($step === 3 && !$isProcessing && !$errorMessage)
        <div class="p-6 bg-success-50 dark:bg-success-900/20 rounded-lg">
            <div class="flex items-start gap-4">
                <div class="p-3 bg-success-100 dark:bg-success-900/30 rounded-full">
                    <x-heroicon-o-check-circle class="w-6 h-6 text-success-600 dark:text-success-400" />
                </div>
                <div class="flex-1">
                    <h3 class="text-lg font-medium text-success-800 dark:text-success-200">発注・移動候補の生成が完了しました</h3>
                    <p class="mt-1 text-sm text-success-700 dark:text-success-300">
                        実行CD: <span class="font-mono font-bold">{{ $results['batchCode'] ?? '-' }}</span>
                    </p>
                </div>
            </div>

            {{-- 結果サマリー --}}
            <div class="mt-6 grid grid-cols-2 gap-4">
                <div class="p-4 bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
                    <div class="text-2xl font-bold text-gray-900 dark:text-white">{{ ($results['deleted'] ?? 0) + ($results['deletedTransfers'] ?? 0) }}</div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">削除済み</div>
                    @if (($results['deleted'] ?? 0) > 0 || ($results['deletedTransfers'] ?? 0) > 0)
                        <div class="mt-1 text-xs text-gray-400 dark:text-gray-500">
                            発注{{ $results['deleted'] ?? 0 }}件 / 移動{{ $results['deletedTransfers'] ?? 0 }}件
                        </div>
                    @endif
                </div>
                <div class="p-4 bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
                    <div class="text-2xl font-bold text-gray-900 dark:text-white">{{ $results['snapshot'] ?? 0 }}</div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">スナップショット</div>
                </div>
            </div>
            <div class="mt-4 grid grid-cols-2 gap-4">
                <div class="p-4 bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
                    <div class="text-2xl font-bold text-primary-600 dark:text-primary-400">{{ $results['orderCandidates'] ?? $results['calculated'] ?? 0 }}</div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">発注候補</div>
                </div>
                <div class="p-4 bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700">
                    <div class="text-2xl font-bold text-info-600 dark:text-info-400">{{ $results['transferCandidates'] ?? 0 }}</div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">移動候補</div>
                </div>
            </div>

            {{-- 倉庫別内訳（非表示）
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
            --}}

            <div class="mt-6 flex justify-end gap-3">
                <x-filament::button
                    color="gray"
                    wire:click="closeWizard"
                >
                    閉じる
                </x-filament::button>
            </div>
        </div>
    @endif
</div>
