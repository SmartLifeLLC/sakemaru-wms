<div class="shrink-0 rounded-md border border-sky-200 bg-sky-50 px-3 py-2 text-xs text-sky-950 dark:border-sky-800 dark:bg-sky-950/40 dark:text-sky-100">
    <div class="flex flex-wrap gap-4 font-semibold">
        <span>追加対象合計（ページをまたいだ全明細）</span>
        <span>ケース合計 <b class="font-mono" x-text="formatNumber(additionTotals.cases)"></b></span>
        <span>バラ合計 <b class="font-mono" x-text="formatNumber(additionTotals.pieces)"></b></span>
    </div>
    <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1">
        <template x-for="group in additionTotals.groups" :key="group.id">
            <span><span x-text="group.label"></span>：ケース <b x-text="formatNumber(group.cases)"></b> ／ バラ <b x-text="formatNumber(group.pieces)"></b></span>
        </template>
    </div>
</div>
