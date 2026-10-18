@php $displayValuations = $this->valuationRows(true); @endphp
@if ($displayValuations->isEmpty())
    <div class="p-8 text-center text-sm text-slate-500">
        {{ $valuationRows->isEmpty() ? '数量明細は未準備です。' : '検索条件に一致する商品はありません。' }}
        @if ($isEditable && $valuationRows->isEmpty())
            <button type="button" @click="guard(() => $wire.mountAction('prepareValuation'))"
                class="mx-2 inline-flex items-center gap-1 rounded border border-slate-300 px-3 py-2 font-bold text-slate-700">
                <x-filament::icon icon="heroicon-o-calculator" class="h-4 w-4" />
                数量入力を準備
            </button>
        @endif
    </div>
@else
    <div wire:key="valuation-total-{{ hash('sha256', $valuationRows->map(fn ($row) => [$row->id, $row->revision])->toJson()) }}"
        x-data="{
            savedAmount: @js((float) $valuationRows->sum('amount')), savedCount: {{ $valuationRows->whereNotNull('quantity')->count() }},
            get invalid() { return Object.values(valuationPreviews).some(change => change.error); },
            get previewAmount() {
                return roundValue(this.savedAmount + Object.values(valuationPreviews).reduce((total, change) => total + (change.amount ?? 0) - (change.originalAmount ?? 0), 0));
            },
            get enteredCount() {
                return this.savedCount + Object.values(valuationPreviews).reduce((total, change) => total + (change.value === '' || change.error ? 0 : 1) - (change.originalQuantity === null ? 0 : 1), 0);
            }
        }"
        class="flex flex-wrap items-center gap-4 border-b border-slate-200 bg-slate-50 px-3 py-2 text-xs font-bold text-slate-700">
        <span>入力済商品数 <span x-text="enteredCount"></span> / {{ $valuationRows->count() }}</span>
        <span>未入力 <span x-text="{{ $valuationRows->count() }} - enteredCount"></span></span>
        <span>入力済み実棚評価額合計 <span x-text="invalid ? '入力内容を確認' : (enteredCount ? money(previewAmount) : '-')"></span></span>
    </div>
    <table class="w-max min-w-full border-collapse text-xs">
        <thead class="sticky top-0 z-10 bg-slate-100 text-slate-700">
            <tr>
                @foreach (['中分類CD', '中分類名', '商品CD', '商品名', '実棚数（バラ）', '実棚評価額', '販売単価（税抜）', '分類原価率(%)', '評価単価', '在庫管理', '評価方法', '価格基準日', '入力者'] as $heading)
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
                    x-data="{
                        counted: @js($value), original: @js($value), unitPrice: @js($row->valuation_unit_price === null ? null : (float) $row->valuation_unit_price),
                        get error() {
                            if (this.counted === '') return '';
                            if (!/^\d{1,9}(?:\.\d{1,3})?$/.test(this.counted)) return '数量を確認';
                            if (toNum(this.counted) > 0 && (this.unitPrice === null || this.unitPrice <= 0)) return '単価・原価率未設定';
                            if (toNum(this.counted) * this.unitPrice > 9999999999999.99) return '評価額上限超過';
                            return '';
                        },
                        get amount() {
                            const quantity = toNum(this.counted);
                            return quantity === null || this.error ? null : roundValue(quantity * (this.unitPrice ?? 0));
                        }
                    }"
                    :class="counted !== original ? 'bg-amber-50' : ($el.rowIndex % 2 === 0 ? 'bg-white' : 'bg-slate-50')">
                    <td class="{{ $tdClass }}">{{ $category?->category2_code }}</td>
                    <td class="{{ $tdClass }}">{{ $category?->category2_name }}</td>
                    <td class="{{ $tdClass }} font-mono">{{ $row->item_code }}</td>
                    <td class="{{ $tdClass }} min-w-[240px]">{{ $row->item_name }}</td>
                    <td class="{{ $tdClass }} text-right">
                        @if ($isEditable)
                            <input type="text" inputmode="decimal" :value="counted" class="{{ $countInputClass }}" placeholder="-"
                                aria-label="商品{{ $row->item_code }} 実棚数"
                                @input="counted = clean($event.target.value); $event.target.value = counted;
                                    $dispatch('sundry-valuation-change', { id: {{ $row->id }}, value: counted, changed: counted !== original, revision: {{ $row->revision }}, amount: amount, error: error, originalAmount: @js($row->amount === null ? null : (float) $row->amount), originalQuantity: @js($row->quantity) });"
                                @keydown.enter.prevent="$el.closest('tr').nextElementSibling?.querySelector('input')?.focus()">
                        @else
                            {{ $this->formatQuantity($row->quantity) }}
                        @endif
                    </td>
                    <td class="{{ $tdClass }} text-right tabular-nums">
                        @if ($isEditable)
                            <span x-text="error || money(amount)" :class="error ? 'text-red-700' : ''"></span>
                        @else
                            {{ $this->formatAmount($row->amount, 2) }}
                        @endif
                    </td>
                    <td class="{{ $tdClass }} text-right tabular-nums">{{ $row->net_sale_price === null ? '未設定' : $this->inputValue($row->net_sale_price, 6) }}</td>
                    <td class="{{ $tdClass }} text-right tabular-nums">{{ $row->cost_rate === null ? '未設定' : $this->inputValue($row->cost_rate, 4) }}</td>
                    <td class="{{ $tdClass }} text-right tabular-nums">{{ $row->valuation_unit_price === null ? '未設定' : $this->inputValue($row->valuation_unit_price, 6) }}</td>
                    <td class="{{ $tdClass }}">{{ $row->item?->is_managed_stock === null ? '不明' : ($row->item->is_managed_stock ? '対象' : '対象外') }}</td>
                    <td class="{{ $tdClass }}">数量 × 税抜売価 × 原価率</td>
                    <td class="{{ $tdClass }}">{{ $row->price_date?->format('Y/m/d') }}</td>
                    <td class="{{ $tdClass }}">{{ $row->counted_by_name }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
@endif
