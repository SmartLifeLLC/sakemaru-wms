@php $displayValuations = $this->valuationRows(true); @endphp
@if ($displayValuations->isEmpty())
    <div class="p-8 text-center text-sm text-slate-500">商品数量明細はありません。</div>
@else
    <table class="w-max min-w-full border-collapse text-xs">
        <thead class="sticky top-0 z-10 bg-slate-100 text-slate-700">
            <tr>
                @foreach (['中分類CD', '中分類名', '商品CD', '商品名', '価格基準日', '販売単価（税抜）', '分類原価率(%)', '評価単価', '実棚数（バラ）', '実棚評価額', '入力者'] as $heading)
                    <th class="{{ $thClass }}">{{ $heading }}</th>
                @endforeach
            </tr>
        </thead>
        <tbody>
            @foreach ($displayValuations as $row)
                @php
                    $category = $amountRows->firstWhere('id', $row->amount_id);
                    $value = $this->inputValue($row->quantity);
                @endphp
                <tr wire:key="sundry-valuation-{{ $row->id }}-{{ $row->revision }}"
                    x-data="{ counted: @js($value), original: @js($value) }"
                    :class="counted !== original ? 'bg-amber-50' : ($el.rowIndex % 2 === 0 ? 'bg-white' : 'bg-slate-50')">
                    <td class="{{ $tdClass }}">{{ $category?->category2_code }}</td>
                    <td class="{{ $tdClass }}">{{ $category?->category2_name }}</td>
                    <td class="{{ $tdClass }} font-mono">{{ $row->item_code }}</td>
                    <td class="{{ $tdClass }} min-w-[240px]">{{ $row->item_name }}</td>
                    <td class="{{ $tdClass }}">{{ $row->price_date?->format('Y/m/d') }}</td>
                    <td class="{{ $tdClass }} text-right tabular-nums">{{ $row->net_sale_price === null ? '未設定' : number_format((float) $row->net_sale_price, 2) }}</td>
                    <td class="{{ $tdClass }} text-right tabular-nums">{{ $row->cost_rate === null ? '未設定' : number_format((float) $row->cost_rate, 2) }}</td>
                    <td class="{{ $tdClass }} text-right tabular-nums">{{ $row->valuation_unit_price === null ? '未設定' : number_format((float) $row->valuation_unit_price, 2) }}</td>
                    <td class="{{ $tdClass }} text-right">
                        @if ($isEditable)
                            <input type="text" inputmode="decimal" :value="counted" class="{{ $countInputClass }}" placeholder="-"
                                aria-label="商品{{ $row->item_code }} 実棚数"
                                @input="counted = clean($event.target.value); $event.target.value = counted;
                                    $dispatch('sundry-valuation-change', { id: {{ $row->id }}, value: counted, changed: counted !== original, revision: {{ $row->revision }} });"
                                @keydown.enter.prevent="$el.closest('tr').nextElementSibling?.querySelector('input')?.focus()">
                        @else
                            {{ $this->formatQuantity($row->quantity) }}
                        @endif
                    </td>
                    <td class="{{ $tdClass }} text-right tabular-nums">{{ $row->amount === null ? '-' : number_format((float) $row->amount, 2) }}</td>
                    <td class="{{ $tdClass }}">{{ $row->counted_by_name }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
