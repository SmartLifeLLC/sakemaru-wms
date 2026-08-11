@php
    $lw = $lw ?? (isset($getLivewire) ? $getLivewire() : null);
    $rows = $lw->salesBasedExternalOrderPreviewRows ?? [];
    $conditions = $lw->salesBasedExternalOrderPreviewConditions ?? [];
    $salesGenerationOrderChannel = $lw && property_exists($lw, 'salesGenerationOrderChannel')
        ? $lw->salesGenerationOrderChannel
        : null;
    $today = now()->toDateString();
    $defaultExpectedArrivalDate = $lw?->defaultOrderExpectedArrivalDate() ?? now()->addDay()->toDateString();
    $candidateColumnHelps = [
        '仕入先' => 'この商品で発注する仕入先です。EOS可・EOS不可の判定もここに表示します。',
        '分類CD' => '商品の中分類コードです。商品分類の確認に使います。',
        '商品CD' => '商品マスタの商品コードです。',
        '商品名' => '商品マスタの商品名です。長い商品名は省略表示されます。',
        '規格' => '商品の容量や入り数など、商品パッケージの規格です。',
        '棚番' => '選択中の倉庫で設定されている入荷デフォルト棚番です。',
        '発注点' => '選択中の倉庫・発注先・商品に設定されている発注点です。',
        '予定日' => '今回この画面から発注した場合の入荷予定日です。必要に応じて明細ごとに変更できます。',
        'ケース' => 'ケース単位で発注する数量を入力します。ケースとバラはどちらか一方を入力します。',
        'バラ' => 'バラ単位で発注する数量を入力します。ケースとバラはどちらか一方を入力します。',
        '理論在庫' => '選択中の倉庫の real_stocks.available_quantity です。数値をクリックすると他倉庫の理論在庫を確認できます。',
        '見込在庫' => '理論在庫に、既に発注済みで未入荷の納品予定数を加えた見込在庫です。',
        '納品予定数' => '既に発注済みで、まだ入荷完了またはキャンセルされていない納品予定数です。',
        '納品予定日' => '既に発注済みの未入荷予定のうち、最も近い納品予定日です。',
        '最終発注日' => 'この商品の直近の発注日です。',
        '1週' => '基準日を含む直近7日間の販売数量です。',
        '2週' => '1週の前の7日間の販売数量です。',
        '3週' => '2週の前の7日間の販売数量です。',
        '前月' => '基準日の前月1か月分の販売数量です。',
    ];
@endphp

