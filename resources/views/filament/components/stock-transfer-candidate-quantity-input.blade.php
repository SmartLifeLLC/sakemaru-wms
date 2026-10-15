<div
    x-data="{
        // 数量は発注した単位の欄（ケース / バラ）に表示する
        rows: @js($rows).map((row) => ({
            ...row,
            case_qty: row.quantity_type === 'CASE' ? row.transfer_quantity : null,
            piece_qty: row.quantity_type === 'CASE' ? null : row.transfer_quantity,
        })),
        formatNumber(value) {
            return new Intl.NumberFormat('ja-JP').format(Number(value || 0));
        },
        sum(field) {
            return this.rows.reduce((total, row) => total + Number(row[field] || 0), 0);
        },
        // 保存する数量と単位。ケースに数量があればケース、バラに数量があればバラ。
        // どちらも 0 のときは元の単位のまま数量 0 にする。
        resolvedQuantity(row) {
            const caseQty = Math.max(0, Number(row.case_qty || 0));
            const pieceQty = Math.max(0, Number(row.piece_qty || 0));

            if (caseQty > 0) {
                return { transfer_quantity: caseQty, quantity_type: 'CASE' };
            }
            if (pieceQty > 0) {
                return { transfer_quantity: pieceQty, quantity_type: 'PIECE' };
            }

            return { transfer_quantity: 0, quantity_type: row.quantity_type === 'CASE' ? 'CASE' : 'PIECE' };
        },
        // 総バラ数（ケースは入数を掛ける）
        totalPieces(row) {
            const resolved = this.resolvedQuantity(row);
            const capacityCase = Math.max(1, Number(row.capacity_case || 1));

            return resolved.quantity_type === 'CASE' ? resolved.transfer_quantity * capacityCase : resolved.transfer_quantity;
        },
        sync() {
            $wire.set('transferQuantityInputPayload', this.rows.map((row) => ({
                id: row.id,
                ...this.resolvedQuantity(row),
            })), false);
        },
        // ケースとバラはどちらか一方だけ。片方に数量を入れたら、もう片方は空にする。
        cleanQuantity(row, field, oppositeField) {
            let value = String(row[field] ?? '');
            value = value.replace(/[０-９]/g, (char) => String.fromCharCode(char.charCodeAt(0) - 0xFEE0));
            value = value.replace(/[^0-9]/g, '');
            row[field] = value === '' ? null : value;
            if (Number(row[field] || 0) > 0) {
                row[oppositeField] = null;
            }
        },
        commitQuantity(row, field, oppositeField) {
            this.cleanQuantity(row, field, oppositeField);
            this.sync();
        },
        // Enter / Tab は同じ列（ケース or バラ）の次の行へ移動する
        focusNext(event) {
            this.$nextTick(() => {
                const field = event.target.dataset.transferQuantityInput;
                const inputs = Array.from(this.$root.querySelectorAll(`[data-transfer-quantity-input='${field}']:not([disabled])`));
                const index = inputs.indexOf(event.target);
                (inputs[index + 1] || inputs[index])?.focus();
                (inputs[index + 1] || inputs[index])?.select();
            });
        },
    }"
    x-init="sync()"
    class="space-y-3"
