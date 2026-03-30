<x-filament-widgets::widget>
    <x-filament::section>
        <x-slot name="heading">
            <div class="flex items-center gap-3">
                <div class="flex items-center gap-2">
                    <x-heroicon-o-truck class="w-5 h-5" />
                    <span>横持ち出荷</span>
                    <span class="text-sm font-normal text-gray-500">{{ count($allocations) }}件</span>
                </div>
                <div class="flex items-center gap-2">
                    <label for="filter-date" class="text-sm font-normal text-gray-500">出荷日:</label>
                    <input
                        type="date"
                        id="filter-date"
                        wire:model.live="filterDate"
                        class="text-sm border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-2 py-1 focus:border-primary-500 focus:ring-primary-500"
                    />
                </div>
            </div>
        </x-slot>

        {{-- Warehouse Tabs --}}
        <div class="mb-4 border-b border-gray-200 dark:border-gray-700">
            <nav class="flex gap-1 overflow-x-auto" aria-label="Warehouse tabs">
                @foreach ($warehouses as $warehouse)
                    <button
                        wire:click="setWarehouse('{{ $warehouse['id'] }}')"
                        class="px-3 py-2 text-sm font-medium whitespace-nowrap border-b-2 transition-colors
                            {{ $activeWarehouse === (string) $warehouse['id']
                                ? 'border-primary-500 text-primary-600 dark:text-primary-400'
                                : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-300' }}"
                    >
                        {{ $warehouse['name'] }}
                    </button>
                @endforeach
            </nav>
        </div>

        {{-- Table --}}
        <div class="overflow-x-auto">
            <table class="w-full text-sm border-collapse">
                <thead>
                    <tr class="bg-gray-50 dark:bg-gray-800">
                        <th class="px-3 py-2 text-center font-semibold text-gray-900 dark:text-gray-100 border border-gray-200 dark:border-gray-700">出荷倉庫</th>
                        <th class="px-3 py-2 text-center font-semibold text-gray-900 dark:text-gray-100 border border-gray-200 dark:border-gray-700">出荷日</th>
                        <th class="px-3 py-2 text-center font-semibold text-gray-900 dark:text-gray-100 border border-gray-200 dark:border-gray-700">配送コース</th>
                        <th class="px-3 py-2 text-center font-semibold text-gray-900 dark:text-gray-100 border border-gray-200 dark:border-gray-700">商品CD</th>
                        <th class="px-3 py-2 text-center font-semibold text-gray-900 dark:text-gray-100 border border-gray-200 dark:border-gray-700">JANCODE</th>
                        <th class="px-3 py-2 text-center font-semibold text-gray-900 dark:text-gray-100 border border-gray-200 dark:border-gray-700">商品名</th>
                        <th class="px-3 py-2 text-center font-semibold text-gray-900 dark:text-gray-100 border border-gray-200 dark:border-gray-700">得意先</th>
                        <th class="px-3 py-2 text-center font-semibold text-gray-900 dark:text-gray-100 border border-gray-200 dark:border-gray-700">単位</th>
                        <th class="px-3 py-2 text-center font-semibold text-gray-900 dark:text-gray-100 border border-gray-200 dark:border-gray-700">依頼数</th>
                        <th class="px-3 py-2 text-center font-semibold text-gray-900 dark:text-gray-100 border border-gray-200 dark:border-gray-700">出荷数</th>
                        <th class="px-3 py-2 text-center font-semibold text-gray-900 dark:text-gray-100 border border-gray-200 dark:border-gray-700">欠品数</th>
                        <th class="px-3 py-2 text-center font-semibold text-gray-900 dark:text-gray-100 border border-gray-200 dark:border-gray-700">ステータス</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($allocations as $index => $allocation)
                        <tr class="{{ $index % 2 === 0 ? 'bg-white dark:bg-gray-900' : 'bg-gray-50/50 dark:bg-gray-800/50' }} {{ $allocation['is_finished'] ? 'opacity-60' : '' }}">
                            <td class="px-3 py-2 text-center border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300">{{ $allocation['target_warehouse'] }}</td>
                            <td class="px-3 py-2 text-center border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300">{{ $allocation['shipment_date'] }}</td>
                            <td class="px-3 py-2 text-center border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300">{{ $allocation['delivery_course'] }}</td>
                            <td class="px-3 py-2 text-center border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300">{{ $allocation['item_code'] }}</td>
                            <td class="px-3 py-2 text-center border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300">{{ $allocation['jan_code'] }}</td>
                            <td class="px-3 py-2 text-left border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300">{{ $allocation['item_name'] }}</td>
                            <td class="px-3 py-2 text-center border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300 max-w-[120px] truncate">{{ $allocation['partner_name'] }}</td>
                            <td class="px-3 py-2 text-center border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300">{{ $allocation['qty_type'] }}</td>
                            <td class="px-3 py-2 text-center border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300">{{ $allocation['assign_qty'] }}</td>
                            <td class="px-3 py-2 text-center border border-gray-200 dark:border-gray-700 text-gray-700 dark:text-gray-300">{{ $allocation['picked_qty'] }}</td>
                            <td class="px-3 py-2 text-center border border-gray-200 dark:border-gray-700 font-bold
                                {{ $allocation['remaining_qty'] > 0 ? 'text-amber-600 dark:text-amber-400' : 'text-green-600 dark:text-green-400' }}">
                                {{ $allocation['remaining_qty'] }}
                            </td>
                            <td class="px-3 py-2 text-center border border-gray-200 dark:border-gray-700">
                                @php
                                    $badgeClasses = match ($allocation['status_color']) {
                                        'green' => 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-300',
                                        'yellow' => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-300',
                                        'blue' => 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-300',
                                        'red' => 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-300',
                                        default => 'bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300',
                                    };
                                @endphp
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium {{ $badgeClasses }}">
                                    {{ $allocation['status_label'] }}
                                </span>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="12" class="px-3 py-8 text-center text-gray-500 dark:text-gray-400 border border-gray-200 dark:border-gray-700">
                                横持ち出荷データはありません
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
