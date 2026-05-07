<x-filament-panels::page>
    <div class="space-y-6 h-[calc(100vh-10rem)] flex flex-col overflow-hidden">
        {{-- タスク情報ヘッダー --}}
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-6">
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">波動番号</div>
                    <div class="font-semibold">{{ $record->wave->wave_code ?? '-' }}</div>
                </div>
                <div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">倉庫</div>
                    <div class="font-semibold">{{ $record->warehouse->name ?? '-' }}</div>
                </div>
                <div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">ピッキングエリア</div>
                    <div class="font-semibold">{{ $record->pickingArea->name ?? '-' }}</div>
                </div>
                <div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">担当者</div>
                    <div class="font-semibold">{{ $record->picker->display_name ?? '未割当' }}</div>
                </div>
            </div>
            <div class="mt-1 border-t-1 border-t-gray-300 grid grid-cols-4 gap-4 text-center">
                <div>
                    <div class="text-2xl font-bold text-gray-700 dark:text-gray-200">
                        {{ collect($items)->count() }}
                    </div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">ピッキング商品数</div>
                </div>
                <div>
                    <div class="text-2xl font-bold text-yellow-600 dark:text-yellow-400">
                        {{ collect($items)->unique('item_code')->count() }}
                    </div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">ピッキング作業件数</div>
                </div>
                <div>
                    <div class="text-2xl font-bold text-blue-600 dark:text-blue-400">
                        {{ collect($items)->where('status', 'PICKING')->count() }}
                    </div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">作業中</div>
                </div>
                <div>
                    <div class="text-2xl font-bold text-green-600 dark:text-green-400">
                        {{ collect($items)->whereIn('status', ['COMPLETED', 'SHORTAGE'])->count() }}
                    </div>
                    <div class="text-sm text-gray-500 dark:text-gray-400">完了</div>
                </div>
            </div>
        </div>

        {{-- ピッキング商品リスト --}}
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow overflow-hidden -mt-4 flex-1 min-h-0 flex flex-col" x-data="{
            sortKey: '',
            sortAsc: true,
            filterText: '',
            isDirty: false,
            sort(key) {
                if (this.sortKey === key) {
                    this.sortAsc = !this.sortAsc;
                } else {
                    this.sortKey = key;
                    this.sortAsc = true;
                }
                this.applySort();
            },
            applySort() {
                const tbody = this.$refs.tbody;
                const rows = Array.from(tbody.querySelectorAll('tr'));
                if (this.sortKey) {
                    rows.sort((a, b) => {
                        const aVal = (a.dataset[this.sortKey] || '').toLowerCase();
                        const bVal = (b.dataset[this.sortKey] || '').toLowerCase();
                        const cmp = aVal.localeCompare(bVal, 'ja');
                        return this.sortAsc ? cmp : -cmp;
                    });
                    rows.forEach(row => tbody.appendChild(row));
                }
                this.applyStripe();
            },
            applyFilter() {
                const term = this.filterText.toLowerCase();
                const tbody = this.$refs.tbody;
                const rows = Array.from(tbody.querySelectorAll('tr'));
                rows.forEach(row => {
                    if (!term) {
                        row.style.display = '';
                    } else {
                        const match = (row.dataset.clientcode || '').toLowerCase().includes(term)
                            || (row.dataset.clientname || '').toLowerCase().includes(term)
                            || (row.dataset.itemcode || '').toLowerCase().includes(term)
                            || (row.dataset.itemname || '').toLowerCase().includes(term);
                        row.style.display = match ? '' : 'none';
                    }
                });
                this.applyStripe();
            },
            applyStripe() {
                const tbody = this.$refs.tbody;
                const visible = Array.from(tbody.querySelectorAll('tr')).filter(r => r.style.display !== 'none');
                visible.forEach((row, i) => {
                    row.classList.toggle('bg-gray-50', i % 2 === 1);
                    row.classList.toggle('dark:bg-gray-750', i % 2 === 1);
                    row.classList.toggle('bg-white', i % 2 === 0);
                    row.classList.toggle('dark:bg-gray-800', i % 2 === 0);
                });
            },
            init() {
                this.$nextTick(() => this.applyStripe());
                this._beforeUnload = (e) => { if (this.isDirty) { e.preventDefault(); e.returnValue = ''; } };
                window.addEventListener('beforeunload', this._beforeUnload);
                this._navGuard = (e) => {
                    if (!this.isDirty) return;
                    const link = e.target.closest('a[href]');
                    if (!link || link.getAttribute('href')?.startsWith('#')) return;
                    if (!confirm('保存していない変更があります。ページを離れますか？')) {
                        e.preventDefault();
                        e.stopPropagation();
                        e.stopImmediatePropagation();
                    } else {
                        this.isDirty = false;
                    }
                };
                document.addEventListener('click', this._navGuard, true);
            },
            destroy() {
                window.removeEventListener('beforeunload', this._beforeUnload);
                document.removeEventListener('click', this._navGuard, true);
            }
        }" x-on:items-saved.window="isDirty = false" x-effect="window.__pickingDirty = isDirty">
            <div class="px-6 py-2 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between gap-4">
                <h3 class="text-sm font-semibold shrink-0">ピッキング商品一覧</h3>
                <div class="flex items-center gap-3">
                    <div class="relative w-72">
                        <input type="text" x-model="filterText" @input="applyFilter()"
                            placeholder="得意先CD/名、商品CD/名で絞り込み..."
                            class="w-full text-xs pl-8 pr-8 py-1.5 border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded focus:border-primary-500 focus:ring-primary-500">
                        <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                        <button x-show="filterText" @click="filterText=''; applyFilter()" class="absolute right-2 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                            <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                    <button
                        wire:click="saveAllItems"
                        wire:loading.attr="disabled"
                        x-show="isDirty"
                        x-transition
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-white bg-primary-600 hover:bg-primary-500 rounded-lg shadow-sm transition disabled:opacity-50"
                    >
                        <svg class="w-4 h-4" wire:loading.class="animate-spin" wire:target="saveAllItems" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" wire:loading.remove wire:target="saveAllItems"/>
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z" wire:loading wire:target="saveAllItems"/>
                        </svg>
                        <span wire:loading.remove wire:target="saveAllItems">保存</span>
                        <span wire:loading wire:target="saveAllItems">保存中...</span>
                    </button>
                </div>
            </div>

            <div class="overflow-x-auto overflow-y-auto flex-1 min-h-0">
                <table class="w-full min-w-[1400px]">
                    <thead class="bg-gray-50 dark:bg-gray-900 sticky top-0 z-10">
                        <tr>
                            <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 dark:text-gray-400">
                                ID
                            </th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400">
                                伝票番号
                            </th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 cursor-pointer select-none hover:text-gray-700 dark:hover:text-gray-200" @click="sort('clientcode')">
                                得意先CD
                                <span x-show="sortKey === 'clientcode'" x-text="sortAsc ? '▲' : '▼'" class="text-xs"></span>
                            </th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 cursor-pointer select-none hover:text-gray-700 dark:hover:text-gray-200" @click="sort('clientname')">
                                得意先名
                                <span x-show="sortKey === 'clientname'" x-text="sortAsc ? '▲' : '▼'" class="text-xs"></span>
                            </th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400">
                                担当営業
                            </th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400">
                                ロケーション
                            </th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 cursor-pointer select-none hover:text-gray-700 dark:hover:text-gray-200" @click="sort('itemcode')">
                                商品CD
                                <span x-show="sortKey === 'itemcode'" x-text="sortAsc ? '▲' : '▼'" class="text-xs"></span>
                            </th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 cursor-pointer select-none hover:text-gray-700 dark:hover:text-gray-200" @click="sort('itemname')">
                                商品名
                                <span x-show="sortKey === 'itemname'" x-text="sortAsc ? '▲' : '▼'" class="text-xs"></span>
                            </th>
                            <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 dark:text-gray-400">
                                単位
                            </th>
                            <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 dark:text-gray-400">
                                受注数
                            </th>
                            <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 dark:text-gray-400">
                                引当数
                            </th>
                            <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 dark:text-gray-400">
                                ピック数
                            </th>
                            <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 dark:text-gray-400">
                                引当欠品
                            </th>
                            <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 dark:text-gray-400">
                                ピック欠品
                            </th>
                            <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 dark:text-gray-400">
                                ステータス
                            </th>
                            <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 dark:text-gray-400">
                                ピック時刻
                            </th>
                        </tr>
                    </thead>
                    <tbody x-ref="tbody" class="divide-y divide-gray-200 dark:divide-gray-700">
                        @foreach($items as $item)
                        <tr class="hover:!bg-gray-100 dark:hover:!bg-gray-700"
                            data-clientcode="{{ $item['client_code'] }}"
                            data-clientname="{{ $item['client_name'] }}"
                            data-itemcode="{{ $item['item_code'] }}"
                            data-itemname="{{ $item['item_name'] }}">
                            <td class="px-3 py-2 whitespace-nowrap text-xs text-center text-gray-500 dark:text-gray-400">
                                {{ $item['id'] }}
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap text-xs font-medium text-gray-900 dark:text-gray-100">
                                {{ $item['serial_id'] }}
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap text-xs text-gray-900 dark:text-gray-100">
                                {{ $item['client_code'] }}
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap text-xs text-gray-900 dark:text-gray-100">
                                {{ $item['client_name'] }}
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap text-xs text-gray-900 dark:text-gray-100">
                                {{ $item['sales_rep_name'] }}
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap text-xs text-gray-900 dark:text-gray-100">
                                {{ $item['location'] }}
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap text-xs text-gray-900 dark:text-gray-100">
                                {{ $item['item_code'] }}
                            </td>
                            <td class="px-3 py-2 text-xs text-gray-900 dark:text-gray-100 whitespace-nowrap">
                                {{ $item['item_name'] }}
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap text-xs text-center text-gray-900 dark:text-gray-100">
                                <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-800 dark:bg-gray-700 dark:text-gray-300">
                                    {{ $item['planned_qty_type_display'] }}
                                </span>
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap text-xs text-center text-gray-900 dark:text-gray-100">
                                {{ $item['ordered_qty'] }}
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap text-xs text-center text-gray-900 dark:text-gray-100">
                                {{ $item['planned_qty'] }}
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap text-xs text-center">
                                <input
                                    type="number"
                                    wire:model="items.{{ $loop->index }}.picked_qty"
                                    @input="isDirty = true"
                                    min="0"
                                    max="{{ max($item['ordered_qty'], $item['planned_qty']) }}"
                                    step="1"
                                    inputmode="numeric"
                                    pattern="[0-9]*"
                                    oninput="this.value = this.value.replace(/[^0-9]/g, '')"
                                    class="w-16 text-center text-xs border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 border focus:border-primary-500 focus:ring-primary-500"
                                >
                            </td>
                            @php
                                $allocationShortage = max(0, $item['ordered_qty'] - $item['planned_qty']);
                                $pickingShortage = max(0, $item['planned_qty'] - $item['picked_qty']);
                            @endphp
                            <td class="px-3 py-2 whitespace-nowrap text-xs text-center">
                                <span class="@if($allocationShortage > 0) font-semibold text-orange-600 dark:text-orange-400 @else text-gray-400 dark:text-gray-500 @endif">
                                    {{ $allocationShortage > 0 ? $allocationShortage : '-' }}
                                </span>
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap text-xs text-center">
                                <span class="@if($pickingShortage > 0 && $item['status'] === 'COMPLETED' || $item['status'] === 'SHORTAGE') font-semibold text-red-600 dark:text-red-400 @else text-gray-400 dark:text-gray-500 @endif">
                                    {{ ($item['status'] === 'COMPLETED' || $item['status'] === 'SHORTAGE') && $pickingShortage > 0 ? $pickingShortage : '-' }}
                                </span>
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap text-xs text-center">
                                @if($item['status'] === 'COMPLETED')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 dark:bg-green-800 dark:text-green-100">
                                        完了
                                    </span>
                                @elseif($item['status'] === 'SHORTAGE')
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800 dark:bg-yellow-800 dark:text-yellow-100">
                                        欠品あり
                                    </span>
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800 dark:bg-blue-800 dark:text-blue-100">
                                        作業中
                                    </span>
                                @endif
                            </td>
                            <td class="px-3 py-2 whitespace-nowrap text-xs text-center text-gray-500 dark:text-gray-400">
                                {{ $item['picked_at'] ?? '-' }}
                            </td>
                        </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>


    </div>

    @push('scripts')
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            focusFirstInput();
        });

        if (typeof Livewire !== 'undefined') {
            Livewire.hook('commit', ({ component, commit, respond, succeed, fail }) => {
                succeed(({ snapshot, effect }) => {
                    setTimeout(() => {
                        const inputs = document.querySelectorAll('input[type="number"]:not([readonly])');
                        if (inputs.length > 0 && !document.activeElement.matches('input[type="number"]')) {
                            inputs[0].focus();
                        }
                    }, 100);
                });
            });
        }

        function focusFirstInput() {
            const firstInput = document.querySelector('input[type="number"]:not([readonly])');
            if (firstInput) {
                firstInput.focus();
            }
        }

        document.addEventListener('keydown', function(event) {
            if (event.key === 'Enter' && event.target.matches('input[type="number"]:not([readonly])')) {
                event.preventDefault();
                const inputs = Array.from(document.querySelectorAll('input[type="number"]:not([readonly])'));
                const currentIndex = inputs.indexOf(event.target);

                if (currentIndex > -1 && currentIndex < inputs.length - 1) {
                    inputs[currentIndex + 1].focus();
                    inputs[currentIndex + 1].select();
                } else if (currentIndex === inputs.length - 1) {
                    event.target.blur();
                }
            }
        });
    </script>
    @endpush
</x-filament-panels::page>