>
    <div class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200">
        現在の検索・フィルタ条件に該当する全行を対象にします。承認前以外の行は編集できません。
    </div>

    <div class="max-h-[65vh] overflow-auto rounded-md border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900">
        <table class="logistics-candidate-table min-w-full divide-y divide-slate-200 text-xs dark:divide-slate-700">
            <colgroup>
                <col class="logistics-candidate-contractor-col" style="width: 128px !important;">
                <col class="logistics-candidate-code-col" style="width: 64px !important;">
                <col class="logistics-candidate-item-name-col" style="width: 400px !important;">
                <col class="logistics-candidate-packaging-col" style="width: 68px !important;">
                <col class="logistics-candidate-order-qty-col" style="width: 44px !important;">
                <col class="logistics-candidate-order-qty-col" style="width: 44px !important;">
                <col class="logistics-candidate-number-col" style="width: 56px !important;">
                <col class="logistics-candidate-number-col" style="width: 72px !important;">
                <col class="logistics-candidate-number-col" style="width: 52px !important;">
                <col class="logistics-candidate-number-col" style="width: 52px !important;">
                <col class="logistics-candidate-number-col" style="width: 52px !important;">
                <col class="logistics-candidate-number-col" style="width: 56px !important;">
                <col class="logistics-candidate-number-col" style="width: 56px !important;">
                <col class="logistics-candidate-number-col" style="width: 56px !important;">
                <col class="logistics-candidate-number-col" style="width: 56px !important;">
                <col class="logistics-candidate-number-col" style="width: 56px !important;">
            </colgroup>
            <thead class="sticky top-0 z-10 bg-slate-100 text-slate-700 shadow-sm dark:bg-slate-800 dark:text-slate-200">
                <tr>
                    <th class="whitespace-nowrap px-2 py-1.5 text-left font-semibold">発注先</th>
                    <th class="whitespace-nowrap px-2 py-1.5 text-left font-semibold">商品CD</th>
                    <th class="logistics-candidate-item-name px-2 py-1.5 text-left font-semibold" style="width: 400px !important; min-width: 400px !important; max-width: 400px !important;">商品名</th>
                    <th class="whitespace-nowrap px-2 py-1.5 text-left font-semibold">規格</th>
                    <th class="logistics-candidate-order-qty whitespace-nowrap border-l-2 border-slate-300 bg-amber-100 px-1 py-1.5 text-right font-semibold text-amber-900 dark:border-slate-600 dark:bg-amber-900/40 dark:text-amber-100" title="ケース単位で発注する数量。ケースとバラはどちらか一方を入力します。">ケース</th>
                    <th class="logistics-candidate-order-qty whitespace-nowrap bg-amber-100 px-1 py-1.5 text-right font-semibold text-amber-900 dark:bg-amber-900/40 dark:text-amber-100" title="バラ単位で発注する数量。ケースとバラはどちらか一方を入力します。">バラ</th>
                    <th class="whitespace-nowrap px-2 py-1.5 text-right font-semibold" title="ケース入力は入数を掛け、バラ入力はそのままの発注数量です。">総バラ</th>
                    <th class="whitespace-nowrap px-2 py-1.5 text-right font-semibold">理論在庫</th>
                    <th class="whitespace-nowrap px-2 py-1.5 text-right font-semibold">入荷予定</th>
                    <th class="whitespace-nowrap px-2 py-1.5 text-center font-semibold">入荷予定日</th>
                    <th class="whitespace-nowrap px-2 py-1.5 text-right font-semibold">見込在庫</th>
                    <th class="whitespace-nowrap px-2 py-1.5 text-right font-semibold">実績合計</th>
                    <th class="whitespace-nowrap px-2 py-1.5 text-right font-semibold">販売</th>
                    <th class="whitespace-nowrap px-2 py-1.5 text-right font-semibold">返品</th>
                    <th class="whitespace-nowrap px-2 py-1.5 text-right font-semibold">移動</th>
                    <th class="whitespace-nowrap px-2 py-1.5 text-right font-semibold">日平均</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                <template x-for="(row, index) in rows" :key="row.id">
                    <tr class="odd:bg-white even:bg-blue-50/70 hover:bg-amber-50 dark:odd:bg-slate-900 dark:even:bg-slate-800/80 dark:hover:bg-amber-950/30">
                        <td class="whitespace-nowrap px-2 py-1.5 text-slate-700 dark:text-slate-200" x-text="row.contractor_name"></td>
                        <td class="whitespace-nowrap px-2 py-1.5 font-mono text-slate-700 dark:text-slate-200" x-text="row.item_code"></td>
                        <td class="logistics-candidate-item-name px-2 py-1.5 font-medium text-slate-900 dark:text-white" style="width: 400px !important; min-width: 400px !important; max-width: 400px !important;" x-text="row.item_name"></td>
                        <td class="whitespace-nowrap px-2 py-1.5 text-slate-600 dark:text-slate-300" x-text="row.item_packaging || '-'"></td>
                        <td class="logistics-candidate-order-qty whitespace-nowrap border-l-2 border-slate-300 dark:border-slate-600 bg-amber-50 px-1 py-1.5 text-right dark:bg-amber-950/30">
                            <input
                                type="text"
                                inputmode="numeric"
                                pattern="[0-9]*"
                                autocomplete="off"
                                x-model="row.case_qty"
                                x-bind:disabled="row.disabled || row.can_order_case === false"
                                x-bind:title="row.can_order_case === false ? '入数が未設定のためケース発注できません' : ''"
                                x-on:focus="$event.target.select()"
                                x-on:input.debounce.150ms="cleanQuantity(row, 'case_qty', 'piece_qty'); sync()"
                                x-on:blur="commitQuantity(row, 'case_qty', 'piece_qty')"
                                x-on:change="commitQuantity(row, 'case_qty', 'piece_qty')"
                                x-on:keydown.arrow-up.prevent
                                x-on:keydown.arrow-down.prevent
                                x-on:keydown.enter.prevent="commitQuantity(row, 'case_qty', 'piece_qty'); focusNext($event)"
                                x-on:keydown.tab.prevent="commitQuantity(row, 'case_qty', 'piece_qty'); focusNext($event)"
                                data-transfer-quantity-input="case"
                                class="w-12 rounded-md border-2 border-amber-300 bg-white px-1 py-0.5 text-right text-sm font-semibold text-slate-900 shadow-sm focus:border-amber-500 focus:ring-2 focus:ring-amber-200 disabled:border-gray-200 disabled:bg-gray-100 disabled:text-gray-400 dark:border-amber-700 dark:bg-gray-900 dark:text-gray-100 dark:focus:border-amber-500 dark:focus:ring-amber-900 dark:disabled:border-gray-700 dark:disabled:bg-gray-800"
                            >
                        </td>
                        <td class="logistics-candidate-order-qty whitespace-nowrap bg-amber-50 px-1 py-1.5 text-right dark:bg-amber-950/30">
                            <input
                                type="text"
                                inputmode="numeric"
                                pattern="[0-9]*"
                                autocomplete="off"
                                x-model="row.piece_qty"
                                x-bind:disabled="row.disabled"
                                x-on:focus="$event.target.select()"
                                x-on:input.debounce.150ms="cleanQuantity(row, 'piece_qty', 'case_qty'); sync()"
                                x-on:blur="commitQuantity(row, 'piece_qty', 'case_qty')"
                                x-on:change="commitQuantity(row, 'piece_qty', 'case_qty')"
                                x-on:keydown.arrow-up.prevent
                                x-on:keydown.arrow-down.prevent
                                x-on:keydown.enter.prevent="commitQuantity(row, 'piece_qty', 'case_qty'); focusNext($event)"
                                x-on:keydown.tab.prevent="commitQuantity(row, 'piece_qty', 'case_qty'); focusNext($event)"
                                data-transfer-quantity-input="piece"
                                class="w-12 rounded-md border-2 border-amber-300 bg-white px-1 py-0.5 text-right text-sm font-semibold text-slate-900 shadow-sm focus:border-amber-500 focus:ring-2 focus:ring-amber-200 disabled:border-gray-200 disabled:bg-gray-100 disabled:text-gray-400 dark:border-amber-700 dark:bg-gray-900 dark:text-gray-100 dark:focus:border-amber-500 dark:focus:ring-amber-900 dark:disabled:border-gray-700 dark:disabled:bg-gray-800"
                            >
                        </td>
                        <td class="whitespace-nowrap px-2 py-1.5 text-right font-mono font-bold" x-bind:class="totalPieces(row) > 0 ? 'text-red-600 dark:text-red-400' : 'text-slate-400'" x-text="formatNumber(totalPieces(row))" data-transfer-total-pieces></td>
                        <td class="whitespace-nowrap px-2 py-1.5 text-right font-mono text-slate-700 dark:text-slate-200" x-text="formatNumber(row.effective_stock)"></td>
                        <td class="whitespace-nowrap px-2 py-1.5 text-right font-mono text-slate-700 dark:text-slate-200" x-text="formatNumber(row.incoming_qty)"></td>
                        <td class="whitespace-nowrap px-2 py-1.5 text-center font-mono text-slate-700 dark:text-slate-200" x-text="row.expected_arrival_date || '-'"></td>
                        <td class="whitespace-nowrap px-2 py-1.5 text-right font-mono text-slate-700 dark:text-slate-200" x-text="formatNumber(row.projected_stock)"></td>
                        <td class="whitespace-nowrap px-2 py-1.5 text-right font-mono font-semibold text-slate-900 dark:text-white" x-text="formatNumber(row.sales_qty)"></td>
                        <td class="whitespace-nowrap px-2 py-1.5 text-right font-mono text-slate-700 dark:text-slate-200" x-text="formatNumber(row.sales_piece_qty)"></td>
                        <td class="whitespace-nowrap px-2 py-1.5 text-right font-mono text-slate-700 dark:text-slate-200" x-text="formatNumber(row.return_piece_qty)"></td>
                        <td class="whitespace-nowrap px-2 py-1.5 text-right font-mono text-slate-700 dark:text-slate-200" x-text="formatNumber(row.transfer_piece_qty)"></td>
                        <td class="whitespace-nowrap px-2 py-1.5 text-right font-mono text-slate-700 dark:text-slate-200" x-text="Number(row.daily_avg_qty || 0).toLocaleString('ja-JP', { minimumFractionDigits: 2, maximumFractionDigits: 2 })"></td>
                    </tr>
                </template>
            </tbody>
            <tfoot class="sticky bottom-0 bg-slate-100 text-slate-900 shadow-[0_-1px_0_rgba(148,163,184,0.45)] dark:bg-slate-800 dark:text-slate-100">
                <tr>
                    <td class="whitespace-nowrap px-2 py-1.5 text-right font-semibold" colspan="11">合計</td>
                    <td class="whitespace-nowrap px-2 py-1.5 text-right font-mono font-bold" x-text="formatNumber(sum('sales_qty'))"></td>
                    <td class="whitespace-nowrap px-2 py-1.5 text-right font-mono font-bold" x-text="formatNumber(sum('sales_piece_qty'))"></td>
                    <td class="whitespace-nowrap px-2 py-1.5 text-right font-mono font-bold" x-text="formatNumber(sum('return_piece_qty'))"></td>
                    <td class="whitespace-nowrap px-2 py-1.5 text-right font-mono font-bold" x-text="formatNumber(sum('transfer_piece_qty'))"></td>
                    <td class="whitespace-nowrap px-2 py-1.5"></td>
                </tr>
            </tfoot>
        </table>
    </div>
</div>
