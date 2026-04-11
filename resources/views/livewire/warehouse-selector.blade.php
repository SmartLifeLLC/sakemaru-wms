<div x-data="{ open: false }" @click.away="open = false" class="relative">
    <button
        @click="open = !open"
        type="button"
        class="flex items-center gap-1.5 px-2.5 py-1 text-sm font-bold rounded-lg
               bg-slate-900/60 border border-slate-500/40
               text-white hover:bg-slate-700/80
               transition-colors whitespace-nowrap"
    >
        <div class="flex items-center gap-1.5 ml-auto">
            <i class="fa-solid fa-warehouse text-amber-400 flex-shrink-0 text-xs"></i>
            <span class="truncate">{{ $selectedWarehouseName }}</span>
        </div>
        <i class="fa-solid fa-chevron-down text-[9px] text-slate-400 flex-shrink-0 ml-1.5"></i>
    </button>

    <div
        x-show="open"
        x-transition:enter="transition ease-out duration-100"
        x-transition:enter-start="opacity-0 scale-95"
        x-transition:enter-end="opacity-100 scale-100"
        x-transition:leave="transition ease-in duration-75"
        x-transition:leave-start="opacity-100 scale-100"
        x-transition:leave-end="opacity-0 scale-95"
        x-cloak
        class="absolute right-0 z-50 mt-1 min-w-full w-64 max-h-80 overflow-y-auto
               bg-white dark:bg-gray-800
               border border-gray-200 dark:border-gray-700
               rounded-lg shadow-lg"
    >
        <div class="py-1">
            @foreach ($warehouses as $warehouse)
                <button
                    wire:click="selectWarehouse({{ $warehouse['id'] }})"
                    @click="open = false"
                    type="button"
                    class="flex items-center justify-between w-full px-3 py-2 text-sm text-left
                           transition-colors
                           {{ $selectedWarehouseId === $warehouse['id']
                               ? 'bg-primary-50 dark:bg-primary-900/20 text-primary-700 dark:text-primary-400 font-medium'
                               : 'text-gray-700 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/5' }}"
                >
                    <span>
                        <span class="text-gray-400 dark:text-gray-500 font-mono text-xs mr-1.5">[{{ $warehouse['code'] }}]</span>
                        {{ $warehouse['name'] }}
                    </span>
                    @if ($selectedWarehouseId === $warehouse['id'])
                        <x-heroicon-m-check class="w-4 h-4 text-primary-500" />
                    @endif
                </button>
            @endforeach
        </div>
    </div>
</div>
