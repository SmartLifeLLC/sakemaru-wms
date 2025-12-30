<div class="space-y-4">
    @if(empty($trade))
        <div class="text-center text-gray-500">
            <p>伝票情報が見つかりません</p>
        </div>
    @else
        {{-- 上部エリア：基本情報・納品先・金額 --}}
        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
            {{-- 左カラム：基本情報 (3カラム表示) --}}
            <div class="bg-white dark:bg-gray-800 rounded-lg p-4 space-y-3 border border-gray-200 dark:border-gray-700 shadow-sm">
                <h3 class="text-sm font-bold text-gray-800 dark:text-white border-b border-gray-200 dark:border-gray-700 pb-2">基本情報</h3>
                <div class="grid grid-cols-3 gap-2 text-xs">
                    <div>
                        <span class="text-gray-500 dark:text-gray-400 block font-medium">伝票番号</span>
                        <span class="font-bold text-gray-800 dark:text-gray-100">{{ $trade['trade']->serial_id ?? '-' }}</span>
                    </div>
                    <div>
                        <span class="text-gray-500 dark:text-gray-400 block font-medium">処理日</span>
                        <span class="text-gray-800 dark:text-gray-100">{{ $trade['trade']->process_date ? \Carbon\Carbon::parse($trade['trade']->process_date)->format('Y-m-d') : '-' }}</span>
                    </div>
                    <div>
                        <span class="text-gray-500 dark:text-gray-400 block font-medium">納品日</span>
                        <span class="text-gray-800 dark:text-gray-100">{{ $trade['earning']->delivered_date ? \Carbon\Carbon::parse($trade['earning']->delivered_date)->format('Y-m-d') : '-' }}</span>
                    </div>
                    <div>
                        <span class="text-gray-500 dark:text-gray-400 block font-medium">請求日</span>
                        <span class="text-gray-800 dark:text-gray-100">{{ $trade['earning']->account_date ? \Carbon\Carbon::parse($trade['earning']->account_date)->format('Y-m-d') : '-' }}</span>
                    </div>
                    <div class="col-span-2">
                        <span class="text-gray-500 dark:text-gray-400 block font-medium">得意先</span>
                        <span class="text-gray-800 dark:text-gray-100">{{ $trade['partner']->code ?? '-' }} {{ $trade['partner']->name ?? '-' }}</span>
                    </div>
                    <div class="col-span-3">
                        <span class="text-gray-500 dark:text-gray-400 block font-medium">配送コース</span>
                        <div class="mt-1">
                            @php
                                $canChangeCourse = in_array($trade['earning']->picking_status, ['BEFORE', 'BEFORE_PICKING']);
                            @endphp
                            @if($canChangeCourse)
                                <div class="flex items-end space-x-2">
                                    <div class="flex-grow">
                                        {{ $this->form }}
                                    </div>
                                    <button
                                        type="button"
                                        wire:click="updateDeliveryCourse"
                                        class="inline-flex items-center px-3 py-2 border border-transparent text-xs font-medium rounded shadow-sm text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 whitespace-nowrap mb-1"
                                    >
                                        変更
                                    </button>
                                </div>
                            @else
                                <div class="text-gray-800 dark:text-gray-100 mb-1">
                                    @if($trade['delivery_course'])
                                        {{ $trade['delivery_course']->code }} - {{ $trade['delivery_course']->name }}
                                    @else
                                        -
                                    @endif
                                </div>
                                <span class="text-red-500 text-xs font-bold">配送コース変更不可</span>
                            @endif
                        </div>
                    </div>
                    <div class="col-span-3">
                        <span class="text-gray-500 dark:text-gray-400 block font-medium">ステータス</span>
                        @php
                            $status = $trade['earning']->picking_status ?? 'PENDING';
                            $statusLabel = match($status) {
                                'PENDING' => '未着手',
                                'BEFORE_PICKING' => 'ピッキング準備中',
                                'PICKING' => 'ピッキング中',
                                'COMPLETED' => '完了',
                                'CANCELLED' => 'キャンセル',
                                default => $status,
                            };
                            $statusColor = match($status) {
                                'PENDING' => 'gray',
                                'BEFORE_PICKING' => 'yellow',
                                'PICKING' => 'blue',
                                'COMPLETED' => 'green',
                                'CANCELLED' => 'red',
                                default => 'gray',
                            };
                        @endphp
                        <span class="inline-flex items-center px-2 py-0.5 text-xs font-medium rounded-full bg-{{ $statusColor }}-100 text-{{ $statusColor }}-800 dark:bg-{{ $statusColor }}-900 dark:text-{{ $statusColor }}-200">
                            {{ $statusLabel }}
                        </span>
                    </div>
                </div>
            </div>

            {{-- 中央カラム：納品先・備考 (2カラム表示) --}}
            <div class="bg-white dark:bg-gray-800 rounded-lg p-4 space-y-3 border border-gray-200 dark:border-gray-700 shadow-sm">
                <h3 class="text-sm font-bold text-gray-800 dark:text-white border-b border-gray-200 dark:border-gray-700 pb-2">納品先情報</h3>
                <div class="grid grid-cols-2 gap-2 text-xs">
                    <div class="col-span-2">
                        <span class="text-gray-500 dark:text-gray-400 block font-medium">名称</span>
                        <span class="text-gray-800 dark:text-gray-100 font-medium">
                            {{ $trade['buyer']->delivery_name ?? $trade['partner']->name ?? '-' }}
                        </span>
                    </div>
                    <div class="col-span-2">
                        <span class="text-gray-500 dark:text-gray-400 block font-medium">住所</span>
                        <span class="text-gray-800 dark:text-gray-100">
                            〒{{ $trade['buyer']->delivery_postal_code ?? $trade['partner']->postal_code ?? '-' }}<br>
                            {{ $trade['buyer']->delivery_address1 ?? $trade['partner']->address1 ?? '-' }}
                            {{ $trade['buyer']->delivery_address2 ?? $trade['partner']->address2 ?? '' }}
                        </span>
                    </div>
                    <div>
                        <span class="text-gray-500 dark:text-gray-400 block font-medium">TEL</span>
                        <span class="text-gray-800 dark:text-gray-100">
                            {{ $trade['buyer']->delivery_tel ?? $trade['partner']->tel ?? '-' }}
                        </span>
                    </div>
                    <div>
                        <span class="text-gray-500 dark:text-gray-400 block font-medium">担当営業</span>
                        <span class="text-gray-800 dark:text-gray-100">
                            {{ $trade['salesman']->name ?? '-' }}
                        </span>
                    </div>
                </div>
                @if($trade['trade']->note)
                    <div class="pt-2 border-t border-gray-200 dark:border-gray-700">
                        <span class="text-gray-500 dark:text-gray-400 block text-xs font-medium">備考</span>
                        <p class="text-xs text-gray-800 dark:text-gray-100 mt-1">{{ $trade['trade']->note }}</p>
                    </div>
                @endif
            </div>

            {{-- 右カラム：金額情報 --}}
            <div class="bg-white dark:bg-gray-800 rounded-lg p-4 space-y-3 border border-gray-200 dark:border-gray-700 shadow-sm">
                <h3 class="text-sm font-bold text-gray-800 dark:text-white border-b border-gray-200 dark:border-gray-700 pb-2">金額情報</h3>
                <div class="space-y-1 text-xs">
                    <div class="flex justify-between">
                        <span class="text-gray-500 dark:text-gray-400">10%対象</span>
                        <span class="text-gray-800 dark:text-gray-100">¥{{ number_format($trade['trade_price']->subtotal_10_percent ?? 0) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500 dark:text-gray-400">消費税(10%)</span>
                        <span class="text-gray-800 dark:text-gray-100">¥{{ number_format($trade['trade_price']->tax_10_percent ?? 0) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500 dark:text-gray-400">8%対象</span>
                        <span class="text-gray-800 dark:text-gray-100">¥{{ number_format($trade['trade_price']->subtotal_8_percent ?? 0) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500 dark:text-gray-400">消費税(8%)</span>
                        <span class="text-gray-800 dark:text-gray-100">¥{{ number_format($trade['trade_price']->tax_8_percent ?? 0) }}</span>
                    </div>
                    <div class="flex justify-between">
                        <span class="text-gray-500 dark:text-gray-400">非課税対象</span>
                        <span class="text-gray-800 dark:text-gray-100">¥{{ number_format($trade['trade_price']->subtotal_0_percent ?? 0) }}</span>
                    </div>
                    <div class="border-t border-gray-200 dark:border-gray-700 my-2"></div>
                    <div class="flex justify-between font-semibold">
                        <span class="text-gray-700 dark:text-gray-300">小計</span>
                        <span class="text-gray-900 dark:text-gray-100">¥{{ number_format($trade['trade']->subtotal ?? 0) }}</span>
                    </div>
                    <div class="flex justify-between font-semibold">
                        <span class="text-gray-700 dark:text-gray-300">消費税</span>
                        <span class="text-gray-900 dark:text-gray-100">¥{{ number_format($trade['trade']->tax ?? 0) }}</span>
                    </div>
                    <div class="border-t-2 border-gray-300 dark:border-gray-600 my-2 pt-1">
                        <div class="flex justify-between text-base font-bold">
                            <span class="text-gray-900 dark:text-gray-100">合計</span>
                            <span class="text-gray-900 dark:text-gray-100">¥{{ number_format($trade['trade']->total ?? 0) }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- 出荷商品リスト --}}
        <div>
            <h3 class="text-sm font-semibold mb-2 text-gray-900 dark:text-gray-100">出荷商品リスト</h3>
            <div class="overflow-x-auto border rounded-lg border-gray-200 dark:border-gray-700 max-h-96 overflow-y-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700 relative">
                    <thead class="bg-gray-50 dark:bg-gray-800 sticky top-0 z-10">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">No</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">商品コード</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">商品名</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">規格</th>
                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">受注数</th>
                            <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">受注単位</th>
                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">引当数</th>
                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">出荷数</th>
                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">単価</th>
                            <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">金額</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white dark:bg-gray-900 divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse($trade['trade_items'] as $index => $item)
                            <tr class="hover:bg-gray-50 dark:hover:bg-gray-800">
                                <td class="px-3 py-2 whitespace-nowrap text-xs text-gray-900 dark:text-gray-100">
                                    {{ $index + 1 }}
                                </td>
                                <td class="px-3 py-2 whitespace-nowrap text-xs text-gray-900 dark:text-gray-100">
                                    {{ $item->item->code ?? '-' }}
                                </td>
                                <td class="px-3 py-2 text-xs text-gray-900 dark:text-gray-100">
                                    {{ $item->item->name ?? '-' }}
                                </td>
                                <td class="px-3 py-2 whitespace-nowrap text-xs text-gray-900 dark:text-gray-100">
                                    @if($item->item && $item->item->volume && $item->item->volume_unit)
                                        {{ $item->item->volume }}{{ App\Enums\EVolumeUnit::tryFrom($item->item->volume_unit)?->name() ?? '' }}
                                    @else
                                        -
                                    @endif
                                </td>
                                <td class="px-3 py-2 whitespace-nowrap text-xs text-right text-gray-900 dark:text-gray-100">
                                    {{ number_format($item->quantity ?? 0) }}
                                </td>
                                <td class="px-3 py-2 whitespace-nowrap text-xs text-center">
                                    @php
                                        $qtyType = $item->quantity_type ?? 'PIECE';
                                        $qtyTypeLabel = App\Enums\QuantityType::tryFrom($qtyType)?->name() ?? $qtyType;
                                        $qtyTypeColor = $qtyType === 'CASE' ? 'green' : 'blue';
                                    @endphp
                                    <span class="inline-flex items-center px-2 py-0.5 text-xs font-medium rounded-full bg-{{ $qtyTypeColor }}-100 text-{{ $qtyTypeColor }}-800 dark:bg-{{ $qtyTypeColor }}-900 dark:text-{{ $qtyTypeColor }}-200">
                                        {{ $qtyTypeLabel }}
                                    </span>
                                </td>
                                <td class="px-3 py-2 whitespace-nowrap text-xs text-right text-gray-900 dark:text-gray-100">
                                    {{ number_format($item->picking_result->planned_qty ?? 0) }}
                                </td>
                                <td class="px-3 py-2 whitespace-nowrap text-xs text-right text-gray-900 dark:text-gray-100">
                                    {{ number_format($item->picking_result->picked_qty ?? 0) }}
                                </td>
                                <td class="px-3 py-2 whitespace-nowrap text-xs text-right text-gray-900 dark:text-gray-100">
                                    ¥{{ number_format($item->price ?? 0) }}
                                </td>
                                <td class="px-3 py-2 whitespace-nowrap text-xs text-right text-gray-900 dark:text-gray-100 font-medium">
                                    ¥{{ number_format(($item->quantity ?? 0) * ($item->price ?? 0)) }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="10" class="px-3 py-8 text-center text-xs text-gray-500 dark:text-gray-400">
                                    商品データがありません
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        
        @if(!empty($trade['trade_balances']) && count($trade['trade_balances']) > 0)
            <div class="bg-gray-50 dark:bg-gray-800 rounded-lg p-4">
                 <h3 class="text-sm font-semibold mb-2 text-gray-900 dark:text-gray-100">残高情報</h3>
                 <div class="flex flex-wrap gap-4">
                     @foreach($trade['trade_balances'] as $balance)
                        <div class="bg-white dark:bg-gray-900 px-3 py-2 rounded shadow-sm flex items-center space-x-2">
                            <label class="text-xs font-medium text-gray-500 dark:text-gray-400">
                                区分ID: {{ $balance->ledger_classification_id }}
                            </label>
                            <p class="text-sm font-bold text-gray-900 dark:text-gray-100">
                                ¥{{ number_format($balance->amount) }}
                            </p>
                        </div>
                     @endforeach
                 </div>
            </div>
        @endif
    @endif
</div>