<div
    x-data="{
        columnHelpDescriptions: @js($candidateColumnHelps),
        columnHelpLabel: null,
        rows: @js($rows),
        conditions: @js($conditions),
        orderChannel: @js($salesGenerationOrderChannel ?? 'FAX'),
        channelControlled: @js($salesGenerationOrderChannel !== null),
        today: @js($today),
        fallbackExpectedArrivalDate: @js($defaultExpectedArrivalDate),
        expectedArrivalDate: @js($conditions['expected_arrival_date'] ?? $defaultExpectedArrivalDate),
        expectedArrivalDisplayValue: '',
        expectedArrivalPreviousValue: '',
        formatNumber(value) {
            return new Intl.NumberFormat('ja-JP').format(Number(value || 0));
        },
        conditionValue(key) {
            return this.conditions[key] || '-';
        },
        isEosUnavailable(row) {
            return this.channelControlled && this.orderChannel === 'EOS' && row.is_eos_available === false;
        },
        isRowDisabled(row) {
            return this.isEosUnavailable(row);
        },
        hoveredItemName: null,
        itemNameTooltipX: 0,
        itemNameTooltipY: 0,
        updateItemNameTooltipPosition(event) {
            const padding = 16;
            this.itemNameTooltipX = Math.min(event.clientX + 14, window.innerWidth - 620 - padding);
            this.itemNameTooltipY = Math.min(event.clientY + 14, window.innerHeight - 160 - padding);
        },
        showItemNameTooltip(event, name) {
            this.hoveredItemName = name || null;
            this.updateItemNameTooltipPosition(event);
        },
        openColumnHelp(label) {
            this.columnHelpLabel = label;
        },
        closeColumnHelp() {
            this.columnHelpLabel = null;
        },
        syncExpectedArrivalDate() {
            this.conditions.expected_arrival_date = this.expectedArrivalDate;
            $wire.updateSalesBasedExternalOrderPreviewExpectedArrivalDate(this.expectedArrivalDate);
        },
        initExpectedArrivalDate() {
            this.expectedArrivalDisplayValue = this.expectedArrivalDate || '';
            this.expectedArrivalPreviousValue = this.expectedArrivalDate || '';
            this.rows.forEach((row) => this.rowExpectedArrivalDateFor(row));
        },
        cleanExpectedArrivalDate() {
            if (!this.expectedArrivalDisplayValue) return;

            let value = this.expectedArrivalDisplayValue;
            value = value.replace(/[０-９]/g, char => String.fromCharCode(char.charCodeAt(0) - 0xFEE0));
            value = value.replace(/[^0-9\-\/]/g, '');
            this.expectedArrivalDisplayValue = value;
        },
        formatExpectedArrivalDate() {
            this.cleanExpectedArrivalDate();

            const input = (this.expectedArrivalDisplayValue || '').trim();
            if (!input) {
                this.setExpectedArrivalDate(null);
                return;
            }

            const fullDateMatch = input.match(/^(\d{4})[-\/](\d{1,2})[-\/](\d{1,2})$/);
            if (fullDateMatch) {
                this.applyExpectedArrivalDate(
                    parseInt(fullDateMatch[1], 10),
                    parseInt(fullDateMatch[2], 10),
                    parseInt(fullDateMatch[3], 10),
                );
                return;
            }

            const digits = input.replace(/\D/g, '');
            if (digits.length === 0) return;

            const now = new Date();
            let year = now.getFullYear();
            let month = now.getMonth() + 1;
            let day = now.getDate();

            if (digits.length === 1 || digits.length === 2) {
                day = parseInt(digits, 10);
            } else if (digits.length === 3) {
                month = parseInt(digits.substring(0, 1), 10);
                day = parseInt(digits.substring(1, 3), 10);
            } else if (digits.length === 4) {
                month = parseInt(digits.substring(0, 2), 10);
                day = parseInt(digits.substring(2, 4), 10);
            } else if (digits.length === 6) {
                year = 2000 + parseInt(digits.substring(0, 2), 10);
                month = parseInt(digits.substring(2, 4), 10);
                day = parseInt(digits.substring(4, 6), 10);
            } else if (digits.length === 8) {
                year = parseInt(digits.substring(0, 4), 10);
                month = parseInt(digits.substring(4, 6), 10);
                day = parseInt(digits.substring(6, 8), 10);
            } else {
                this.restoreExpectedArrivalDate();
                return;
            }

            this.applyExpectedArrivalDate(year, month, day);
        },
        applyExpectedArrivalDate(year, month, day) {
            const parsed = new Date(year, month - 1, day);

            if (
                parsed.getFullYear() !== year ||
                parsed.getMonth() !== month - 1 ||
                parsed.getDate() !== day
            ) {
                this.restoreExpectedArrivalDate();
                return;
            }

            const formatted = [
                parsed.getFullYear(),
                String(parsed.getMonth() + 1).padStart(2, '0'),
                String(parsed.getDate()).padStart(2, '0'),
            ].join('-');

            this.setExpectedArrivalDate(formatted);
        },
        setExpectedArrivalDate(value) {
            this.expectedArrivalDate = value || '';
            this.expectedArrivalDisplayValue = value || '';
            this.expectedArrivalPreviousValue = value || '';
            this.applyExpectedArrivalDateToRows(this.expectedArrivalDate);
            this.syncExpectedArrivalDate();
            this.sync();
        },
        applyExpectedArrivalDateToRows(value) {
            if (!value) return;

            const date = value < this.today ? this.today : value;
            this.rows.forEach((row) => {
                if (this.isRowDisabled(row)) return;
                row.default_expected_arrival_date = date;
            });
        },
        rowExpectedArrivalDateFor(row) {
            const fallback = this.expectedArrivalDate || this.fallbackExpectedArrivalDate;
            const defaultDate = row.default_expected_arrival_date || fallback;
            row.default_expected_arrival_date = defaultDate < this.today ? this.today : defaultDate;

            return row.default_expected_arrival_date;
        },
        setRowExpectedArrivalDate(row, value) {
            const fallback = this.expectedArrivalDate || this.fallbackExpectedArrivalDate;
            const date = value || row.default_expected_arrival_date || fallback;
            row.default_expected_arrival_date = date < this.today ? this.today : date;
            this.sync();
        },
        restoreExpectedArrivalDate() {
            this.expectedArrivalDisplayValue = this.expectedArrivalPreviousValue || '';
        },
        syncExpectedArrivalDateFromPicker(event) {
            this.setExpectedArrivalDate(event.target.value || null);
        },
        openExpectedArrivalDatePicker() {
            const picker = this.$refs.expectedArrivalDatePicker;
            if (!picker) return;

            picker.value = this.expectedArrivalDate || '';

            if (typeof picker.showPicker === 'function') {
                picker.showPicker();
                return;
            }

            picker.click();
        },
        sync() {
            this.rows.forEach((row) => {
                this.rowExpectedArrivalDateFor(row);
                if (!this.isRowDisabled(row)) return;

                row.input_order_case_qty = null;
                row.input_order_piece_qty = null;
            });
            $wire.updateSalesBasedExternalOrderPreviewRows(this.rows);
        },
        cleanQuantity(row, field, oppositeField) {
            if (this.isRowDisabled(row)) {
                row[field] = null;
                row[oppositeField] = null;
                return;
            }

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
        focusNextInput(event) {
            this.$nextTick(() => {
                const inputs = Array.from(this.$root.querySelectorAll('[data-order-quantity-input]'));
                const enabledInputs = inputs.filter((input) => !input.disabled);
                const currentIndex = enabledInputs.indexOf(event.target);
                (enabledInputs[currentIndex + 1] || enabledInputs[currentIndex])?.focus();
                (enabledInputs[currentIndex + 1] || enabledInputs[currentIndex])?.select();
            });
        },
        focusAdjacentInput(event, direction) {
            this.$nextTick(() => {
                const inputs = Array.from(this.$root.querySelectorAll('[data-order-quantity-input]'));
                const currentIndex = inputs.indexOf(event.target);
                if (currentIndex < 0) return;

                const columnCount = 2;
                const step = direction === 'up'
                    ? -columnCount
                    : direction === 'down'
                        ? columnCount
                        : direction === 'left'
                            ? -1
                            : 1;

                let nextIndex = currentIndex + step;
                while (nextIndex >= 0 && nextIndex < inputs.length && inputs[nextIndex].disabled) {
                    nextIndex += step > 0 ? 1 : -1;
                }

                const nextInput = inputs[nextIndex] || event.target;
                nextInput.focus();
                nextInput.select();
            });
        },
    }"
    x-init="initExpectedArrivalDate(); sync()"
    class="space-y-3"
