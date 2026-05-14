<x-filament-panels::page>
    <div wire:key="data-files-table-{{ $this->activePresetView }}">
        <div class="mb-6 -mt-6">
            <x-advanced-tables::favorites-bar />
        </div>

        {{ $this->table }}
    </div>
</x-filament-panels::page>
