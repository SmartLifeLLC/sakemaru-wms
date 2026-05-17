@php
    $lw = $getLivewire();
    $rows = $lw->salesBasedTransferPreviewRows ?? [];
    $conditions = $lw->salesBasedTransferPreviewConditions ?? [];
@endphp

<div
    x-data="{
        rows: @js($rows),
        conditions: @js($conditions),
        formatNumber(value) {
            return new Intl.NumberFormat('ja-JP').format(Number(value || 0));
        },
        conditionValue(key) {
            return this.conditions[key] || '-';
        },
        sync() {
            $wire.updateSalesBasedTransferPreviewRows(this.rows);
        },
        cleanQuantity(row) {
            let value = String(row.input_order_piece_qty ?? '');
            value = value.replace(/[０-９]/g, (char) => String.fromCharCode(char.charCodeAt(0) - 0xFEE0));
            value = value.replace(/[^0-9]/g, '');
            row.input_order_piece_qty = value === '' ? null : value;
        },
        commitQuantity(row) {
            this.cleanQuantity(row);
            this.sync();
        },
        removeRow(index) {
            this.rows.splice(index, 1);
            this.sync();
        },
        focusNext(index) {
            this.$nextTick(() => {
                const inputs = Array.from(this.$root.querySelectorAll('[data-order-quantity-input]'));
                (inputs[index + 1] || inputs[index])?.focus();
                (inputs[index + 1] || inputs[index])?.select();
            });
        },
    }"
    x-init="sync()"
    class="space-y-3"
