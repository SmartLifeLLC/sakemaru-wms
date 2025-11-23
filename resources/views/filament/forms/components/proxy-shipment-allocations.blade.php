<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    <div x-data="{
        state: $wire.entangle('{{ $getStatePath() }}'),
        shortageQty: {{ $shortage_qty }},
        stocks: {{ json_encode($stocks) }},
        
        get allocatedQty() {
            return (this.state || []).reduce((sum, item) => sum + (parseInt(item.assign_qty) || 0), 0);
        },

        get remainingQty() {
            return Math.max(0, this.shortageQty - this.allocatedQty);
        },

        addAllocation(warehouseId, qty) {
            if (qty <= 0) {
                alert('残欠品数が0のため追加できません。');
                return;
            }
            
            this.state = this.state || [];
            
            // 重複チェック
            if (this.state.find(item => item.from_warehouse_id == warehouseId)) {
                alert('この倉庫は既に選択されています。');
                return;
            }

            this.state.push({
                from_warehouse_id: warehouseId,
                assign_qty: qty,
                assign_qty_type: '{{ $qty_type }}',
            });
        },

        removeAllocation(index) {
            this.state.splice(index, 1);
        },

        validateQty(index) {
            const item = this.state[index];
            let val = parseInt(item.assign_qty);
            
            if (isNaN(val) || val < 0) {
                val = 0;
            }

            // 他の行の合計を計算
            const otherTotal = (this.state || []).reduce((sum, it, i) => {
                if (i === index) return sum;
                return sum + (parseInt(it.assign_qty) || 0);
            }, 0);

            // 上限チェック
            if (val + otherTotal > this.shortageQty) {
                val = Math.max(0, this.shortageQty - otherTotal);
            }

            item.assign_qty = val;
        },

        addManualAllocation() {
            this.state = this.state || [];
            this.state.push({
                from_warehouse_id: '',
                assign_qty: this.remainingQty > 0 ? this.remainingQty : 0,
                assign_qty_type: '{{ $qty_type }}',
            });
        }
    }">
        <!-- 商品情報テーブル -->
        <div class="mb-6 -mt-2">
            <table class="w-full border-collapse border border-gray-300 dark:border-gray-600 mb-1">
                <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <th class="border border-gray-300 dark:border-gray-600 px-2 py-1 text-sm font-semibold text-gray-900 dark:text-gray-100 text-center">商品コード</th>
                        <th class="border border-gray-300 dark:border-gray-600 px-2 py-1 text-sm font-semibold text-gray-900 dark:text-gray-100 text-center">商品名</th>
                        <th class="border border-gray-300 dark:border-gray-600 px-2 py-1 text-sm font-semibold text-gray-900 dark:text-gray-100 text-center">入り数</th>
                        <th class="border border-gray-300 dark:border-gray-600 px-2 py-1 text-sm font-semibold text-gray-900 dark:text-gray-100 text-center">容量</th>
                        <th class="border border-gray-300 dark:border-gray-600 px-2 py-1 text-sm font-semibold text-gray-900 dark:text-gray-100 text-center">得意先コード</th>
                        <th class="border border-gray-300 dark:border-gray-600 px-2 py-1 text-sm font-semibold text-gray-900 dark:text-gray-100 text-center">得意先名</th>
                    </tr>
                </thead>
                <tbody class="bg-white dark:bg-gray-900">
                    <tr>
                        <td class="border border-gray-300 dark:border-gray-600 px-2 py-1 text-sm text-center text-gray-700 dark:text-gray-300">{{ $item_code }}</td>
                        <td class="border border-gray-300 dark:border-gray-600 px-2 py-1 text-sm text-center text-gray-700 dark:text-gray-300">{{ $item_name }}</td>
                        <td class="border border-gray-300 dark:border-gray-600 px-2 py-1 text-sm text-center text-gray-700 dark:text-gray-300">{{ $capacity_case }}</td>
                        <td class="border border-gray-300 dark:border-gray-600 px-2 py-1 text-sm text-center text-gray-700 dark:text-gray-300">{{ $volume_value }}</td>
                        <td class="border border-gray-300 dark:border-gray-600 px-2 py-1 text-sm text-center text-gray-700 dark:text-gray-300">{{ $partner_code }}</td>
                        <td class="border border-gray-300 dark:border-gray-600 px-2 py-1 text-sm text-center text-gray-700 dark:text-gray-300">{{ $partner_name }}</td>
                    </tr>
                </tbody>
            </table>

            <table class="w-full border-collapse border border-gray-300 dark:border-gray-600">
                <thead class="bg-gray-50 dark:bg-gray-800">
                    <tr>
                        <th class="border border-gray-300 dark:border-gray-600 px-2 py-1 text-sm font-semibold text-gray-900 dark:text-gray-100 text-center">元倉庫</th>
                        <th class="border border-gray-300 dark:border-gray-600 px-2 py-1 text-sm font-semibold text-gray-900 dark:text-gray-100 text-center">受注単位</th>
                        <th class="border border-gray-300 dark:border-gray-600 px-2 py-1 text-sm font-semibold text-gray-900 dark:text-gray-100 text-center">受注数</th>
                        <th class="border border-gray-300 dark:border-gray-600 px-2 py-1 text-sm font-semibold text-gray-900 dark:text-gray-100 text-center">{{ $picked_qty_label ?? '引当数' }}</th>
                        <th class="border border-gray-300 dark:border-gray-600 px-2 py-1 text-sm font-semibold text-gray-900 dark:text-gray-100 text-center">欠品数</th>
                        <th class="border border-gray-300 dark:border-gray-600 px-2 py-1 text-sm font-semibold text-gray-900 dark:text-gray-100 text-center">横持ち出荷数</th>
                        <th class="border border-gray-300 dark:border-gray-600 px-2 py-1 text-sm font-semibold text-gray-900 dark:text-gray-100 text-center">残欠品数</th>
                        <th class="border border-gray-300 dark:border-gray-600 px-2 py-1 text-sm font-semibold text-gray-900 dark:text-gray-100 text-center">欠品内訳</th>
                    </tr>
                </thead>
                <tbody class="bg-white dark:bg-gray-900">
                    <tr>
                        <td class="border border-gray-300 dark:border-gray-600 px-2 py-1 text-sm text-center text-gray-700 dark:text-gray-300">{{ $warehouse_name }}</td>
                        <td class="border border-gray-300 dark:border-gray-600 px-2 py-1 text-sm text-center text-gray-700 dark:text-gray-300">{{ $qty_type_label }}</td>
                        <td class="border border-gray-300 dark:border-gray-600 px-2 py-1 text-sm text-center text-gray-700 dark:text-gray-300">{{ $order_qty }}</td>
                        <td class="border border-gray-300 dark:border-gray-600 px-2 py-1 text-sm text-center text-gray-700 dark:text-gray-300">{{ $picked_qty }}</td>
                        <td class="border border-gray-300 dark:border-gray-600 px-2 py-1 text-sm text-center text-gray-700 dark:text-gray-300">{{ $shortage_qty }}</td>
                        <td class="border border-gray-300 dark:border-gray-600 px-2 py-1 text-sm text-center text-blue-600 dark:text-blue-400" x-text="allocatedQty"></td>
                        <td class="border border-gray-300 dark:border-gray-600 px-2 py-1 text-sm text-center font-bold" :class="remainingQty > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-700 dark:text-gray-300'" x-text="remainingQty"></td>
                        <td class="border border-gray-300 dark:border-gray-600 px-2 py-1 text-sm text-center text-gray-700 dark:text-gray-300">{{ $shortage_details }}</td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- 在庫リスト -->
        <div class="mb-4 overflow-hidden rounded-lg border border-gray-300 dark:border-gray-600">
            <table class="w-full text-sm text-left rtl:text-right text-gray-500 dark:text-gray-400">
                <thead class="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-gray-700 dark:text-gray-400 border-b border-gray-200 dark:border-gray-600">
                    <tr>
                        <th class="px-4 py-3 border-r border-gray-200 dark:border-gray-600 last:border-r-0">倉庫名</th>
                        <th class="px-4 py-3 text-center border-r border-gray-200 dark:border-gray-600 last:border-r-0">ケース数</th>
                        <th class="px-4 py-3 text-center">総バラ数</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    <template x-for="stock in stocks" :key="stock.warehouse_id">
                        <tr 
                            class="bg-white border-b dark:bg-gray-800 dark:border-gray-700 hover:bg-blue-50 dark:hover:bg-blue-900 cursor-pointer transition-colors"
                            @click="addAllocation(stock.warehouse_id, remainingQty)"
                        >
                            <td class="px-4 py-2 border-r border-gray-200 dark:border-gray-700 last:border-r-0 text-gray-900 dark:text-gray-100" x-text="stock.warehouse_name"></td>
                            <td class="px-4 py-2 text-center border-r border-gray-200 dark:border-gray-700 last:border-r-0 text-gray-900 dark:text-gray-100 font-medium" x-text="new Intl.NumberFormat('ja-JP').format(stock.cases) + 'ケース'"></td>
                            <td class="px-4 py-2 text-center text-gray-900 dark:text-gray-100" x-text="new Intl.NumberFormat('ja-JP').format(stock.total_pieces) + 'バラ'"></td>
                        </tr>
                    </template>
                    <tr x-show="stocks.length === 0" class="bg-white dark:bg-gray-900">
                        <td colspan="3" class="px-4 py-4 text-center text-gray-500 dark:text-gray-400">
                            在庫のある倉庫がありません
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!-- 横持ち出荷指示リスト -->
        <div class="space-y-3">
            <div class="flex items-center justify-between">
                <div class="font-bold text-gray-700 dark:text-gray-300">横持ち出荷指示</div>
                <div class="text-sm">
                    残欠品数: <span class="font-bold" :class="remainingQty > 0 ? 'text-red-600' : 'text-green-600'" x-text="remainingQty"></span>
                </div>
            </div>

            <div class="overflow-hidden rounded-lg border border-gray-300 dark:border-gray-600">
                <table class="w-full text-sm text-left rtl:text-right text-gray-500 dark:text-gray-400">
                    <thead class="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-gray-700 dark:text-gray-400 border-b border-gray-200 dark:border-gray-600">
                        <tr>
                            <th class="px-4 py-3 border-r border-gray-200 dark:border-gray-600 last:border-r-0">横持ち出荷倉庫</th>
                            <th class="px-4 py-3 border-r border-gray-200 dark:border-gray-600 last:border-r-0 w-40">数量</th>
                            <th class="px-4 py-3 border-r border-gray-200 dark:border-gray-600 last:border-r-0 w-20">単位</th>
                            <th class="px-4 py-3 text-center w-16">削除</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700 bg-white dark:bg-gray-900">
                        <template x-for="(allocation, index) in state" :key="index">
                            <tr class="border-b dark:border-gray-700">
                                <td class="px-4 py-2 border-r border-gray-200 dark:border-gray-700 last:border-r-0">
                                    <select 
                                        x-model="allocation.from_warehouse_id" 
                                        class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-sm py-1"
                                    >
                                        <option value="">倉庫を選択</option>
                                        @foreach($warehouses as $id => $name)
                                            <option value="{{ $id }}">{{ $name }}</option>
                                        @endforeach
                                    </select>
                                </td>
                                <td class="px-4 py-2 border-r border-gray-200 dark:border-gray-700 last:border-r-0">
                                    <input 
                                        type="number" 
                                        x-model="allocation.assign_qty" 
                                        @input="validateQty(index)"
                                        min="0"
                                        class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-sm py-1"
                                    >
                                </td>
                                <td class="px-4 py-2 border-r border-gray-200 dark:border-gray-700 last:border-r-0 text-gray-600 dark:text-gray-400">
                                    {{ $qty_type_label }}
                                </td>
                                <td class="px-4 py-2 text-center">
                                    <button 
                                        type="button" 
                                        @click="removeAllocation(index)" 
                                        class="text-red-500 hover:text-red-700 p-1"
                                    >
                                        <x-heroicon-o-trash class="w-5 h-5" />
                                    </button>
                                </td>
                            </tr>
                        </template>
                        <tr x-show="!state || state.length === 0">
                            <td colspan="4" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400 border-dashed">
                                指示がありません。上の在庫リストから倉庫をクリックして追加してください。
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            
            <div class="flex justify-end">
                <button
                    type="button"
                    @click="addManualAllocation()"
                    class="flex items-center gap-1 text-sm font-medium text-primary-600 hover:text-primary-500 dark:text-primary-400 dark:hover:text-primary-300"
                >
                    <x-heroicon-m-plus class="w-4 h-4" />
                    <span>倉庫を追加</span>
                </button>
            </div>
        </div>
    </div>
</x-dynamic-component>
