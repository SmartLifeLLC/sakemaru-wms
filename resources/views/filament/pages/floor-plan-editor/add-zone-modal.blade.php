{{-- Add Zone Modal --}}
<x-modal.container size="md" alpine-var="showAddZoneModal" z-index="100">
    <x-modal.header icon="plus" title="ロケーション追加" alpine-var="showAddZoneModal" />
    <x-modal.content padding="6">
        <div class="space-y-4">
            {{-- Error Message --}}
            <div x-show="addZoneError" class="bg-red-50 dark:bg-red-900/30 border border-red-200 dark:border-red-800 text-red-700 dark:text-red-400 px-4 py-2 rounded-lg text-sm">
                <span x-text="addZoneError"></span>
            </div>

            {{-- Warehouse (Read-only) --}}
            <x-modal.form-group label="倉庫">
                <div class="w-full px-3 py-2 bg-slate-50 dark:bg-gray-900 border border-slate-200 dark:border-gray-700 rounded-lg text-slate-700 dark:text-gray-300 text-sm">
                    @foreach($this->warehouses as $wh)
                        <span x-show="$wire.selectedWarehouseId == '{{ $wh->id }}'">{{ $wh->name }}</span>
                    @endforeach
                </div>
            </x-modal.form-group>

            {{-- Floor (Read-only) --}}
            <x-modal.form-group label="フロア">
                <div class="w-full px-3 py-2 bg-slate-50 dark:bg-gray-900 border border-slate-200 dark:border-gray-700 rounded-lg text-slate-700 dark:text-gray-300 text-sm">
                    @foreach($this->floors as $floor)
                        <span x-show="$wire.selectedFloorId == '{{ $floor->id }}'">{{ $floor->name }}</span>
                    @endforeach
                </div>
            </x-modal.form-group>

            {{-- Code1 (通路) --}}
            <x-modal.form-group label="通路 (code1) *">
                <input type="text"
                       x-model="newZoneData.code1"
                       class="w-full border border-slate-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                       placeholder="例: A, B, 01"
                       maxlength="10">
            </x-modal.form-group>

            {{-- Code2 (棚番号) --}}
            <x-modal.form-group label="棚番号 (code2) *">
                <input type="text"
                       x-model="newZoneData.code2"
                       class="w-full border border-slate-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                       placeholder="例: 001, 01"
                       maxlength="10">
            </x-modal.form-group>

            {{-- Code3 (段) --}}
            <x-modal.form-group label="段 (code3)">
                <input type="text"
                       x-model="newZoneData.code3"
                       class="w-full border border-slate-200 dark:border-gray-700 dark:bg-gray-800 dark:text-gray-200 rounded-lg px-3 py-2 text-sm focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                       placeholder="例: 1A, 2B"
                       maxlength="10">
            </x-modal.form-group>

            {{-- Preview --}}
            <div class="bg-blue-50 dark:bg-blue-900/20 p-3 rounded-lg border border-blue-100 dark:border-blue-800">
                <label class="block text-xs font-medium text-slate-600 dark:text-gray-400 mb-1">ロケーションコード (プレビュー)</label>
                <div class="text-xl font-mono font-bold text-blue-600 dark:text-blue-400"
                     x-text="(newZoneData.code1 || '___') + (newZoneData.code2 || '___') + (newZoneData.code3 || '')">
                </div>
            </div>
        </div>
    </x-modal.content>
    <x-modal.footer>
        <div class="flex gap-2">
            <button @click="showAddZoneModal = false"
                    class="px-3 py-1.5 text-xs font-medium text-slate-600 dark:text-gray-400 bg-slate-100 dark:bg-gray-700 rounded hover:bg-slate-200 dark:hover:bg-gray-600">
                キャンセル
            </button>
            <button @click="createZone()"
                    class="px-3 py-1.5 text-xs font-medium text-white bg-blue-600 rounded hover:bg-blue-700">
                <i class="fa fa-plus mr-1"></i> 追加
            </button>
        </div>
    </x-modal.footer>
</x-modal.container>