>
    <div
        x-cloak
        x-show="hoveredItemName"
        x-transition.opacity.duration.100ms
        class="pointer-events-none fixed z-[9999] max-w-[620px] whitespace-normal rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-semibold leading-6 text-slate-900 shadow-xl ring-1 ring-black/5 dark:border-slate-600 dark:bg-slate-900 dark:text-white"
        x-bind:style="`left: ${Math.max(16, itemNameTooltipX)}px; top: ${Math.max(16, itemNameTooltipY)}px;`"
        x-text="hoveredItemName"
    ></div>
    @include('filament.components.order-registration-column-help-modal')

    <div class="rounded-md border border-slate-200 bg-slate-50 px-3 py-2 text-xs text-slate-700 dark:border-slate-700 dark:bg-slate-900 dark:text-slate-200">
        <div class="flex flex-wrap items-center gap-x-4 gap-y-2">
            <label class="flex items-center gap-2 text-sm font-semibold text-slate-800 dark:text-slate-100">
                <span class="whitespace-nowrap">一括入荷予定日:</span>
                <span class="relative block w-56">
                    <input
                        type="text"
                        inputmode="numeric"
                        x-model="expectedArrivalDisplayValue"
                        x-on:focus="$event.target.select()"
                        x-on:input="cleanExpectedArrivalDate()"
                        x-on:blur="formatExpectedArrivalDate()"
                        x-on:keyup.enter.prevent="formatExpectedArrivalDate()"
                        placeholder="YYYY-MM-DD または 数字"
                        class="w-full rounded-md border-2 border-blue-400 bg-white py-1.5 pl-3 pr-9 text-base font-semibold text-slate-900 shadow-sm outline-none focus:border-blue-500 focus:ring-2 focus:ring-blue-200 dark:border-blue-700 dark:bg-slate-950 dark:text-white dark:focus:border-blue-500 dark:focus:ring-blue-900"
                    >
                    <button
                        type="button"
                        x-on:click="openExpectedArrivalDatePicker()"
                        class="absolute inset-y-0 right-0 flex w-9 items-center justify-center text-slate-400 transition hover:text-blue-600 focus:outline-none dark:text-slate-500 dark:hover:text-blue-400"
                        tabindex="-1"
                        aria-label="カレンダーを開く"
                    >
                        <x-filament::icon icon="heroicon-m-calendar" class="h-5 w-5" />
                    </button>
                    <input
                        type="date"
                        x-ref="expectedArrivalDatePicker"
                        x-bind:value="expectedArrivalDate || ''"
                        x-bind:min="today"
                        x-on:change="syncExpectedArrivalDateFromPicker($event)"
                        class="pointer-events-none absolute bottom-0 right-0 h-px w-px opacity-0"
                        tabindex="-1"
                        aria-hidden="true"
                    >
                </span>
            </label>
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
            <span x-show="channelControlled">
                発注区分:
                <span class="font-semibold" x-text="orderChannel === 'EOS' ? 'EOS発注' : 'FAX発注'"></span>
            </span>
            <span x-show="channelControlled && orderChannel === 'EOS'">
                EOS不可:
                <span class="font-mono font-semibold text-amber-700 dark:text-amber-300" x-text="formatNumber(rows.filter((row) => isEosUnavailable(row)).length) + '件'"></span>
            </span>
            <span>
                発注店:
                <span class="font-semibold" x-text="conditionValue('selected_warehouse_name')"></span>
            </span>
            <span>
                仕入先:
                <span class="font-mono font-semibold" x-text="formatNumber(conditionValue('contractor_count')) + '件'"></span>
            </span>
            <span>
                中分類:
                <span class="font-mono font-semibold" x-text="formatNumber(conditionValue('category2_count')) + '件'"></span>
            </span>
            <span>
                自動発注フラグ:
                <span class="font-semibold" x-text="conditionValue('auto_order_flag_filter')"></span>
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
        <div class="max-h-[58vh] overflow-auto rounded-md border border-slate-200 bg-white shadow-sm dark:border-slate-700 dark:bg-slate-900">
            <table class="logistics-candidate-table divide-y divide-slate-200 text-xs dark:divide-slate-700" style="width: 1760px; min-width: 1760px;">
                <colgroup>
                    <col class="logistics-candidate-contractor-col" style="width: 132px !important;">
                    <col class="logistics-candidate-code-col" style="width: 54px !important;">
                    <col class="logistics-candidate-code-col" style="width: 64px !important;">
                    <col class="logistics-candidate-item-name-col" style="width: 390px !important;">
                    <col class="logistics-candidate-packaging-col" style="width: 68px !important;">
                    <col class="logistics-candidate-location-col" style="width: 78px !important;">
                    <col class="logistics-candidate-number-col" style="width: 54px !important;">
                    <col class="logistics-candidate-date-col" style="width: 136px !important;">
                    <col class="logistics-candidate-order-qty-col" style="width: 44px !important;">
                    <col class="logistics-candidate-order-qty-col" style="width: 44px !important;">
                    <col class="logistics-candidate-number-col" style="width: 54px !important;">
                    <col class="logistics-candidate-number-col" style="width: 58px !important;">
                    <col class="logistics-candidate-number-col" style="width: 54px !important;">
                    <col class="logistics-candidate-date-col" style="width: 92px !important;">
                    <col class="logistics-candidate-number-col" style="width: 58px !important;">
                    <col class="logistics-candidate-number-col" style="width: 54px !important;">
                    <col class="logistics-candidate-number-col" style="width: 54px !important;">
                    <col class="logistics-candidate-number-col" style="width: 54px !important;">
                    <col class="logistics-candidate-number-col" style="width: 58px !important;">
                </colgroup>
                <thead class="sticky top-0 z-10 bg-slate-100 text-slate-700 shadow-sm dark:bg-slate-800 dark:text-slate-200">
                    <tr>
                        <th class="whitespace-nowrap px-2 py-1.5 text-left font-semibold"><x-order-registration.column-help-heading label="仕入先" /></th>
                        <th class="whitespace-nowrap px-1 py-1.5 text-left font-semibold"><x-order-registration.column-help-heading label="分類CD" /></th>
                        <th class="whitespace-nowrap px-2 py-1.5 text-left font-semibold"><x-order-registration.column-help-heading label="商品CD" /></th>
                        <th class="logistics-candidate-item-name px-2 py-1.5 text-left font-semibold" style="width: 390px !important; min-width: 390px !important; max-width: 390px !important;"><x-order-registration.column-help-heading label="商品名" /></th>
                        <th class="whitespace-nowrap px-2 py-1.5 text-left font-semibold"><x-order-registration.column-help-heading label="規格" /></th>
                        <th class="whitespace-nowrap px-2 py-1.5 text-left font-semibold"><x-order-registration.column-help-heading label="棚番" /></th>
                        <th class="whitespace-nowrap px-2 py-1.5 text-right font-semibold"><x-order-registration.column-help-heading label="発注点" align="right" /></th>
                        <th class="whitespace-nowrap px-2 py-1.5 text-center font-semibold"><x-order-registration.column-help-heading label="予定日" align="center" /></th>
                        <th class="logistics-candidate-order-qty whitespace-nowrap border-l-2 border-slate-300 bg-amber-100 px-1 py-1.5 text-right font-semibold text-amber-900 dark:border-slate-600 dark:bg-amber-900/40 dark:text-amber-100"><x-order-registration.column-help-heading label="ケース" align="right" /></th>
                        <th class="logistics-candidate-order-qty whitespace-nowrap bg-amber-100 px-1 py-1.5 text-right font-semibold text-amber-900 dark:bg-amber-900/40 dark:text-amber-100"><x-order-registration.column-help-heading label="バラ" align="right" /></th>
                        <th class="whitespace-nowrap px-2 py-1.5 text-right font-semibold"><x-order-registration.column-help-heading label="理論在庫" align="right" /></th>
                        <th class="whitespace-nowrap px-2 py-1.5 text-right font-semibold"><x-order-registration.column-help-heading label="見込在庫" align="right" /></th>
                        <th class="whitespace-nowrap px-2 py-1.5 text-right font-semibold"><x-order-registration.column-help-heading label="納品予定数" align="right" /></th>
                        <th class="whitespace-nowrap px-2 py-1.5 text-center font-semibold"><x-order-registration.column-help-heading label="納品予定日" align="center" /></th>
                        <th class="whitespace-nowrap px-2 py-1.5 text-right font-semibold"><x-order-registration.column-help-heading label="最終発注日" align="right" /></th>
                        <th class="whitespace-nowrap px-2 py-1.5 text-right font-semibold"><x-order-registration.column-help-heading label="1週" align="right" /></th>
                        <th class="whitespace-nowrap px-2 py-1.5 text-right font-semibold"><x-order-registration.column-help-heading label="2週" align="right" /></th>
                        <th class="whitespace-nowrap px-2 py-1.5 text-right font-semibold"><x-order-registration.column-help-heading label="3週" align="right" /></th>
                        <th class="whitespace-nowrap px-2 py-1.5 text-right font-semibold"><x-order-registration.column-help-heading label="前月" align="right" /></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 dark:divide-slate-800">
                    <template x-for="(row, index) in rows" :key="row.warehouse_id + '-' + row.item_code + '-' + row.contractor_id + '-' + index">
                        <tr
                            :class="isEosUnavailable(row)
                                ? 'bg-red-50 dark:bg-red-950/30'
                                : 'bg-white dark:bg-slate-900'"
                            class="hover:bg-amber-50 dark:hover:bg-amber-950/30"
                        >
                            <td class="whitespace-nowrap px-2 py-1.5 text-slate-700 dark:text-slate-200">
                                <span x-show="channelControlled && row.is_eos_available === false" class="inline-flex rounded bg-red-100 px-1.5 py-0.5 text-[10px] font-bold text-red-700 dark:bg-red-900/40 dark:text-red-200">EOS不可</span>
                                <span x-show="channelControlled && row.is_eos_available === true" class="inline-flex rounded bg-blue-100 px-1.5 py-0.5 text-[10px] font-bold text-blue-700 dark:bg-blue-900/40 dark:text-blue-200">EOS可</span>
                                <span class="block" x-text="row.supplier_name || row.contractor_name || '-'"></span>
                            </td>
                            <td class="whitespace-nowrap px-1 py-1.5 font-mono text-slate-700 dark:text-slate-200" x-text="row.item_category2_code || '-'"></td>
                            <td class="whitespace-nowrap px-2 py-1.5 font-mono text-slate-700 dark:text-slate-200" x-text="row.item_code"></td>
                            <td
                                class="logistics-candidate-item-name px-2 py-1.5 font-medium text-slate-900 dark:text-white"
                                style="width: 390px !important; min-width: 390px !important; max-width: 390px !important;"
                            >
                                <span
                                    class="block cursor-help truncate"
                                    x-text="row.item_name"
                                    x-on:mouseenter="showItemNameTooltip($event, row.item_name)"
                                    x-on:mousemove="updateItemNameTooltipPosition($event)"
                                    x-on:mouseleave="hoveredItemName = null"
                                ></span>
                            </td>
                            <td class="whitespace-nowrap px-2 py-1.5 text-slate-600 dark:text-slate-300" x-text="row.item_packaging || '-'"></td>
                            <td class="whitespace-nowrap px-2 py-1.5 font-mono text-slate-700 dark:text-slate-200" x-text="row.default_location_code || '-'"></td>
                            <td class="whitespace-nowrap px-2 py-1.5 text-right font-mono text-slate-700 dark:text-slate-200" x-text="formatNumber(row.safety_stock)"></td>
                            <td class="whitespace-nowrap px-1 py-1.5 text-center">
                                <input
                                    type="date"
                                    :value="rowExpectedArrivalDateFor(row)"
                                    :min="today"
                                    x-bind:disabled="isRowDisabled(row)"
                                    x-on:change="setRowExpectedArrivalDate(row, $event.target.value)"
                                    class="w-[128px] rounded-md border border-slate-300 bg-white px-1 py-0.5 text-xs font-mono text-slate-900 shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-200 disabled:border-slate-200 disabled:bg-slate-100 disabled:text-slate-400 dark:border-slate-600 dark:bg-gray-900 dark:text-gray-100 dark:focus:border-primary-500 dark:focus:ring-primary-900 dark:disabled:border-slate-700 dark:disabled:bg-slate-800"
                                >
                            </td>
                            <td class="logistics-candidate-order-qty whitespace-nowrap border-l-2 border-slate-300 bg-amber-50 px-1 py-1.5 text-right dark:border-slate-600 dark:bg-amber-950/30">
                                <input
                                    type="text"
                                    inputmode="numeric"
                                    pattern="[0-9]*"
                                    autocomplete="off"
                                    x-model="row.input_order_case_qty"
                                    x-bind:disabled="isRowDisabled(row) || Number(row.input_order_piece_qty || 0) > 0"
                                    x-on:focus="$event.target.select()"
                                    x-on:input.debounce.150ms="cleanQuantity(row, 'input_order_case_qty', 'input_order_piece_qty'); sync()"
                                    x-on:blur="commitQuantity(row, 'input_order_case_qty', 'input_order_piece_qty')"
                                    x-on:change="commitQuantity(row, 'input_order_case_qty', 'input_order_piece_qty')"
                                    x-on:keydown.arrow-up.prevent="commitQuantity(row, 'input_order_case_qty', 'input_order_piece_qty'); focusAdjacentInput($event, 'up')"
                                    x-on:keydown.arrow-down.prevent="commitQuantity(row, 'input_order_case_qty', 'input_order_piece_qty'); focusAdjacentInput($event, 'down')"
                                    x-on:keydown.arrow-left.prevent="commitQuantity(row, 'input_order_case_qty', 'input_order_piece_qty'); focusAdjacentInput($event, 'left')"
                                    x-on:keydown.arrow-right.prevent="commitQuantity(row, 'input_order_case_qty', 'input_order_piece_qty'); focusAdjacentInput($event, 'right')"
                                    x-on:keydown.enter.prevent="commitQuantity(row, 'input_order_case_qty', 'input_order_piece_qty'); focusNextInput($event)"
                                    x-on:keydown.tab.prevent="commitQuantity(row, 'input_order_case_qty', 'input_order_piece_qty'); focusNextInput($event)"
                                    data-order-quantity-input
                                    class="w-12 rounded-md border-2 border-amber-300 bg-white px-1 py-0.5 text-right text-sm font-semibold text-slate-900 shadow-sm focus:border-amber-500 focus:ring-2 focus:ring-amber-200 disabled:border-slate-200 disabled:bg-slate-100 disabled:text-slate-400 dark:border-amber-700 dark:bg-gray-900 dark:text-gray-100 dark:focus:border-amber-500 dark:focus:ring-amber-900 dark:disabled:border-slate-700 dark:disabled:bg-slate-800"
                                >
                            </td>
                            <td class="logistics-candidate-order-qty whitespace-nowrap bg-amber-50 px-1 py-1.5 text-right dark:bg-amber-950/30">
                                <input
                                    type="text"
                                    inputmode="numeric"
                                    pattern="[0-9]*"
                                    autocomplete="off"
                                    x-model="row.input_order_piece_qty"
                                    x-bind:disabled="isRowDisabled(row) || Number(row.input_order_case_qty || 0) > 0"
                                    x-on:focus="$event.target.select()"
                                    x-on:input.debounce.150ms="cleanQuantity(row, 'input_order_piece_qty', 'input_order_case_qty'); sync()"
                                    x-on:blur="commitQuantity(row, 'input_order_piece_qty', 'input_order_case_qty')"
                                    x-on:change="commitQuantity(row, 'input_order_piece_qty', 'input_order_case_qty')"
                                    x-on:keydown.arrow-up.prevent="commitQuantity(row, 'input_order_piece_qty', 'input_order_case_qty'); focusAdjacentInput($event, 'up')"
                                    x-on:keydown.arrow-down.prevent="commitQuantity(row, 'input_order_piece_qty', 'input_order_case_qty'); focusAdjacentInput($event, 'down')"
                                    x-on:keydown.arrow-left.prevent="commitQuantity(row, 'input_order_piece_qty', 'input_order_case_qty'); focusAdjacentInput($event, 'left')"
                                    x-on:keydown.arrow-right.prevent="commitQuantity(row, 'input_order_piece_qty', 'input_order_case_qty'); focusAdjacentInput($event, 'right')"
                                    x-on:keydown.enter.prevent="commitQuantity(row, 'input_order_piece_qty', 'input_order_case_qty'); focusNextInput($event)"
                                    x-on:keydown.tab.prevent="commitQuantity(row, 'input_order_piece_qty', 'input_order_case_qty'); focusNextInput($event)"
                                    data-order-quantity-input
                                    class="w-12 rounded-md border-2 border-amber-300 bg-white px-1 py-0.5 text-right text-sm font-semibold text-slate-900 shadow-sm focus:border-amber-500 focus:ring-2 focus:ring-amber-200 disabled:border-slate-200 disabled:bg-slate-100 disabled:text-slate-400 dark:border-amber-700 dark:bg-gray-900 dark:text-gray-100 dark:focus:border-amber-500 dark:focus:ring-amber-900 dark:disabled:border-slate-700 dark:disabled:bg-slate-800"
                                >
                            </td>
                            <td class="whitespace-nowrap px-2 py-1.5 text-right">
                                <button
                                    type="button"
                                    x-on:click.stop="$wire.openWarehouseStockModal(Number(row.item_id || 0))"
                                    title="他倉庫在庫を見る"
                                    class="inline-flex min-w-14 justify-end rounded-md border border-sky-300 bg-sky-50 px-2 py-0.5 font-mono text-sm font-bold text-sky-800 shadow-sm hover:border-sky-500 hover:bg-sky-100 focus:outline-none focus:ring-2 focus:ring-sky-200 dark:border-sky-700 dark:bg-sky-950/40 dark:text-sky-200 dark:hover:border-sky-500 dark:hover:bg-sky-900/50"
                                    x-text="formatNumber(row.effective_stock)"
                                ></button>
                            </td>
                            <td class="whitespace-nowrap px-2 py-1.5 text-right font-mono text-slate-700 dark:text-slate-200" x-text="formatNumber(row.projected_stock)"></td>
                            <td class="whitespace-nowrap px-2 py-1.5 text-right font-mono text-slate-700 dark:text-slate-200" x-text="formatNumber(row.incoming_qty)"></td>
                            <td class="whitespace-nowrap px-2 py-1.5 text-center font-mono text-slate-700 dark:text-slate-200" x-text="row.incoming_expected_arrival_date || '-'"></td>
                            <td class="whitespace-nowrap px-2 py-1.5 text-right font-mono text-slate-700 dark:text-slate-200" x-text="row.last_order_date || '-'"></td>
                            <td class="whitespace-nowrap px-2 py-1.5 text-right font-mono text-slate-700 dark:text-slate-200" x-text="formatNumber(row.sales_week1_qty)"></td>
                            <td class="whitespace-nowrap px-2 py-1.5 text-right font-mono text-slate-700 dark:text-slate-200" x-text="formatNumber(row.sales_week2_qty)"></td>
                            <td class="whitespace-nowrap px-2 py-1.5 text-right font-mono text-slate-700 dark:text-slate-200" x-text="formatNumber(row.sales_week3_qty)"></td>
                            <td class="whitespace-nowrap px-2 py-1.5 text-right font-mono text-slate-700 dark:text-slate-200" x-text="formatNumber(row.previous_month_sales_qty)"></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>
</div>
