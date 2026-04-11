<x-filament-widgets::widget>
    <div class="flex items-center gap-2">
        <x-heroicon-o-calendar class="w-5 h-5 text-gray-500 dark:text-gray-400" />
        <label for="global-filter-date" class="text-sm font-medium text-gray-700 dark:text-gray-300">表示基準日:</label>
        <input
            type="date"
            id="global-filter-date"
            wire:model.live="filterDate"
            class="text-sm border border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-100 rounded-md px-2 py-1 focus:border-primary-500 focus:ring-primary-500"
        />
    </div>
</x-filament-widgets::widget>
