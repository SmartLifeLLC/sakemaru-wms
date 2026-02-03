{{-- Add Zone Modal --}}
<div x-show="showAddZoneModal" x-cloak
     class="fixed inset-0 flex items-center justify-center"
     style="z-index: 10000;"
     @click.self="showAddZoneModal = false">
    <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl w-full max-w-md" @click.stop>

        {{-- Modal Header --}}
        <div class="bg-[#1e3a5f] border-b border-gray-700 px-6 py-4 rounded-t-lg">
            <h3 class="text-lg font-bold text-white">ロケーション追加</h3>
        </div>

        {{-- Modal Body --}}
        <div class="p-6 space-y-4">
            {{-- Error Message --}}
            <div x-show="addZoneError" class="bg-red-100 border border-red-400 text-red-700 px-4 py-2 rounded text-sm">
                <span x-text="addZoneError"></span>
            </div>

            {{-- Warehouse (Read-only) --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">倉庫</label>
                <div class="w-full px-3 py-2 bg-gray-100 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-md text-gray-700 dark:text-gray-300">
                    @foreach($this->warehouses as $wh)
                        <span x-show="$wire.selectedWarehouseId == '{{ $wh->id }}'">{{ $wh->name }}</span>
                    @endforeach
                </div>
            </div>

            {{-- Floor (Read-only) --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">フロア</label>
                <div class="w-full px-3 py-2 bg-gray-100 dark:bg-gray-700 border border-gray-300 dark:border-gray-600 rounded-md text-gray-700 dark:text-gray-300">
                    @foreach($this->floors as $floor)
                        <span x-show="$wire.selectedFloorId == '{{ $floor->id }}'">{{ $floor->name }}</span>
                    @endforeach
                </div>
            </div>

            {{-- Code1 (通路) --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    通路 (code1) <span class="text-red-500">*</span>
                </label>
                <input type="text"
                       x-model="newZoneData.code1"
                       class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                       placeholder="例: A, B, 01"
                       maxlength="10">
            </div>

            {{-- Code2 (棚番号) --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    棚番号 (code2) <span class="text-red-500">*</span>
                </label>
                <input type="text"
                       x-model="newZoneData.code2"
                       class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                       placeholder="例: 001, 01"
                       maxlength="10">
            </div>

            {{-- Code3 (段) --}}
            <div>
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">
                    段 (code3)
                </label>
                <input type="text"
                       x-model="newZoneData.code3"
                       class="w-full px-3 py-2 border border-gray-300 dark:border-gray-600 rounded-md focus:ring-blue-500 focus:border-blue-500 dark:bg-gray-700 dark:text-white"
                       placeholder="例: 1A, 2B"
                       maxlength="10">
            </div>

            {{-- Preview --}}
            <div class="bg-blue-50 dark:bg-blue-900/20 p-3 rounded-lg">
                <label class="block text-sm font-medium text-gray-700 dark:text-gray-300 mb-1">ロケーションコード (プレビュー)</label>
                <div class="text-xl font-mono font-bold text-blue-600 dark:text-blue-400"
                     x-text="(newZoneData.code1 || '___') + (newZoneData.code2 || '___') + (newZoneData.code3 || '')">
                </div>
            </div>
        </div>

        {{-- Modal Footer --}}
        <div class="border-t border-gray-200 dark:border-gray-700 px-6 py-4 flex justify-end gap-2">
            <button @click="showAddZoneModal = false"
                    class="px-4 py-2 bg-gray-100 hover:bg-gray-200 dark:bg-gray-700 dark:hover:bg-gray-600 text-gray-700 dark:text-gray-300 rounded font-medium text-sm">
                キャンセル
            </button>
            <button @click="createZone()"
                    class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded font-medium text-sm">
                追加
            </button>
        </div>
    </div>
</div>
