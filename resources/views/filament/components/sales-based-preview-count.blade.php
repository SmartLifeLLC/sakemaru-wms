@php
    $lw = $getLivewire();
@endphp

<div class="flex flex-wrap items-center gap-3 rounded-lg border border-slate-200 bg-white px-3 py-2 dark:border-slate-700 dark:bg-slate-900">
    <button type="button"
            wire:click="calculateSalesBasedPreviewCount"
            wire:loading.attr="disabled"
            wire:target="calculateSalesBasedPreviewCount"
            class="inline-flex items-center gap-1.5 rounded-md bg-slate-700 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-slate-600 disabled:cursor-wait disabled:opacity-60 dark:bg-slate-600 dark:hover:bg-slate-500">
        <x-heroicon-m-calculator class="h-4 w-4" />
        <span wire:loading.remove wire:target="calculateSalesBasedPreviewCount">候補件数を計算</span>
        <span wire:loading wire:target="calculateSalesBasedPreviewCount">計算中...</span>
    </button>

    @if ($lw->salesBasedPreviewError)
        <div class="text-xs font-medium text-danger-600 dark:text-danger-400">
            {{ $lw->salesBasedPreviewError }}
        </div>
    @elseif ($lw->salesBasedPreviewCount !== null)
        <div class="text-xs text-slate-700 dark:text-slate-200">
            新規追加予定:
            <span class="font-mono text-sm font-bold text-slate-900 dark:text-white">
                {{ number_format($lw->salesBasedPreviewCount) }}
            </span>
            件
        </div>
    @else
        <div class="text-xs text-slate-500 dark:text-slate-400">
            条件と仕入先を選んで件数を確認できます。
        </div>
    @endif
</div>
