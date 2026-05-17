@php
    $jsonItems = json_encode($items ?? [], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
@endphp

<div wire:ignore>
    <textarea x-ref="jsonData" class="hidden">{!! $jsonItems !!}</textarea>

    <div
        x-data="{
            items: [],
            taskCount: 0,
            changes: {},
            statePath: '{{ $getStatePath() }}',

            get changeCount() {
                return Object.keys(this.changes).length;
            },

            get hasChanges() {
                return this.changeCount > 0;
            },

            getPlannedQty(item) {
                return this.changes[item.id]?.planned_qty ?? item.planned_qty;
            },

            updatePlannedQty(item, value) {
                const val = parseInt(value, 10);
                if (isNaN(val) || val < 0) return;

                const capacityCase = Math.max(1, item.capacity_case || 1);
                const plannedPieces = item.planned_qty_type === 'CASE' ? val * capacityCase : val;
                const orderedPieces = item.ordered_qty_type === 'CASE' ? item.ordered_qty * capacityCase : item.ordered_qty;

                if (plannedPieces > orderedPieces) return;

                if (val === item.planned_qty) {
                    delete this.changes[item.id];
                } else {
                    this.changes[item.id] = { planned_qty: val };
                }

                this.syncToLivewire();
            },

            calcShortage(item) {
                const plannedQty = this.getPlannedQty(item);
                const capacityCase = Math.max(1, item.capacity_case || 1);
                const plannedPieces = item.planned_qty_type === 'CASE' ? plannedQty * capacityCase : plannedQty;
                const orderedPieces = item.ordered_qty_type === 'CASE' ? item.ordered_qty * capacityCase : item.ordered_qty;
                return Math.max(0, orderedPieces - plannedPieces);
            },

            isChanged(item) {
                return this.changes.hasOwnProperty(item.id);
            },

            isResolved(item) {
                return this.calcShortage(item) === 0;
            },

            maxPlannedQty(item) {
                const capacityCase = Math.max(1, item.capacity_case || 1);
                const orderedPieces = item.ordered_qty_type === 'CASE' ? item.ordered_qty * capacityCase : item.ordered_qty;
                if (item.planned_qty_type === 'CASE') {
                    return Math.floor(orderedPieces / capacityCase);
                }
                return orderedPieces;
            },

            syncToLivewire() {
                if (this.statePath && this.$wire) {
                    this.$wire.set(this.statePath, JSON.stringify(this.changes));
                }
            }
        }"
        x-init="
            const ta = $el.parentElement.querySelector('[x-ref=jsonData]');
            if (ta) {
                items = JSON.parse(ta.value);
                taskCount = [...new Set(items.map(i => i.picking_task_id))].length;
            }
            syncToLivewire();
        "
        class="space-y-3"
    >
        {{-- Summary --}}
        <div class="flex items-center justify-between px-3 py-2 rounded-lg bg-slate-50 dark:bg-slate-800 border border-slate-200 dark:border-slate-700">
            <div class="flex items-center gap-4 text-xs text-slate-600 dark:text-slate-300">
                <span>対象タスク: <span class="font-bold" x-text="taskCount"></span>件</span>
                <span>引当欠品明細: <span class="font-bold" x-text="items.length"></span>件</span>
            </div>
            <div class="text-xs">
                <span class="text-slate-400 dark:text-slate-500">変更件数: </span>
                <span
                    class="font-bold"
                    :class="hasChanges ? 'text-amber-600 dark:text-amber-400' : 'text-slate-400 dark:text-slate-500'"
                    x-text="changeCount + '件'"
                ></span>
            </div>
        </div>

        <template x-if="items.length === 0">
            <div class="flex flex-col items-center justify-center py-12 text-slate-400 dark:text-slate-500">
                <svg class="w-10 h-10 mb-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                </svg>
                <p class="text-sm">引当欠品の明細はありません</p>
            </div>
        </template>

        <template x-if="items.length > 0">
            <div class="overflow-auto border rounded-lg dark:border-gray-700" style="max-height: 60vh;">
                <table class="min-w-max text-xs text-left text-gray-600 dark:text-gray-300">
                    <thead class="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-gray-700 dark:text-gray-400 sticky top-0 z-10">
                        <tr>
                            <th class="px-2 py-1.5 whitespace-nowrap">タスクID</th>
                            <th class="px-2 py-1.5 whitespace-nowrap">担当営業</th>
                            <th class="px-2 py-1.5 whitespace-nowrap">得意先名</th>
                            <th class="px-2 py-1.5 whitespace-nowrap">伝票番号</th>
                            <th class="px-2 py-1.5 whitespace-nowrap">棚番</th>
                            <th class="px-2 py-1.5 whitespace-nowrap">商品CD</th>
                            <th class="px-2 py-1.5">商品名</th>
                            <th class="px-2 py-1.5 text-center whitespace-nowrap">入り数</th>
                            <th class="px-2 py-1.5 text-center whitespace-nowrap">受注数</th>
                            <th class="px-2 py-1.5 text-center whitespace-nowrap">受注区分</th>
                            <th class="px-2 py-1.5 text-center whitespace-nowrap">引当数</th>
                            <th class="px-2 py-1.5 text-center whitespace-nowrap">引当区分</th>
                            <th class="px-2 py-1.5 text-center whitespace-nowrap">欠品数</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(item, index) in items" :key="item.id">
                            <tr
                                class="border-b dark:border-gray-700 transition-colors"
                                :class="{
                                    'bg-green-50 dark:bg-green-900/20': isResolved(item),
                                    'bg-amber-50 dark:bg-amber-900/20': isChanged(item) && !isResolved(item),
                                    'bg-[#f5f9ff] dark:bg-[#1e2a3b]': !isChanged(item) && !isResolved(item) && index % 2 === 0,
                                    'bg-white dark:bg-gray-800': !isChanged(item) && !isResolved(item) && index % 2 === 1
                                }"
                            >
                                <td class="px-2 py-1 text-center font-mono" x-text="item.picking_task_id"></td>
                                <td class="px-2 py-1 whitespace-nowrap" x-text="item.sales_man"></td>
                                <td class="px-2 py-1 whitespace-nowrap max-w-[200px] truncate" x-text="item.partner_name" :title="item.partner_name"></td>
                                <td class="px-2 py-1 text-center font-mono" x-text="item.serial_id"></td>
                                <td class="px-2 py-1 font-mono whitespace-nowrap" x-text="item.location_display"></td>
                                <td class="px-2 py-1 font-mono" x-text="item.item_code"></td>
                                <td class="px-2 py-1" x-text="item.item_name"></td>
                                <td class="px-2 py-1 text-center" x-text="item.capacity_case"></td>
                                <td class="px-2 py-1 text-center font-bold" x-text="item.ordered_qty"></td>
                                <td class="px-2 py-1 text-center" x-text="item.ordered_qty_type_label"></td>
                                <td class="px-2 py-1 text-center">
                                    <input
                                        type="number"
                                        :value="getPlannedQty(item)"
                                        @input="updatePlannedQty(item, $event.target.value)"
                                        @focus="$event.target.select()"
                                        @wheel.prevent
                                        :max="maxPlannedQty(item)"
                                        min="0"
                                        step="1"
                                        inputmode="numeric"
                                        class="w-16 h-7 p-0 text-center text-xs border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded focus:border-primary-500 focus:ring-primary-500"
                                    />
                                </td>
                                <td class="px-2 py-1 text-center" x-text="item.planned_qty_type_label"></td>
                                <td class="px-2 py-1 text-center font-bold"
                                    :class="calcShortage(item) > 0 ? 'text-red-600 dark:text-red-400' : 'text-green-600 dark:text-green-400'"
                                    x-text="calcShortage(item) > 0 ? calcShortage(item) : '-'"
                                ></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </template>

        <p class="text-xs text-slate-400 dark:text-slate-500">
            ※ 引当数を変更したい明細の数量を編集してください。受注数を超える値は入力できません。
        </p>
    </div>
</div>
