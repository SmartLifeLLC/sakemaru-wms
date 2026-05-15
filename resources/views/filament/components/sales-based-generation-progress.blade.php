@php
    $lw = $getLivewire();
@endphp

@if ($lw->isSalesBasedProcessing || $lw->salesBasedJobError || ! empty($lw->salesBasedResults))
    <div @if ($lw->isSalesBasedProcessing) wire:poll.1s="pollSalesBasedJobProgress" @endif
         class="rounded-lg border border-slate-200 bg-white px-4 py-3 dark:border-slate-700 dark:bg-slate-900">
        @if ($lw->isSalesBasedProcessing)
            <div class="flex items-start gap-3">
                <x-heroicon-o-arrow-path class="mt-0.5 h-5 w-5 animate-spin text-info-600 dark:text-info-400" />
                <div class="min-w-0 flex-1 space-y-2">
                    <div class="flex items-center justify-between gap-3 text-xs">
                        <span class="font-medium text-slate-700 dark:text-slate-200">
                            {{ $lw->salesBasedProgressMessage ?: '処理中...' }}
                        </span>
                        <span class="font-mono text-slate-500 dark:text-slate-400">
                            {{ $lw->salesBasedProgress }}%
                        </span>
                    </div>
                    <div class="h-2 overflow-hidden rounded-full bg-slate-200 dark:bg-slate-700">
                        <div class="h-2 rounded-full bg-info-500 transition-all duration-300"
                             style="width: {{ $lw->salesBasedProgress }}%"></div>
                    </div>
                    <p class="text-xs text-slate-500 dark:text-slate-400">
                        ジョブ完了までこの画面で進捗を確認できます。閉じても処理は継続します。
                    </p>
                </div>
            </div>
        @elseif ($lw->salesBasedJobError)
            <div class="flex items-start gap-3 text-danger-700 dark:text-danger-300">
                <x-heroicon-o-exclamation-circle class="mt-0.5 h-5 w-5" />
                <div class="text-xs">
                    <div class="font-semibold">実績ベース発注候補生成に失敗しました</div>
                    <div class="mt-1">{{ $lw->salesBasedJobError }}</div>
                </div>
            </div>
        @else
            <div class="flex items-start gap-3 text-success-700 dark:text-success-300">
                <x-heroicon-o-check-circle class="mt-0.5 h-5 w-5" />
                <div class="text-xs">
                    <div class="font-semibold">実績ベース発注候補生成が完了しました</div>
                    <div class="mt-1">
                        実行CD:
                        <span class="font-mono font-semibold">
                            {{ $lw->salesBasedResults['batchCode'] ?? '-' }}
                        </span>
                        /
                        発注候補:
                        <span class="font-mono font-semibold">
                            {{ number_format($lw->salesBasedResults['orderCandidates'] ?? $lw->salesBasedResults['calculated'] ?? 0) }}
                        </span>
                        件
                    </div>
                </div>
            </div>
        @endif
    </div>
@endif
