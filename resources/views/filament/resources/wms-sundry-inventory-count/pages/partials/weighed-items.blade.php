{{-- 量り売り明細（実棚はカメ・QT の2欄。数量は 100ml 単位） --}}
<table class="w-max min-w-full border-collapse text-xs">
    <thead class="sticky top-0 z-10 bg-slate-100 text-slate-700">
        <tr>
            <th class="{{ $thClass }} text-left">商品CD</th>
            <th class="{{ $thClass }} text-left">商品名</th>
            <th class="{{ $thClass }} text-right">仕入単価</th>
            <th class="{{ $thClass }} text-right">理論在庫数</th>
            <th class="{{ $thClass }} text-right">実棚数（カメ）</th>
            <th class="{{ $thClass }} text-right">実棚数（QT）</th>
            <th class="{{ $thClass }} text-right">実棚計</th>
            <th class="{{ $thClass }} text-right">差異数</th>
            <th class="{{ $thClass }} text-right">差異金額</th>
            <th class="{{ $thClass }} text-right">期間売上数量</th>
            <th class="{{ $thClass }} text-right">ロス数量</th>
            <th class="{{ $thClass }} text-left">入力者</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($rows->items() as $row)
            @php
                $initJar = $page->inputValue($row->counted_quantity_jar);
                $initReserve = $page->inputValue($row->counted_quantity_reserve);
            @endphp
            <tr wire:key="sundry-weighed-{{ $row->id }}-u{{ $row->updated_at?->timestamp ?? 0 }}"
                x-data="{
                    jar: @js($initJar),
                    originalJar: @js($initJar),
                    reserve: @js($initReserve),
                    originalReserve: @js($initReserve),
                    system: {{ (float) $row->system_quantity }},
                    cost: {{ (float) $row->cost_price }},
                    get total() {
                        let jar = toNum(this.jar);
                        let reserve = toNum(this.reserve);
                        if (jar === null && reserve === null) return null;
                        return Math.round(((jar ?? 0) + (reserve ?? 0)) * 1000) / 1000;
                    },
                    get diff() {
                        return this.total === null ? null : Math.round((this.total - this.system) * 1000) / 1000;
                    },
                    get diffAmount() {
                        return this.diff === null ? null : Math.round(this.diff * this.cost);
                    },
                    get changed() {
                        return this.jar !== this.originalJar || this.reserve !== this.originalReserve;
                    },
                    notify() {
                        let fields = {};
                        if (this.jar !== this.originalJar) fields.jar = this.jar === '' ? null : this.jar;
                        if (this.reserve !== this.originalReserve) fields.reserve = this.reserve === '' ? null : this.reserve;
                        $dispatch('sundry-weighed-change', { id: {{ $row->id }}, fields: fields });
                    }
                }"
                :class="changed ? 'bg-amber-50' : ($el.rowIndex % 2 === 0 ? 'bg-white' : 'bg-slate-50')"
                class="hover:bg-sky-50">
                <td class="{{ $tdClass }} font-mono">{{ $row->item_code ?: '-' }}</td>
                <td class="min-w-[260px] border border-slate-300 px-2 py-1">
                    {{ $row->item_name ?: '-' }}
                    @if ($row->is_additional)
                        <span class="ml-1 rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-bold text-amber-700">追加</span>
                    @endif
                </td>
                <td class="{{ $tdClass }} text-right tabular-nums">{{ number_format((float) $row->cost_price, 2) }}</td>
                <td class="{{ $tdClass }} text-right font-bold tabular-nums text-slate-700">{{ $page->formatQuantity($row->system_quantity) }}</td>
                @if ($isEditable)
                    <td class="whitespace-nowrap border border-slate-300 px-1 py-0.5" @click.stop>
                        <input type="text" inputmode="decimal" data-weighed-col="jar"
                            :value="jar"
                            @input="jar = clean($event.target.value); $event.target.value = jar; notify()"
                            @keydown.enter.prevent="$el.closest('tr').nextElementSibling?.querySelector('input[data-weighed-col=jar]')?.focus()"
                            class="{{ $countInputClass }}" placeholder="-">
                    </td>
                    <td class="whitespace-nowrap border border-slate-300 px-1 py-0.5" @click.stop>
                        <input type="text" inputmode="decimal" data-weighed-col="reserve"
                            :value="reserve"
                            @input="reserve = clean($event.target.value); $event.target.value = reserve; notify()"
                            @keydown.enter.prevent="$el.closest('tr').nextElementSibling?.querySelector('input[data-weighed-col=reserve]')?.focus()"
                            class="{{ $countInputClass }}" placeholder="-">
                    </td>
                    <td class="{{ $tdClass }} text-right font-bold tabular-nums"
                        x-text="total !== null ? formatNumber(total, 3) : '-'"></td>
                    <td class="{{ $tdClass }} text-right font-bold tabular-nums"
                        :class="{ 'text-green-700': diff > 0, 'text-red-700': diff < 0 }"
                        x-text="diff !== null ? formatNumber(diff, 3) : '-'"></td>
                    <td class="{{ $tdClass }} text-right font-bold tabular-nums"
                        :class="{ 'text-green-700': diffAmount > 0, 'text-red-700': diffAmount < 0 }"
                        x-text="diffAmount !== null ? '¥' + formatNumber(diffAmount) : '-'"></td>
                @else
                    <td class="{{ $tdClass }} text-right font-bold tabular-nums">{{ $page->formatQuantity($row->counted_quantity_jar) }}</td>
                    <td class="{{ $tdClass }} text-right font-bold tabular-nums">{{ $page->formatQuantity($row->counted_quantity_reserve) }}</td>
                    <td class="{{ $tdClass }} text-right font-bold tabular-nums">{{ $page->formatQuantity($row->counted_quantity) }}</td>
                    <td class="{{ $tdClass }} text-right font-bold tabular-nums {{ $signClass($row->difference_quantity) }}">{{ $page->formatQuantity($row->difference_quantity) }}</td>
                    <td class="{{ $tdClass }} text-right font-bold tabular-nums {{ $signClass($row->difference_amount) }}">{{ $page->formatAmount($row->difference_amount) }}</td>
                @endif
                <td class="{{ $tdClass }} text-right tabular-nums">{{ $page->formatQuantity($row->period_sales_quantity) }}</td>
                <td class="{{ $tdClass }} text-right tabular-nums">{{ $row->period_sales_quantity === null ? '-' : $page->formatQuantity($page->weighedLossQuantity($row->period_sales_quantity)) }}</td>
                <td class="{{ $tdClass }} text-slate-600">{{ $row->counted_by_name ?: '-' }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