>
    <div class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200">
        <div class="flex flex-wrap items-center gap-x-4 gap-y-1">
            <span>
                選択期間:
                <span class="font-mono font-semibold" x-text="conditionValue('sales_start_date')"></span>
                <span>より</span>
                <span class="font-mono font-semibold" x-text="conditionValue('sales_end_date')"></span>
            </span>
            <span>
                対象:
                <span class="font-semibold" x-text="conditionValue('target_warehouse_name')"></span>
            </span>
            <span>
                選択中倉庫:
                <span class="font-semibold" x-text="conditionValue('selected_warehouse_name')"></span>
            </span>
            <span>
                自動発注フラグ:
                <span class="font-semibold" x-text="conditionValue('auto_order_flag_filter')"></span>
            </span>
            <span>
                中分類:
                <span class="font-mono font-semibold" x-text="`${conditionValue('category2_count')}/${conditionValue('category2_total_count')}`"></span>
            </span>
            <span>
                件数:
                <span class="font-mono font-semibold" x-text="formatNumber(rows.length) + '件'"></span>
            </span>
        </div>
    </div>

    <div
        x-show="rows.length === 0"
        class="rounded-md border border-slate-200 bg-white px-4 py-6 text-center text-sm text-slate-500 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-400"
    >
        表示できる候補がありません。
    </div>

    <div x-show="rows.length > 0">
        <div class="max-h-[65vh] overflow-auto rounded-md border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900">
            <table class="logistics-candidate-table min-w-full divide-y divide-slate-200 text-xs dark:divide-slate-700">
                <colgroup>
                    <col class="logistics-candidate-delete-col" style="width: 28px !important;">
                    <col class="logistics-candidate-contractor-col" style="width: 128px !important;">
                    <col class="logistics-candidate-code-col" style="width: 64px !important;">
                    <col class="logistics-candidate-item-name-col" style="width: 500px !important;">
                    <col class="logistics-candidate-packaging-col" style="width: 68px !important;">
                    <col class="logistics-candidate-order-qty-col" style="width: 44px !important;">
                    <col class="logistics-candidate-number-col" style="width: 72px !important;">
                    <col class="logistics-candidate-number-col" style="width: 52px !important;">
                    <col class="logistics-candidate-number-col" style="width: 52px !important;">
                    <col class="logistics-candidate-number-col" style="width: 52px !important;">
                    <col class="logistics-candidate-number-col" style="width: 56px !important;">
                    <col class="logistics-candidate-number-col" style="width: 56px !important;">
                    <col class="logistics-candidate-number-col" style="width: 56px !important;">
                    <col class="logistics-candidate-number-col" style="width: 56px !important;">
                </colgroup>
                <thead class="sticky top-0 z-10 bg-slate-100 text-slate-700 shadow-sm dark:bg-slate-800 dark:text-slate-200">
                    <tr>
                        <th class="w-7 whitespace-nowrap px-1 py-1.5 text-center font-semibold"></th>
                        <th class="whitespace-nowrap px-2 py-1.5 text-left font-semibold">発注先</th>
                        <th class="whitespace-nowrap px-2 py-1.5 text-left font-semibold">商品CD</th>
                        <th class="logistics-candidate-item-name px-2 py-1.5 text-left font-semibold" style="width: 500px !important; min-width: 500px !important; max-width: 500px !important;">商品名</th>
                        <th class="whitespace-nowrap px-2 py-1.5 text-left font-semibold">規格</th>
                        <th class="logistics-candidate-order-qty whitespace-nowrap border-l-2 border-slate-300 bg-amber-100 px-1 py-1.5 text-right font-semibold text-amber-900 dark:border-slate-600 dark:bg-amber-900/40 dark:text-amber-100">発注バラ</th>
                        <th class="whitespace-nowrap px-2 py-1.5 text-right font-semibold">実績合計</th>
                        <th class="whitespace-nowrap px-2 py-1.5 text-right font-semibold">販売</th>
                        <th class="whitespace-nowrap px-2 py-1.5 text-right font-semibold">返品</th>
                        <th class="whitespace-nowrap px-2 py-1.5 text-right font-semibold">移動</th>
                        <th class="whitespace-nowrap px-2 py-1.5 text-right font-semibold">日平均</th>
                        <th class="whitespace-nowrap px-2 py-1.5 text-right font-semibold">理論在庫</th>
                        <th class="whitespace-nowrap px-2 py-1.5 text-right font-semibold">入荷予定</th>
                        <th class="whitespace-nowrap px-2 py-1.5 text-right font-semibold">見込在庫</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <template x-for="(row, index) in rows" :key="row.item_code + '-' + index">
                        <tr class="odd:bg-white even:bg-blue-50/70 hover:bg-amber-50 dark:odd:bg-slate-900 dark:even:bg-slate-800/80 dark:hover:bg-amber-950/30">
                            <td class="whitespace-nowrap px-1 py-1.5 text-center">
                                <button
                                    type="button"
                                    tabindex="-1"
                                    x-on:click="removeRow(index)"
                                    class="inline-flex h-6 w-6 items-center justify-center rounded-md text-slate-400 hover:bg-red-50 hover:text-red-600 focus:outline-none focus:ring-2 focus:ring-red-200 dark:text-slate-500 dark:hover:bg-red-950/40 dark:hover:text-red-300 dark:focus:ring-red-900"
                                    aria-label="候補から削除"
                                    title="候補から削除"
                                >
                                    <x-filament::icon
                                        icon="heroicon-m-x-mark"
                                        class="h-4 w-4"
                                    />
                                </button>
                            </td>
                            <td class="whitespace-nowrap px-2 py-1.5 text-slate-700 dark:text-slate-200" x-text="row.contractor_name"></td>
                            <td class="whitespace-nowrap px-2 py-1.5 font-mono text-slate-700 dark:text-slate-200" x-text="row.item_code"></td>
                            <td class="logistics-candidate-item-name px-2 py-1.5 font-medium text-slate-900 dark:text-white" style="width: 500px !important; min-width: 500px !important; max-width: 500px !important;" x-text="row.item_name"></td>
                            <td class="whitespace-nowrap px-2 py-1.5 text-slate-600 dark:text-slate-300" x-text="row.item_packaging || '-'"></td>
                            <td class="logistics-candidate-order-qty whitespace-nowrap border-l-2 border-slate-300 bg-amber-50 px-1 py-1.5 text-right dark:border-slate-600 dark:bg-amber-950/30">
                                <input
                                    type="text"
                                    inputmode="numeric"
                                    pattern="[0-9]*"
                                    autocomplete="off"
                                    x-model="row.input_order_piece_qty"
                                    x-on:focus="$event.target.select()"
                                    x-on:input.debounce.150ms="cleanQuantity(row); sync()"
                                    x-on:blur="commitQuantity(row)"
                                    x-on:change="commitQuantity(row)"
                                    x-on:keydown.arrow-up.prevent
                                    x-on:keydown.arrow-down.prevent
                                    x-on:keydown.enter.prevent="commitQuantity(row); focusNext(index)"
                                    x-on:keydown.tab.prevent="commitQuantity(row); focusNext(index)"
                                    data-order-quantity-input
                                    class="w-12 rounded-md border-2 border-amber-300 bg-white px-1 py-0.5 text-right text-sm font-semibold text-slate-900 shadow-sm focus:border-amber-500 focus:ring-2 focus:ring-amber-200 dark:border-amber-700 dark:bg-gray-900 dark:text-gray-100 dark:focus:border-amber-500 dark:focus:ring-amber-900"
                                >
                            </td>
                            <td class="whitespace-nowrap px-2 py-1.5 text-right font-mono font-semibold text-slate-900 dark:text-white" x-text="formatNumber(row.sales_qty)"></td>
                            <td class="whitespace-nowrap px-2 py-1.5 text-right font-mono text-slate-700 dark:text-slate-200" x-text="formatNumber(row.sales_piece_qty)"></td>
                            <td class="whitespace-nowrap px-2 py-1.5 text-right font-mono text-slate-700 dark:text-slate-200" x-text="formatNumber(row.return_piece_qty)"></td>
                            <td class="whitespace-nowrap px-2 py-1.5 text-right font-mono text-slate-700 dark:text-slate-200" x-text="formatNumber(row.transfer_piece_qty)"></td>
                            <td class="whitespace-nowrap px-2 py-1.5 text-right font-mono text-slate-700 dark:text-slate-200" x-text="Number(row.daily_avg_qty || 0).toLocaleString('ja-JP', { minimumFractionDigits: 2, maximumFractionDigits: 2 })"></td>
                            <td class="whitespace-nowrap px-2 py-1.5 text-right font-mono text-slate-700 dark:text-slate-200" x-text="formatNumber(row.effective_stock)"></td>
                            <td class="whitespace-nowrap px-2 py-1.5 text-right font-mono text-slate-700 dark:text-slate-200" x-text="formatNumber(row.incoming_qty)"></td>
                            <td class="whitespace-nowrap px-2 py-1.5 text-right font-mono text-slate-700 dark:text-slate-200" x-text="formatNumber(row.projected_stock)"></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>
</div>
