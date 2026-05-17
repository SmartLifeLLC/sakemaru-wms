@php
    $lw = $getLivewire();
    $dataProperty = $categoriesProperty ?? 'categoriesData';
    $selectedProperty = $selectedProperty ?? 'selectedCategoryIds';
    $fallbackMethod = $fallbackMethod ?? null;
    $label = $label ?? '分類';
    $compactListHeight = (bool) ($compactListHeight ?? false);
    $externalOrderLayout = ($layout ?? null) === 'externalOrderGeneration';
    $data = $lw->{$dataProperty} ?? [];

    if (empty($data) && $fallbackMethod && method_exists($lw, $fallbackMethod)) {
        $data = $lw->{$fallbackMethod}();
        $lw->{$dataProperty} = $data;
    }

    $categoryIds = collect($data)->pluck('id')->values()->toArray();
    $selectedIds = $lw->{$selectedProperty} ?? $categoryIds;
@endphp

<div x-data="{
    searchQuery: '',
    categories: @js($data),
    selectedIds: @js($selectedIds),

    get filteredCategories() {
        if (!this.searchQuery) return this.categories;
        const q = this.searchQuery.normalize('NFKC').toLowerCase();

        return this.categories.filter(category =>
            String(category.code).normalize('NFKC').toLowerCase().includes(q) ||
            category.name.normalize('NFKC').toLowerCase().includes(q)
        );
    },

    get selectedCount() {
        return this.selectedIds.length;
    },

    get totalCount() {
        return this.categories.length;
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

    selectFiltered() {
        const ids = this.filteredCategories.map(category => category.id);
        this.selectedIds = [...new Set([...this.selectedIds, ...ids])];
    },

    deselectFiltered() {
        const ids = this.filteredCategories.map(category => category.id);
        this.selectedIds = this.selectedIds.filter(id => !ids.includes(id));
    },
}" x-effect="$wire.set(@js($selectedProperty), selectedIds)" @class([
    'overflow-x-hidden',
    'flex h-[24rem] flex-col overflow-hidden rounded-md border border-gray-200 bg-white shadow-sm dark:border-gray-700 dark:bg-gray-900' => $externalOrderLayout,
    'space-y-2' => ! $externalOrderLayout,
])>
    @if ($externalOrderLayout)
    <div class="border-b border-gray-200 bg-gray-50 p-3 dark:border-gray-700 dark:bg-gray-800">
        <div class="mb-2 flex items-center justify-between gap-3">
            <div class="min-w-0">
                <div class="truncate text-sm font-bold text-gray-800 dark:text-gray-100">{{ $label }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400" x-text="`${selectedCount}/${totalCount}件 選択中`"></div>
            </div>
            <div class="flex shrink-0 items-center gap-1">
                <button type="button"
                        @click="selectFiltered()"
                        class="rounded border border-gray-300 bg-gray-100 px-2 py-1 text-xs text-gray-700 transition hover:bg-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600">
                    全選択
                </button>
                <button type="button"
                        @click="deselectFiltered()"
                        class="rounded border border-gray-300 bg-gray-100 px-2 py-1 text-xs text-gray-700 transition hover:bg-gray-200 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600">
                    解除
                </button>
            </div>
        </div>

        <div class="relative">
            <input type="text"
                   x-model="searchQuery"
                   placeholder="分類CD・名前で検索..."
                   class="w-full rounded border-2 border-blue-400 bg-white py-1.5 pl-8 pr-3 text-sm shadow-sm outline-none focus:border-blue-500 focus:ring-1 focus:ring-blue-500 dark:border-blue-700 dark:bg-gray-900 dark:text-white">
            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-2.5">
                <x-heroicon-m-magnifying-glass class="h-4 w-4 text-blue-500" />
            </div>
        </div>
    </div>

    <div class="min-h-0 flex-1 overflow-y-auto bg-amber-50/30 dark:bg-slate-900">
        <template x-if="filteredCategories.length === 0">
            <div class="p-4 text-center text-sm text-gray-500 dark:text-gray-400">該当する分類がありません</div>
        </template>

        <div class="grid grid-cols-1">
            <template x-for="(category, index) in filteredCategories" :key="category.id">
                <label class="flex cursor-pointer items-center gap-2 border-b border-gray-100 px-3 py-2 text-sm transition hover:bg-amber-100/50 dark:border-gray-800 dark:hover:bg-slate-800"
                       :class="index % 2 === 0 ? 'bg-amber-50/50 dark:bg-slate-900' : 'bg-white dark:bg-slate-950'">
                    <input type="checkbox"
                           :checked="isSelected(category.id)"
                           @change="toggle(category.id)"
                           class="h-4 w-4 shrink-0 rounded border-gray-300 text-blue-600 shadow-sm focus:ring-blue-500 dark:border-gray-500 dark:bg-gray-700">
                    <span class="shrink-0 font-mono text-gray-500 dark:text-gray-400" x-text="category.code"></span>
                    <span class="min-w-0 truncate text-gray-900 dark:text-gray-100" x-text="category.name"></span>
                </label>
            </template>
        </div>
    </div>
    @else
    <div class="flex flex-col gap-2 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 dark:border-gray-600 dark:bg-gray-800">
        <div class="flex items-center justify-between gap-3">
            <div class="min-w-0">
                <div class="truncate text-xs font-semibold text-gray-800 dark:text-gray-100">{{ $label }}</div>
                <div class="text-xs text-gray-500 dark:text-gray-400" x-text="`${selectedCount}/${totalCount}件 選択中`"></div>
            </div>
            <div class="flex shrink-0 items-center gap-2">
                <button type="button"
                        @click="selectFiltered()"
                        class="rounded-md bg-gray-100 px-2 py-1 text-[11px] text-gray-700 transition hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600">
                    全選択
                </button>
                <button type="button"
                        @click="deselectFiltered()"
                        class="rounded-md bg-gray-100 px-2 py-1 text-[11px] text-gray-700 transition hover:bg-gray-200 dark:bg-gray-700 dark:text-gray-300 dark:hover:bg-gray-600">
                    解除
                </button>
            </div>
        </div>

        <div class="relative">
            <input type="text"
                   x-model="searchQuery"
                   placeholder="分類CD・名前で検索..."
                   class="w-full rounded-lg border-gray-300 py-1.5 pl-8 text-xs shadow-sm focus:border-primary-500 focus:ring-1 focus:ring-primary-500 dark:border-gray-600 dark:bg-gray-700 dark:text-white">
            <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-2.5">
                <x-heroicon-m-magnifying-glass class="h-4 w-4 text-gray-400" />
            </div>
        </div>
    </div>

    <div @class([
        'overflow-y-auto rounded-lg border border-gray-200 dark:border-gray-600',
        'h-[7.5rem]' => $compactListHeight,
        'h-[18rem]' => ! $compactListHeight,
    ])>
        <template x-if="filteredCategories.length === 0">
            <div class="p-4 text-center text-sm text-gray-500 dark:text-gray-400">該当する分類がありません</div>
        </template>

        <div class="grid grid-cols-1">
            <template x-for="(category, index) in filteredCategories" :key="category.id">
                <label @class([
                    'flex cursor-pointer items-center gap-2 border-b px-2 transition hover:bg-gray-100 dark:border-gray-600 dark:hover:bg-gray-700/50',
                    'py-1' => $compactListHeight,
                    'py-2' => ! $compactListHeight,
                ])
                       :class="{
                           'bg-primary-50 dark:bg-primary-900/20': isSelected(category.id),
                           'bg-gray-50 dark:bg-gray-800/50': !isSelected(category.id) && index % 2 === 1,
                       }">
                    <input type="checkbox"
                           :checked="isSelected(category.id)"
                           @change="toggle(category.id)"
                           class="shrink-0 rounded border-gray-300 text-primary-600 shadow-sm focus:ring-primary-500 dark:border-gray-500 dark:bg-gray-700">
                    <span class="shrink-0 font-mono text-[11px] font-semibold text-gray-500 dark:text-gray-400" x-text="category.code"></span>
                    <span class="min-w-0 truncate text-xs text-gray-900 dark:text-gray-100" x-text="category.name"></span>
                </label>
            </template>
        </div>
    </div>

    <div x-show="selectedCount === 0" class="text-xs text-danger-600 dark:text-danger-400">
        ※ 最低1つの{{ $label }}を選択してください
    </div>
    @endif
</div>
