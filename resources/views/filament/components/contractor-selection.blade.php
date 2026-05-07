@php
    $lw = $getLivewire();
    $dataProperty = $contractorsProperty ?? 'contractorsData';
    $selectedProperty = $selectedProperty ?? 'selectedContractorIds';
    $fallbackMethod = $fallbackMethod ?? 'getContractorsForWarehouse';
    $notice = $notice ?? null;
    $data = $lw->{$dataProperty} ?? [];

    // contractorsData が空の場合、直接取得を試みる
    if (empty($data) && method_exists($lw, $fallbackMethod)) {
        $data = $lw->{$fallbackMethod}();
        $lw->{$dataProperty} = $data;
        $lw->{$selectedProperty} = collect($data)->pluck('id')->values()->toArray();
    }

    $contractorIds = collect($data)->pluck('id')->values()->toArray();
    $selectedIds = $lw->{$selectedProperty} ?? $contractorIds;
@endphp

<div x-data="{
    searchQuery: '',
    allContractors: @js($data),
    selectedIds: @js($selectedIds),
    expanded: false,

    get filteredContractors() {
        if (!this.searchQuery) return this.allContractors;
        const q = this.searchQuery.toLowerCase();
        return this.allContractors.filter(c =>
            String(c.code).toLowerCase().includes(q) || c.name.toLowerCase().includes(q)
        );
    },

    get selectedCount() {
        return this.selectedIds.length;
    },

    get isAllSelected() {
        return this.allContractors.length > 0 &&
            this.selectedIds.length === this.allContractors.length;
    },

    get allVisibleSelected() {
        return this.filteredContractors.length > 0 &&
            this.filteredContractors.every(c => this.selectedIds.includes(c.id));
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
            const visibleIds = this.filteredContractors.map(c => c.id);
            this.selectedIds = this.selectedIds.filter(id => !visibleIds.includes(id));
        } else {
            const visibleIds = this.filteredContractors.map(c => c.id);
            this.selectedIds = [...new Set([...this.selectedIds, ...visibleIds])];
        }
    },

    selectAll() {
        this.selectedIds = this.allContractors.map(c => c.id);
    },

    deselectAll() {
        this.selectedIds = [];
    }
    }" x-effect="$wire.set(@js($selectedProperty), selectedIds, false)" class="space-y-3">
    @if ($notice)
        <div class="flex items-center gap-2 rounded-lg border border-yellow-200 bg-yellow-50 px-3 py-2 text-xs text-yellow-800 dark:border-yellow-800 dark:bg-yellow-900/20 dark:text-yellow-300">
            <x-heroicon-m-exclamation-triangle class="h-4 w-4 flex-shrink-0" />
            <span>{{ $notice }}</span>
        </div>
    @endif

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
                <span x-show="isAllSelected">すべての仕入先が選択されています</span>
                <span x-show="!isAllSelected" x-text="`${selectedCount}/${allContractors.length}件 選択中`"></span>
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
                       placeholder="仕入先コード・名前で検索..."
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

        <div class="border rounded-lg dark:border-gray-600 max-h-96 overflow-y-auto">
            <template x-if="filteredContractors.length === 0">
                <div class="p-4 text-center text-sm text-gray-500 dark:text-gray-400">
                    <span x-show="searchQuery">該当する仕入先がありません</span>
                    <span x-show="!searchQuery">仕入先が登録されていません</span>
                </div>
            </template>

            <div class="grid grid-cols-2">
                <template x-for="(contractor, index) in filteredContractors" :key="contractor.id">
                    <label class="flex items-center gap-2 px-3 py-2 cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-700/50 border-b border-r dark:border-gray-600 transition"
                           :class="{
                               'bg-primary-50 dark:bg-primary-900/20': isSelected(contractor.id),
                               'bg-gray-50 dark:bg-gray-800/50': !isSelected(contractor.id) && index % 4 >= 2,
                           }">
                        <input type="checkbox"
                               :checked="isSelected(contractor.id)"
                               @change="toggle(contractor.id)"
                               class="rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500 dark:border-gray-500 dark:bg-gray-700 flex-shrink-0">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-1.5">
                                <span class="text-xs font-mono font-semibold text-gray-500 dark:text-gray-400" x-text="contractor.code"></span>
                                <span class="text-sm text-gray-900 dark:text-gray-100 truncate" x-text="contractor.name"></span>
                            </div>
                        </div>
	                        <div class="flex items-center gap-1 flex-shrink-0">
                            <span x-show="contractor.transmission_type === 'JX_FINET'"
                                  class="inline-flex items-center px-1 py-0.5 rounded text-[10px] font-medium bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400">
                                JX
                            </span>
                            <span x-show="contractor.transmission_parent_code"
                                  class="inline-flex items-center px-1 py-0.5 rounded text-[10px] font-medium bg-amber-100 text-amber-700 dark:bg-amber-900/30 dark:text-amber-400">
                                集約元
                            </span>
	                            <span x-show="contractor.transmission_type === 'INTERNAL'"
	                                  class="inline-flex items-center px-1 py-0.5 rounded text-[10px] font-medium bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400">
	                                移動
                            </span>
                            <span x-show="contractor.generation_time"
                                  class="text-[10px] text-gray-400 dark:text-gray-500 font-mono"
                                  x-text="contractor.generation_time"></span>
                        </div>
                    </label>
                </template>
            </div>
        </div>

        <div x-show="selectedCount === 0" class="text-sm text-danger-600 dark:text-danger-400">
            ※ 最低1つの仕入先を選択してください
        </div>
    </div>
</div>
