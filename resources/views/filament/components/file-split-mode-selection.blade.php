@php
    $lw = $getLivewire();
@endphp

<div x-data="{ selected: $wire.entangle('fileSplitMode') }" class="space-y-3">
    <div class="text-sm font-medium text-slate-700 dark:text-gray-300 mb-2">発注データファイルの出力方式</div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        {{-- 納品先別（split） --}}
        <button
            type="button"
            @click="selected = 'split'"
            :class="selected === 'split'
                ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/30 ring-2 ring-blue-500/30'
                : 'border-slate-200 dark:border-gray-600 bg-white dark:bg-gray-800 hover:border-slate-300 dark:hover:border-gray-500 hover:bg-slate-50 dark:hover:bg-gray-750'"
            class="relative flex flex-col items-start gap-2 rounded-xl border-2 p-4 text-left transition-all duration-200 cursor-pointer"
        >
            <div class="flex items-center gap-3 w-full">
                <div
                    :class="selected === 'split'
                        ? 'bg-blue-500 text-white'
                        : 'bg-slate-100 dark:bg-gray-700 text-slate-400 dark:text-gray-500'"
                    class="flex items-center justify-center w-10 h-10 rounded-lg transition-colors duration-200"
                >
                    <x-heroicon-o-building-office-2 class="w-5 h-5" />
                </div>
                <div class="flex-1">
                    <div class="font-semibold text-sm text-slate-800 dark:text-gray-200">納品先（倉庫）別にファイルを分ける</div>
                </div>
                <div
                    :class="selected === 'split'
                        ? 'border-blue-500 bg-blue-500'
                        : 'border-slate-300 dark:border-gray-600'"
                    class="flex items-center justify-center w-5 h-5 rounded-full border-2 transition-colors duration-200"
                >
                    <div x-show="selected === 'split'" class="w-2 h-2 rounded-full bg-white"></div>
                </div>
            </div>
            <p class="text-xs text-slate-500 dark:text-gray-400 pl-[3.25rem]">担当倉庫の発注分のみ発注する場合はこちらを選択</p>
        </button>

        {{-- 1つにまとめる（merged） --}}
        <button
            type="button"
            @click="selected = 'merged'"
            :class="selected === 'merged'
                ? 'border-blue-500 bg-blue-50 dark:bg-blue-900/30 ring-2 ring-blue-500/30'
                : 'border-slate-200 dark:border-gray-600 bg-white dark:bg-gray-800 hover:border-slate-300 dark:hover:border-gray-500 hover:bg-slate-50 dark:hover:bg-gray-750'"
            class="relative flex flex-col items-start gap-2 rounded-xl border-2 p-4 text-left transition-all duration-200 cursor-pointer"
        >
            <div class="flex items-center gap-3 w-full">
                <div
                    :class="selected === 'merged'
                        ? 'bg-blue-500 text-white'
                        : 'bg-slate-100 dark:bg-gray-700 text-slate-400 dark:text-gray-500'"
                    class="flex items-center justify-center w-10 h-10 rounded-lg transition-colors duration-200"
                >
                    <x-heroicon-o-document-duplicate class="w-5 h-5" />
                </div>
                <div class="flex-1">
                    <div class="font-semibold text-sm text-slate-800 dark:text-gray-200">同じ発注先は1つのファイルにまとめる</div>
                </div>
                <div
                    :class="selected === 'merged'
                        ? 'border-blue-500 bg-blue-500'
                        : 'border-slate-300 dark:border-gray-600'"
                    class="flex items-center justify-center w-5 h-5 rounded-full border-2 transition-colors duration-200"
                >
                    <div x-show="selected === 'merged'" class="w-2 h-2 rounded-full bg-white"></div>
                </div>
            </div>
            <p class="text-xs text-slate-500 dark:text-gray-400 pl-[3.25rem]">本部まとめての発注の場合はこちら</p>
        </button>
    </div>
</div>
