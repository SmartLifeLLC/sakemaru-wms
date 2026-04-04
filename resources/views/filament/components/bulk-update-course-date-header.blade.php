<div class="-mx-6 -mt-8 mb-5">
    <div class="px-5 py-3 bg-slate-700 dark:bg-slate-800 rounded-t-xl">
        <div class="flex items-center gap-3">
            <span class="px-2 py-0.5 rounded text-xs font-bold bg-amber-500/20 text-amber-300">一括変更</span>
            <span class="text-white font-medium text-sm">コース・入荷日変更</span>
        </div>
    </div>
    <div class="px-5 py-3 bg-gray-50 dark:bg-white/5 border-b border-gray-200 dark:border-white/10">
        <div class="flex items-center gap-4">
            <div class="flex items-center gap-2">
                <span class="text-gray-500 dark:text-gray-400 text-sm">選択件数</span>
                <span class="text-lg font-bold text-gray-900 dark:text-white">{{ $totalCount }}件</span>
            </div>
            <div class="text-gray-300 dark:text-gray-600">|</div>
            <div class="flex items-center gap-2">
                <span class="text-gray-500 dark:text-gray-400 text-sm">変更対象</span>
                <span class="text-lg font-bold text-primary-600 dark:text-primary-400">{{ $pendingCount }}件</span>
            </div>
        </div>
        @if($totalCount !== $pendingCount)
            <div class="mt-1.5 text-xs text-amber-600 dark:text-amber-400">
                ※ 承認前（PENDING）の {{ $pendingCount }}件のみ変更されます。{{ $totalCount - $pendingCount }}件はスキップされます。
            </div>
        @endif
    </div>
</div>
