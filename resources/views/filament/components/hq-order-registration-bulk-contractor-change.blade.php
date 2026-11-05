@if ($showBulkContractorChangeModal)
    <div data-hq-modal data-hq-bulk-contractor-modal role="dialog" aria-modal="true" aria-label="発注先一括変更"
        x-data x-trap.inert.noscroll="true" x-on:keydown.escape.window="$wire.closeBulkContractorChangeModal()"
        wire:click.self="closeBulkContractorChangeModal"
        class="fixed inset-0 flex items-center justify-center bg-slate-950/50 p-4" style="z-index: 10000;">
        <div class="flex max-h-[85vh] flex-col overflow-hidden rounded-lg bg-white shadow-xl dark:bg-gray-900" style="width: min(900px, 96vw);">
            <div class="flex items-center justify-between bg-slate-800 px-4 py-3 text-white">
                <div class="flex items-center gap-2 text-sm font-semibold">
                    <x-heroicon-o-arrow-path class="h-5 w-5" />
                    発注先一括変更
                </div>
                <button type="button" wire:click="closeBulkContractorChangeModal" aria-label="変更せず閉じる" class="rounded p-1 hover:bg-white/10">
                    <x-heroicon-o-x-mark class="h-5 w-5" />
                </button>
            </div>
            <div class="space-y-3 border-b border-slate-200 p-4 dark:border-gray-700">
                <div class="rounded-md border border-blue-200 bg-blue-50 px-3 py-2 text-sm text-blue-900 dark:border-blue-800 dark:bg-blue-950/40 dark:text-blue-100">
                    対象：表示中の選択行 {{ number_format($selectedVisibleLineCount) }}件
                </div>
                <p class="text-xs leading-5 text-slate-600 dark:text-gray-300">
                    発注先に合わせて、仕入先・仕入原価・入荷予定日を再設定します。同じ発注先の行は変更しません。<br>
                    変更先でEOS発注できない明細はFAX発注になります。仕入先を決められない商品がある場合は、全件の変更を止めます。
                </p>
                <form wire:submit.prevent="searchBulkContractorChangeCandidates" class="flex items-end gap-2">
                    <label class="min-w-0 flex-1">
                        <span class="mb-1 block text-xs font-semibold text-slate-600 dark:text-gray-300">発注先CD・発注先名</span>
                        <input type="search" wire:model="bulkContractorSearch" placeholder="CD・名前で検索" autofocus
                            class="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-100">
                    </label>
                    <button type="submit" wire:loading.attr="disabled" class="rounded-md bg-slate-800 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700 disabled:opacity-40">検索</button>
                </form>
                <div class="text-xs text-slate-500 dark:text-gray-400">最大50件を表示します。CD・名前で絞り込んでください。</div>
            </div>
            <div class="min-h-0 overflow-auto">
                <table class="min-w-full divide-y divide-slate-200 text-sm dark:divide-gray-700">
                    <thead class="sticky top-0 bg-slate-50 text-xs text-slate-600 dark:bg-gray-800 dark:text-gray-300">
                        <tr><th class="px-3 py-2 text-left">発注先CD</th><th class="px-3 py-2 text-left">発注先名</th><th class="px-3 py-2 text-right">操作</th></tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 dark:divide-gray-800">
                        @forelse ($bulkContractorRows as $row)
                            <tr wire:key="bulk-contractor-{{ $row['id'] }}" class="odd:bg-white even:bg-blue-50/40 dark:odd:bg-gray-900 dark:even:bg-gray-800">
                                <td class="whitespace-nowrap px-3 py-2 font-mono">{{ $row['code'] }}</td>
                                <td class="px-3 py-2">{{ $row['name'] }}</td>
                                <td class="px-3 py-2 text-right">
                                    <button type="button" wire:click="applyBulkContractorChange({{ $row['id'] }})" wire:loading.attr="disabled" wire:target="applyBulkContractorChange,searchBulkContractorChangeCandidates"
                                        class="whitespace-nowrap rounded-md bg-danger-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-danger-500 disabled:opacity-40">この発注先へ変更</button>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="px-3 py-10 text-center text-slate-400 dark:text-gray-500">発注先が見つかりません。CD・名前を変更して検索してください。</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            <div class="flex justify-end border-t border-slate-200 bg-slate-50 px-4 py-3 dark:border-gray-700 dark:bg-gray-800">
                <button type="button" wire:click="closeBulkContractorChangeModal" class="rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50 dark:border-gray-600 dark:bg-gray-900 dark:text-gray-200">変更せず閉じる</button>
            </div>
        </div>
    </div>
@endif
