<div
    x-data="{
        rows: @js($rows),
        _syncTimer: null,
        formatNumber(value) {
            return new Intl.NumberFormat('ja-JP').format(Number(value || 0));
        },
        totalPieces(row) {
            return Number(row.case_quantity || 0) * Number(row.capacity_case || 1) + Number(row.piece_quantity || 0);
        },
        sync() {
            clearTimeout(this._syncTimer);
            this._syncTimer = setTimeout(() => {
                $wire.set('fastQuantityInputPayload', this.rows.map((row) => ({
                    id: row.id,
                    case_quantity: Number(row.case_quantity || 0),
                    piece_quantity: Number(row.piece_quantity || 0),
                })), false);
            }, 500);
        },
        updateFromInput(row, key, el) {
            row[key] = Math.max(0, Math.floor(Number(el.value || 0)));
            this.sync();
        },
        commitInput(row, key, el) {
            row[key] = Math.max(0, Math.floor(Number(el.value || 0)));
            el.value = row[key];
            this.sync();
        },
    }"
    x-init="sync()"
    class="space-y-3"
>
    <div class="rounded-md border border-blue-200 bg-blue-50 px-3 py-1.5 text-sm text-blue-800">
        現在の検索・フィルタ条件に該当する全行を対象にします。承認前以外の行は編集できません。
    </div>

    <div class="max-h-[65vh] overflow-auto rounded-md border border-gray-300 shadow-sm dark:border-gray-700">
        <table class="min-w-full divide-y divide-gray-200 text-sm dark:divide-gray-700">
            <thead class="sticky top-0 z-10 bg-slate-100 text-xs font-semibold text-slate-700 shadow-sm dark:bg-gray-800 dark:text-gray-200">
                <tr>
                    <th class="whitespace-nowrap px-2 py-1.5 text-left">商品CD</th>
                    <th class="min-w-64 px-2 py-1.5 text-left">商品名</th>
                    <th class="whitespace-nowrap px-2 py-1.5 text-left">規格</th>
                    <th class="min-w-48 px-2 py-1.5 text-left">仕入先</th>
                    <th class="whitespace-nowrap px-2 py-1.5 text-right">見込在庫</th>
                    <th class="whitespace-nowrap px-2 py-1.5 text-right">発注点</th>
                    <th class="whitespace-nowrap px-2 py-1.5 text-right">自動発注数</th>
                    <th class="whitespace-nowrap px-2 py-1.5 text-right">不足分</th>
                    <th class="whitespace-nowrap border-l-2 border-slate-300 bg-amber-100 px-2 py-1.5 text-right text-amber-900 dark:border-slate-600 dark:bg-amber-900/40 dark:text-amber-100">ケース</th>
                    <th class="whitespace-nowrap bg-amber-100 px-2 py-1.5 text-right text-amber-900 dark:bg-amber-900/40 dark:text-amber-100">バラ</th>
                    <th class="whitespace-nowrap bg-amber-100 px-2 py-1.5 text-right text-amber-900 dark:bg-amber-900/40 dark:text-amber-100">総バラ数</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 bg-white dark:divide-gray-800 dark:bg-gray-900">
                <template x-for="row in rows" :key="row.id">
                    <tr class="odd:bg-white even:bg-slate-50 hover:bg-blue-50 dark:odd:bg-gray-900 dark:even:bg-gray-800/60 dark:hover:bg-blue-950/40">
                        <td class="whitespace-nowrap px-2 py-1 font-mono text-xs" x-text="row.item_code"></td>
                        <td class="px-2 py-1 font-medium" x-text="row.item_name"></td>
                        <td class="whitespace-nowrap px-2 py-1" x-text="row.packaging"></td>
                        <td class="px-2 py-1 text-xs" x-text="row.supplier_name"></td>
                        <td class="whitespace-nowrap px-2 py-1 text-right" x-text="formatNumber(row.calculated_available)"></td>
                        <td class="whitespace-nowrap px-2 py-1 text-right" x-text="formatNumber(row.safety_stock)"></td>
                        <td class="whitespace-nowrap px-2 py-1 text-right" x-text="formatNumber(row.auto_order_quantity)"></td>
                        <td class="whitespace-nowrap px-2 py-1 text-right" x-text="formatNumber(row.shortage_qty)"></td>
                        <td class="whitespace-nowrap border-l-2 border-slate-300 bg-amber-50 px-2 py-1 text-right dark:border-slate-600 dark:bg-amber-950/30">
                            <input
                                type="number"
                                min="0"
                                x-init="$el.value = row.case_quantity"
                                x-bind:disabled="row.case_disabled"
                                x-on:input.debounce.150ms="updateFromInput(row, 'case_quantity', $el)"
                                x-on:blur="commitInput(row, 'case_quantity', $el)"
                                class="w-20 rounded-md border-2 border-amber-300 bg-white px-2 py-0.5 text-right text-sm font-semibold text-slate-900 shadow-sm focus:border-amber-500 focus:ring-2 focus:ring-amber-200 disabled:border-gray-200 disabled:bg-gray-100 disabled:text-gray-400 dark:border-amber-700 dark:bg-gray-900 dark:text-gray-100 dark:focus:border-amber-500 dark:focus:ring-amber-900 dark:disabled:border-gray-700 dark:disabled:bg-gray-800"
                            >
                        </td>
                        <td class="whitespace-nowrap bg-amber-50 px-2 py-1 text-right dark:bg-amber-950/30">
                            <input
                                type="number"
                                min="0"
                                x-init="$el.value = row.piece_quantity"
                                x-bind:disabled="row.piece_disabled"
                                x-on:input.debounce.150ms="updateFromInput(row, 'piece_quantity', $el)"
                                x-on:blur="commitInput(row, 'piece_quantity', $el)"
                                class="w-20 rounded-md border-2 border-amber-300 bg-white px-2 py-0.5 text-right text-sm font-semibold text-slate-900 shadow-sm focus:border-amber-500 focus:ring-2 focus:ring-amber-200 disabled:border-gray-200 disabled:bg-gray-100 disabled:text-gray-400 dark:border-amber-700 dark:bg-gray-900 dark:text-gray-100 dark:focus:border-amber-500 dark:focus:ring-amber-900 dark:disabled:border-gray-700 dark:disabled:bg-gray-800"
                            >
                        </td>
                        <td class="whitespace-nowrap bg-amber-50 px-2 py-1 text-right font-semibold text-amber-900 dark:bg-amber-950/30 dark:text-amber-100" x-text="formatNumber(totalPieces(row))"></td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>
</div>
