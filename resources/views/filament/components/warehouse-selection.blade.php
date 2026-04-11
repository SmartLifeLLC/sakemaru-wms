@php
    $lw = $getLivewire();
    $data = $lw->warehousesData ?? [];

    if (empty($data)) {
        $data = $lw->getActiveWarehouses();
        $lw->warehousesData = $data;
        $lw->selectedWarehouseIds = collect($data)->pluck('id')->values()->toArray();
    }

    $warehouseIds = collect($data)->pluck('id')->values()->toArray();
@endphp

<div x-data="{
    searchQuery: '',
    allWarehouses: @js($data),
    selectedIds: @js($warehouseIds),
    expanded: false,

    get filteredWarehouses() {
        if (!this.searchQuery) return this.allWarehouses;
        const q = this.searchQuery.toLowerCase();
        return this.allWarehouses.filter(w =>
            String(w.code).toLowerCase().includes(q) || w.name.toLowerCase().includes(q)
        );
    },

    get selectedCount() {
        return this.selectedIds.length;
    },

    get isAllSelected() {
        return this.allWarehouses.length > 0 &&
            this.selectedIds.length === this.allWarehouses.length;
    },

    get allVisibleSelected() {
        return this.filteredWarehouses.length > 0 &&
            this.filteredWarehouses.every(w => this.selectedIds.includes(w.id));
    },

    isSelected(id) {
        return this.selectedIds.includes(id);
    },

    toggle(id) {
        const idx = this.selectedIds.indexOf(id);
        if (idx > -1) {
            this.selectedIds.splice(idx, 1);
        } else {
            this.selectedIds.push(id);
        }
    },

    toggleAllVisible() {
        if (this.allVisibleSelected) {
            const visibleIds = this.filteredWarehouses.map(w => w.id);
            this.selectedIds = this.selectedIds.filter(id => !visibleIds.includes(id));
        } else {
            const visibleIds = this.filteredWarehouses.map(w => w.id);
            this.selectedIds = [...new Set([...this.selectedIds, ...visibleIds])];
        }
    },

    selectAll() {
        this.selectedIds = this.allWarehouses.map(w => w.id);
    },

    deselectAll() {
        this.selectedIds = [];
    }
}" x-effect="$wire.set('selectedWarehouseIds', selectedIds, false)" class="space-y-3">

    {{-- ヘッダー: 選択状況 + 展開ボタン --}}
    <div class="flex items-center justify-between rounded-lg border border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-800 px-4 py-2.5">
        <div class="flex items-center gap-2">
            <template x-if="isAllSelected">
                <x-heroicon-m-check-circle class="w-5 h-5 text-success-500" />
            </template>
            <template x-if="!isAllSelected && selectedCount > 0">
                <x-heroicon-m-minus-circle class="w-5 h-5 text-warning-500" />
            </template>
            <template x-if="selectedCount === 0">
                <x-heroicon-m-x-circle class="w-5 h-5 text-danger-500" />
            </template>
            <span class="text-sm text-gray-700 dark:text-gray-300">
                <span x-show="isAllSelected">すべての倉庫が選択されています</span>
                <span x-show="!isAllSelected" x-text="`${selectedCount}/${allWarehouses.length}件 選択中`"></span>
            </span>
        </div>
        <button type="button"
                @click="expanded = !expanded"
                class="text-xs px-3 py-1.5 rounded-md bg-white hover:bg-gray-100 text-gray-700 border border-gray-300 dark:bg-gray-700 dark:hover:bg-gray-600 dark:text-gray-300 dark:border-gray-500 transition flex items-center gap-1">
            <span x-text="expanded ? '閉じる' : '選択を変更'"></span>
            <svg class="w-3.5 h-3.5 transition-transform" :class="expanded && 'rotate-180'" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
            </svg>
        </button>
    </div>

    {{-- 展開時: 検索 + チェックボックスリスト --}}
    <div x-show="expanded" x-collapse class="space-y-3">
        <div class="flex items-center gap-3">
            <div class="relative flex-1">
                <input type="text"
                       x-model="searchQuery"
                       placeholder="倉庫コード・名前で検索..."
                       class="w-full rounded-lg border-gray-300 text-sm pl-9 shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:bg-gray-700 dark:border-gray-600 dark:text-white">
                <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                    <x-heroicon-m-magnifying-glass class="w-4 h-4 text-gray-400" />
                </div>
            </div>
        </div>

        <div class="flex items-center gap-2">
            <button type="button"
                    @click="selectAll()"
                    class="text-xs px-3 py-1.5 rounded-md bg-gray-100 hover:bg-gray-200 text-gray-700 dark:bg-gray-700 dark:hover:bg-gray-600 dark:text-gray-300 transition">
                すべて選択
            </button>
            <button type="button"
                    @click="deselectAll()"
                    class="text-xs px-3 py-1.5 rounded-md bg-gray-100 hover:bg-gray-200 text-gray-700 dark:bg-gray-700 dark:hover:bg-gray-600 dark:text-gray-300 transition">
                すべて解除
            </button>
            <button type="button"
                    @click="toggleAllVisible()"
                    class="text-xs px-3 py-1.5 rounded-md bg-gray-100 hover:bg-gray-200 text-gray-700 dark:bg-gray-700 dark:hover:bg-gray-600 dark:text-gray-300 transition">
                <span x-text="allVisibleSelected ? '表示中を解除' : '表示中を選択'"></span>
            </button>
        </div>

        <div class="border rounded-lg dark:border-gray-600 max-h-64 overflow-y-auto">
            <template x-if="filteredWarehouses.length === 0">
                <div class="p-4 text-center text-sm text-gray-500 dark:text-gray-400">
                    <span x-show="searchQuery">該当する倉庫がありません</span>
                    <span x-show="!searchQuery">倉庫が登録されていません</span>
                </div>
            </template>

            <div class="grid grid-cols-2">
                <template x-for="(warehouse, index) in filteredWarehouses" :key="warehouse.id">
                    <label class="flex items-center gap-2 px-3 py-2 cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700/50 border-b border-r dark:border-gray-600 transition"
                           :class="{
                               'bg-primary-50 dark:bg-primary-900/20': isSelected(warehouse.id),
                               'bg-gray-50 dark:bg-gray-800/50': !isSelected(warehouse.id) && index % 4 >= 2,
                           }">
                        <input type="checkbox"
                               :checked="isSelected(warehouse.id)"
                               @change="toggle(warehouse.id)"
                               class="rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500 dark:border-gray-500 dark:bg-gray-700 flex-shrink-0">
                        <div class="flex items-center gap-1.5 min-w-0">
                            <span class="text-xs font-mono font-semibold text-gray-500 dark:text-gray-400" x-text="warehouse.code"></span>
                            <span class="text-sm text-gray-900 dark:text-gray-100 truncate" x-text="warehouse.name"></span>
                        </div>
                    </label>
                </template>
            </div>
        </div>

        <div x-show="selectedCount === 0" class="text-sm text-danger-600 dark:text-danger-400">
            ※ 最低1つの倉庫を選択してください
        </div>
    </div>
</div>
