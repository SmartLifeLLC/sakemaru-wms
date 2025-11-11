<x-filament-panels::page>
    <div x-data="floorPlanEditor()"
         x-init="init()"
         @wall-added.window="walls.push($event.detail.wall)"
         @fixed-area-added.window="fixedAreas.push($event.detail.fixedArea)"
         @layout-loaded.window="
             zones = Array.isArray($event.detail.zones) ? $event.detail.zones : [];
             walls = Array.isArray($event.detail.walls) ? $event.detail.walls : [];
             fixedAreas = Array.isArray($event.detail.fixedAreas) ? $event.detail.fixedAreas : [];
             zonePositions = {};
             if ($event.detail.canvasWidth && $event.detail.canvasHeight) {
                 $dispatch('canvas-size-updated', { width: $event.detail.canvasWidth, height: $event.detail.canvasHeight });
             }
         "
         class="h-full flex flex-col">
        {{-- Combined Toolbar and Canvas --}}
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow flex-1 flex flex-col" style="height: calc(100vh - 120px);">
            {{-- Toolbar --}}
            <div class="flex flex-wrap gap-2 items-center text-sm p-3 border-b border-gray-200 dark:border-gray-700">
                <select wire:model.live="selectedWarehouseId"
                    class="rounded-md border border-gray-300 dark:border-gray-600 text-sm px-3 py-1.5">
                    <option value="">倉庫を選択</option>
                    @foreach($this->warehouses as $wh)
                        <option value="{{ $wh->id }}">{{ $wh->name }}</option>
                    @endforeach
                </select>

                <select wire:model.live="selectedFloorId"
                    class="rounded-md border border-gray-300 dark:border-gray-600 text-sm px-3 py-1.5">
                    <option value="">フロアを選択</option>
                    @foreach($this->floors as $floor)
                        <option value="{{ $floor->id }}">{{ $floor->name }}</option>
                    @endforeach
                </select>

                <button @click="saveAllChanges()"
                    class="px-4 py-2 bg-green-500 hover:bg-green-600 text-white rounded-md text-sm font-medium">
                    保存
                </button>

                <button wire:click="addZone" title="区画追加"
                    class="p-2 bg-purple-500 hover:bg-purple-600 text-white rounded-md">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                    </svg>
                </button>

                <button @click="$wire.addWall()" title="壁追加"
                    class="p-2 bg-gray-500 hover:bg-gray-600 text-white rounded-md">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M3 14h18m-9-4v8"></path>
                    </svg>
                </button>

                <button @click="$wire.addFixedArea()" title="固定領域追加"
                    class="p-2 bg-yellow-500 hover:bg-yellow-600 text-white rounded-md">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                </button>

                <button wire:click="exportLayout" title="レイアウト出力(JSON)"
                    class="p-2 bg-blue-500 hover:bg-blue-600 text-white rounded-md">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                    </svg>
                </button>

                <button @click="$refs.importFile.click()" title="レイアウト取込(JSON)"
                    class="p-2 bg-indigo-500 hover:bg-indigo-600 text-white rounded-md">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L9 8m4-4v12"></path>
                    </svg>
                </button>
                <input type="file" x-ref="importFile" accept=".json" @change="handleImport($event)" class="hidden">

                <button @click="exportCSV()" title="CSV出力"
                    class="p-2 bg-emerald-500 hover:bg-emerald-600 text-white rounded-md">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                </button>

                <div class="border-l border-gray-300 dark:border-gray-600 h-6 mx-1"></div>

                <label class="flex items-center gap-1.5">
                    <input type="checkbox" x-model="gridEnabled" @change="updateGrid()"
                        class="rounded border-gray-300">
                    <span class="text-sm">GRID</span>
                </label>

                <label class="flex items-center gap-1">
                    <span class="text-sm">Size:</span>
                    <input type="number" x-model="gridSize" @change="updateGrid()" min="4"
                        class="w-14 rounded-md border border-gray-300 dark:border-gray-600 text-sm text-right px-2 py-1">
                </label>

                <label class="flex items-center gap-1">
                    <span class="text-sm">閾値:</span>
                    <input type="number" x-model="gridThreshold" min="0"
                        class="w-14 rounded-md border border-gray-300 dark:border-gray-600 text-sm text-right px-2 py-1">
                </label>

                <div class="border-l border-gray-300 dark:border-gray-600 h-6 mx-1"></div>

                <div x-data="{
                    tempWidth: {{ $canvasWidth }},
                    tempHeight: {{ $canvasHeight }},
                    async applySize() {
                        await $wire.updateCanvasSize(this.tempWidth, this.tempHeight);
                        // Wait for Livewire to update, then sync values
                        await this.$nextTick();
                        this.tempWidth = $wire.canvasWidth;
                        this.tempHeight = $wire.canvasHeight;
                    }
                }"
                @canvas-size-updated.window="
                    tempWidth = $event.detail.width;
                    tempHeight = $event.detail.height;
                "
                class="flex items-center gap-1">
                    <label class="flex items-center gap-1">
                        <span class="text-sm">幅:</span>
                        <input type="number" x-model.number="tempWidth" min="500" max="10000" step="100"
                            class="w-20 rounded-md border border-gray-300 dark:border-gray-600 text-sm text-right px-2 py-1">
                    </label>

                    <label class="flex items-center gap-1">
                        <span class="text-sm">高さ:</span>
                        <input type="number" x-model.number="tempHeight" min="500" max="10000" step="100"
                            class="w-20 rounded-md border border-gray-300 dark:border-gray-600 text-sm text-right px-2 py-1">
                    </label>

                    <button @click="applySize()"
                        class="px-2 py-1 bg-indigo-500 hover:bg-indigo-600 text-white rounded-md text-xs font-medium">
                        適用
                    </button>
                </div>

                <span x-show="selectedZones.length > 0" class="text-gray-600 dark:text-gray-400">
                    選択: <span x-text="selectedZones.length"></span>個
                </span>
            </div>

            {{-- Floor Plan Canvas --}}
            <div @mousedown="handleCanvasMouseDown($event)"
                 @mousemove="handleCanvasMouseMove($event)"
                 @mouseup="handleCanvasMouseUp($event)"
                 @contextmenu.prevent
                 class="relative overflow-auto flex-1 bg-gray-50 dark:bg-gray-900"
                 :style="canvasStyle"
                 id="floor-plan-canvas"
                 style="min-height: 0;">

                {{-- Canvas Inner Container with minimum size from Livewire --}}
                <div class="relative" style="min-width: {{ $canvasWidth }}px; min-height: {{ $canvasHeight }}px;">

                {{-- Zone Blocks (Locations) --}}
                <template x-for="zone in zones" :key="zone.id">
                    <div @mousedown.stop="handleZoneMouseDown($event, zone)"
                         @click="selectZone($event, zone)"
                         @dblclick="editZone(zone)"
                         :style="`
                             position: absolute;
                             left: ${zone.x1_pos}px;
                             top: ${zone.y1_pos}px;
                             width: ${zone.x2_pos - zone.x1_pos}px;
                             height: ${zone.y2_pos - zone.y1_pos}px;
                             background-color: {{ $colors['location']['rectangle'] ?? '#E0F2FE' }};
                             border-color: {{ $colors['location']['border'] ?? '#D1D5DB' }};
                             color: {{ $textStyles['location']['color'] ?? '#6B7280' }};
                             font-size: {{ $textStyles['location']['size'] ?? 12 }}px;
                         `"
                         :class="selectedZones.includes(zone.id) ? 'border-2 border-blue-900' : 'border'"
                         class="cursor-move flex flex-col items-center justify-center p-2 rounded shadow-sm select-none">

                        <div x-text="zone.code1 + zone.code2"></div>

                        {{-- Resize Handle --}}
                        <div @mousedown.stop="handleResizeMouseDown($event, zone)"
                             class="absolute bottom-0 right-0 w-4 h-4 cursor-se-resize">
                        </div>
                    </div>
                </template>

                {{-- Walls --}}
                <template x-for="wall in walls" :key="wall.id">
                    <div @mousedown.stop="handleWallMouseDown($event, wall)"
                         @click="selectWall($event, wall)"
                         @dblclick.stop="editWall(wall)"
                         :style="`
                             position: absolute;
                             left: ${wall.x1}px;
                             top: ${wall.y1}px;
                             width: ${wall.x2 - wall.x1}px;
                             height: ${wall.y2 - wall.y1}px;
                             background-color: {{ $colors['wall']['rectangle'] ?? '#9CA3AF' }};
                             border-width: ${selectedWalls.includes(wall.id) ? '3px' : '1px'};
                             border-style: solid;
                             border-color: ${selectedWalls.includes(wall.id) ? '#374151' : '{{ $colors['wall']['border'] ?? '#6B7280' }}'};
                             color: {{ $textStyles['wall']['color'] ?? '#FFFFFF' }};
                             font-size: {{ $textStyles['wall']['size'] ?? 10 }}px;
                         `"
                         class="flex items-center justify-center rounded select-none cursor-move">
                        <div x-text="wall.name"></div>
                        <div @mousedown.stop="handleWallResizeMouseDown($event, wall)"
                             class="absolute bottom-0 right-0 w-4 h-4 cursor-se-resize">
                        </div>
                    </div>
                </template>

                {{-- Fixed Areas --}}
                <template x-for="area in fixedAreas" :key="area.id">
                    <div @mousedown.stop="handleFixedAreaMouseDown($event, area)"
                         @click="selectFixedArea($event, area)"
                         @dblclick.stop="editFixedArea(area)"
                         :style="`
                             position: absolute;
                             left: ${area.x1}px;
                             top: ${area.y1}px;
                             width: ${area.x2 - area.x1}px;
                             height: ${area.y2 - area.y1}px;
                             background-color: {{ $colors['fixed_area']['rectangle'] ?? '#FEF3C7' }};
                             border-width: ${selectedFixedAreas.includes(area.id) ? '4px' : '2px'};
                             border-style: solid;
                             border-color: ${selectedFixedAreas.includes(area.id) ? '#B45309' : '{{ $colors['fixed_area']['border'] ?? '#F59E0B' }}'};
                             color: {{ $textStyles['fixed_area']['color'] ?? '#92400E' }};
                             font-size: {{ $textStyles['fixed_area']['size'] ?? 12 }}px;
                         `"
                         class="flex items-center justify-center rounded-lg select-none font-medium cursor-move">
                        <div x-text="area.name"></div>
                        <div @mousedown.stop="handleFixedAreaResizeMouseDown($event, area)"
                             class="absolute bottom-0 right-0 w-4 h-4 cursor-se-resize">
                        </div>
                    </div>
                </template>
                </div>
            </div>
        </div>

        {{-- Detail Modal --}}
        <div x-show="showEditModal" x-cloak
             class="fixed inset-0 flex items-center justify-center z-50"
             @click.self="showEditModal = false">
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl p-6 w-full max-w-3xl max-h-[90vh] overflow-y-auto"
                 @click.stop>
                <h3 class="text-lg font-bold mb-4">区画詳細</h3>

                {{-- Basic Info --}}
                <div class="grid grid-cols-2 gap-4 mb-6">
                    <div>
                        <label class="block text-sm font-medium mb-1">通路 (code1)</label>
                        <input type="text" x-model="editingZone.code1" maxlength="10"
                            class="w-full rounded-md border-gray-300 dark:border-gray-600">
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1">棚番号 (code2)</label>
                        <input type="text" x-model="editingZone.code2" maxlength="10"
                            class="w-full rounded-md border-gray-300 dark:border-gray-600">
                    </div>

                    <div class="col-span-2">
                        <label class="block text-sm font-medium mb-1">名称</label>
                        <input type="text" x-model="editingZone.name"
                            class="w-full rounded-md border-gray-300 dark:border-gray-600">
                    </div>

                    <div class="col-span-2">
                        <label class="block text-sm font-medium mb-1">引当可能単位</label>
                        <select x-model.number="editingZone.available_quantity_flags"
                            class="w-full rounded-md border-gray-300 dark:border-gray-600">
                            <option value="1">ケース</option>
                            <option value="2">バラ</option>
                            <option value="3">ケース+バラ</option>
                            <option value="4">ボール</option>
                        </select>
                    </div>
                </div>

                {{-- Shelf Levels Tabs --}}
                <div class="border-t border-gray-200 dark:border-gray-700 pt-4">
                    <h4 class="text-md font-semibold mb-3">棚段別在庫情報</h4>

                    {{-- Tab Headers --}}
                    <div class="flex border-b border-gray-200 dark:border-gray-700 mb-4">
                        <template x-for="level in (editingZone.levels || 3)" :key="level">
                            <button @click="selectedLevel = level"
                                :class="{
                                    'border-b-2 border-blue-500 text-blue-600': selectedLevel === level,
                                    'text-gray-500 hover:text-gray-700': selectedLevel !== level
                                }"
                                class="px-4 py-2 font-medium text-sm transition-colors">
                                <span x-text="`${level}段目`"></span>
                            </button>
                        </template>
                    </div>

                    {{-- Tab Content --}}
                    <div class="space-y-4">
                        <template x-if="levelStocks && levelStocks[selectedLevel]">
                            <div>
                                {{-- Stock Summary --}}
                                <div class="grid grid-cols-3 gap-4 mb-4">
                                    <div class="bg-blue-50 dark:bg-blue-900/20 rounded-lg p-4">
                                        <div class="text-xs text-gray-600 dark:text-gray-400 mb-1">実在庫数</div>
                                        <div class="text-2xl font-bold text-blue-600" x-text="levelStocks[selectedLevel]?.current_qty || 0"></div>
                                    </div>
                                    <div class="bg-yellow-50 dark:bg-yellow-900/20 rounded-lg p-4">
                                        <div class="text-xs text-gray-600 dark:text-gray-400 mb-1">引当中</div>
                                        <div class="text-2xl font-bold text-yellow-600" x-text="levelStocks[selectedLevel]?.reserved_qty || 0"></div>
                                    </div>
                                    <div class="bg-green-50 dark:bg-green-900/20 rounded-lg p-4">
                                        <div class="text-xs text-gray-600 dark:text-gray-400 mb-1">引当可能数</div>
                                        <div class="text-2xl font-bold text-green-600" x-text="levelStocks[selectedLevel]?.available_qty || 0"></div>
                                    </div>
                                </div>

                                {{-- Stock Items List --}}
                                <template x-if="levelStocks[selectedLevel]?.items && levelStocks[selectedLevel].items.length > 0">
                                    <div>
                                        <h5 class="text-sm font-semibold mb-2">商品別在庫</h5>
                                        <div class="border border-gray-200 dark:border-gray-700 rounded-lg overflow-hidden">
                                            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                                                <thead class="bg-gray-50 dark:bg-gray-900">
                                                    <tr>
                                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">商品コード</th>
                                                        <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">商品名</th>
                                                        <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">実在庫</th>
                                                        <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">引当中</th>
                                                        <th class="px-3 py-2 text-right text-xs font-medium text-gray-500 uppercase">引当可能</th>
                                                    </tr>
                                                </thead>
                                                <tbody class="bg-white dark:bg-gray-800 divide-y divide-gray-200 dark:divide-gray-700">
                                                    <template x-for="item in levelStocks[selectedLevel].items" :key="item.item_id">
                                                        <tr>
                                                            <td class="px-3 py-2 text-sm" x-text="item.item_code"></td>
                                                            <td class="px-3 py-2 text-sm" x-text="item.item_name"></td>
                                                            <td class="px-3 py-2 text-sm text-right" x-text="item.current_qty"></td>
                                                            <td class="px-3 py-2 text-sm text-right" x-text="item.reserved_qty"></td>
                                                            <td class="px-3 py-2 text-sm text-right font-semibold" x-text="item.available_qty"></td>
                                                        </tr>
                                                    </template>
                                                </tbody>
                                            </table>
                                        </div>
                                    </div>
                                </template>

                                <template x-if="!levelStocks[selectedLevel]?.items || levelStocks[selectedLevel].items.length === 0">
                                    <div class="text-center py-8 text-gray-500">
                                        <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4" />
                                        </svg>
                                        <p class="mt-2">この段には在庫がありません</p>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>
                </div>

                <div class="flex gap-2 mt-6 pt-4 border-t border-gray-200 dark:border-gray-700">
                    <button @click="saveEditedZone()"
                        class="flex-1 px-4 py-2 bg-green-500 hover:bg-green-600 text-white rounded-md">
                        保存
                    </button>
                    <button @click="deleteZone()"
                        class="flex-1 px-4 py-2 bg-red-500 hover:bg-red-600 text-white rounded-md">
                        削除
                    </button>
                    <button @click="showEditModal = false"
                        class="flex-1 px-4 py-2 bg-gray-500 hover:bg-gray-600 text-white rounded-md">
                        閉じる
                    </button>
                </div>
            </div>
        </div>

        {{-- Settings Modal --}}
        <div x-data="{ showSettingsModal: false }" x-show="showSettingsModal" x-cloak
             class="fixed inset-0 flex items-center justify-center z-50 bg-black bg-opacity-50"
             @click.self="showSettingsModal = false">
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl p-6 w-full max-w-4xl max-h-[90vh] overflow-y-auto"
                 @click.stop>
                <h3 class="text-lg font-bold mb-6">レイアウト設定</h3>

                {{-- Canvas Size --}}
                <div class="mb-6">
                    <h4 class="text-md font-semibold mb-3">キャンバスサイズ</h4>
                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium mb-1">幅 (px)</label>
                            <input type="number" wire:model.live="canvasWidth" min="1000" max="10000" step="100"
                                class="w-full rounded-md border-gray-300 dark:border-gray-600">
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">高さ (px)</label>
                            <input type="number" wire:model.live="canvasHeight" min="1000" max="10000" step="100"
                                class="w-full rounded-md border-gray-300 dark:border-gray-600">
                        </div>
                    </div>
                </div>

                {{-- Location Colors --}}
                <div class="mb-6">
                    <h4 class="text-md font-semibold mb-3">ロケーション（区画）</h4>
                    <div class="grid grid-cols-4 gap-4">
                        <div>
                            <label class="block text-sm font-medium mb-1">境界線色</label>
                            <input type="color" wire:model.live="colors.location.border"
                                class="w-full h-10 rounded-md border-gray-300">
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">背景色</label>
                            <input type="color" wire:model.live="colors.location.rectangle"
                                class="w-full h-10 rounded-md border-gray-300">
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">文字色</label>
                            <input type="color" wire:model.live="textStyles.location.color"
                                class="w-full h-10 rounded-md border-gray-300">
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">文字サイズ (px)</label>
                            <input type="number" wire:model.live="textStyles.location.size" min="8" max="24"
                                class="w-full rounded-md border-gray-300 dark:border-gray-600">
                        </div>
                    </div>
                </div>

                {{-- Wall Colors --}}
                <div class="mb-6">
                    <h4 class="text-md font-semibold mb-3">壁・柱</h4>
                    <div class="grid grid-cols-4 gap-4">
                        <div>
                            <label class="block text-sm font-medium mb-1">境界線色</label>
                            <input type="color" wire:model.live="colors.wall.border"
                                class="w-full h-10 rounded-md border-gray-300">
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">背景色</label>
                            <input type="color" wire:model.live="colors.wall.rectangle"
                                class="w-full h-10 rounded-md border-gray-300">
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">文字色</label>
                            <input type="color" wire:model.live="textStyles.wall.color"
                                class="w-full h-10 rounded-md border-gray-300">
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">文字サイズ (px)</label>
                            <input type="number" wire:model.live="textStyles.wall.size" min="8" max="24"
                                class="w-full rounded-md border-gray-300 dark:border-gray-600">
                        </div>
                    </div>
                </div>

                {{-- Fixed Area Colors --}}
                <div class="mb-6">
                    <h4 class="text-md font-semibold mb-3">固定領域（エレベーター、荷下ろし場など）</h4>
                    <div class="grid grid-cols-4 gap-4">
                        <div>
                            <label class="block text-sm font-medium mb-1">境界線色</label>
                            <input type="color" wire:model.live="colors.fixed_area.border"
                                class="w-full h-10 rounded-md border-gray-300">
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">背景色</label>
                            <input type="color" wire:model.live="colors.fixed_area.rectangle"
                                class="w-full h-10 rounded-md border-gray-300">
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">文字色</label>
                            <input type="color" wire:model.live="textStyles.fixed_area.color"
                                class="w-full h-10 rounded-md border-gray-300">
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">文字サイズ (px)</label>
                            <input type="number" wire:model.live="textStyles.fixed_area.size" min="8" max="24"
                                class="w-full rounded-md border-gray-300 dark:border-gray-600">
                        </div>
                    </div>
                </div>

                {{-- Buttons --}}
                <div class="flex gap-2 pt-4 border-t border-gray-200 dark:border-gray-700">
                    <button wire:click="saveLayout"
                        class="flex-1 px-4 py-2 bg-green-500 hover:bg-green-600 text-white rounded-md">
                        設定を保存
                    </button>
                    <button @click="showSettingsModal = false"
                        class="flex-1 px-4 py-2 bg-gray-500 hover:bg-gray-600 text-white rounded-md">
                        閉じる
                    </button>
                </div>
            </div>
        </div>

        {{-- Wall Edit Modal --}}
        <template x-if="showWallEditModal">
        <div class="fixed inset-0 flex items-center justify-center z-50"
             @click.self="cancelWallEdit()">
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl p-6 w-full max-w-md"
                 @click.stop>
                <h3 class="text-lg font-bold mb-4">柱の名前を編集</h3>

                <div class="mb-4">
                    <label class="block text-sm font-medium mb-1">名前</label>
                    <input type="text" x-model="editingWall.name"
                        class="w-full rounded-md border-gray-300 dark:border-gray-600"
                        @keydown.enter="saveWallEdit()"
                        @keydown.escape="cancelWallEdit()">
                </div>

                <div class="flex gap-2 pt-4 border-t border-gray-200 dark:border-gray-700">
                    <button @click="saveWallEdit()"
                        class="flex-1 px-4 py-2 bg-blue-500 hover:bg-blue-600 text-white rounded-md">
                        保存
                    </button>
                    <button @click="deleteWall()"
                        class="flex-1 px-4 py-2 bg-red-500 hover:bg-red-600 text-white rounded-md">
                        削除
                    </button>
                    <button @click="cancelWallEdit()"
                        class="flex-1 px-4 py-2 bg-gray-500 hover:bg-gray-600 text-white rounded-md">
                        キャンセル
                    </button>
                </div>
            </div>
        </div>
    </template>

    {{-- Fixed Area Edit Modal --}}
    <template x-if="showFixedAreaEditModal">
        <div class="fixed inset-0 flex items-center justify-center z-50"
             @click.self="cancelFixedAreaEdit()">
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl p-6 w-full max-w-md"
                 @click.stop>
                <h3 class="text-lg font-bold mb-4">固定領域の名前を編集</h3>

                <div class="mb-4">
                    <label class="block text-sm font-medium mb-1">名前</label>
                    <input type="text" x-model="editingFixedArea.name"
                        class="w-full rounded-md border-gray-300 dark:border-gray-600"
                        @keydown.enter="saveFixedAreaEdit()"
                        @keydown.escape="cancelFixedAreaEdit()">
                </div>

                <div class="flex gap-2 pt-4 border-t border-gray-200 dark:border-gray-700">
                    <button @click="saveFixedAreaEdit()"
                        class="flex-1 px-4 py-2 bg-blue-500 hover:bg-blue-600 text-white rounded-md">
                        保存
                    </button>
                    <button @click="deleteFixedArea()"
                        class="flex-1 px-4 py-2 bg-red-500 hover:bg-red-600 text-white rounded-md">
                        削除
                    </button>
                    <button @click="cancelFixedAreaEdit()"
                        class="flex-1 px-4 py-2 bg-gray-500 hover:bg-gray-600 text-white rounded-md">
                        キャンセル
                    </button>
                </div>
            </div>
        </div>
        </template>
    </div>

    @push('scripts')
    <script>
        function floorPlanEditor() {
            return {
                warehouses: [],
                floors: [],
                zones: [],
                walls: [],
                fixedAreas: [],
                zonePositions: {}, // Track zone position changes {zoneId: {x1, y1, x2, y2}}
                selectedWarehouseId: '',
                selectedFloorId: '',
                gridEnabled: true,
                gridSize: 20,
                gridThreshold: 6,
                selectedZones: [],
                selectedWalls: [],
                selectedFixedAreas: [],
                dragState: null,
                resizeState: null,
                showEditModal: false,
                editingZone: {},
                selectedLevel: 1,
                levelStocks: {},
                showWallEditModal: false,
                editingWall: {},
                showFixedAreaEditModal: false,
                editingFixedArea: {},

                init() {
                    // Request initial data from Livewire
                    this.$nextTick(() => {
                        this.$wire.loadInitialData();
                    });
                },

                get canvasStyle() {
                    if (!this.gridEnabled || this.gridSize < 4) {
                        return { backgroundImage: 'none' };
                    }
                    return {
                        backgroundImage: `linear-gradient(to right, rgba(0,0,0,0.06) 1px, transparent 1px),
                                        linear-gradient(to bottom, rgba(0,0,0,0.06) 1px, transparent 1px)`,
                        backgroundSize: `${this.gridSize}px ${this.gridSize}px`
                    };
                },

                async loadWarehouses() {
                    try {
                        const response = await fetch('/api/warehouses');
                        const data = await response.json();
                        this.warehouses = data.data || [];

                        if (this.selectedWarehouseId) {
                            await this.loadFloors();
                        }
                    } catch (error) {
                        console.error('Failed to load warehouses:', error);
                    }
                },

                async loadFloors() {
                    if (!this.selectedWarehouseId) return;

                    try {
                        const response = await fetch(`/api/warehouses/${this.selectedWarehouseId}/floors`);
                        const data = await response.json();
                        this.floors = data.data || [];

                        if (this.floors.length > 0 && !this.selectedFloorId) {
                            this.selectedFloorId = this.floors[0].id;
                            await this.switchFloor();
                        }
                    } catch (error) {
                        console.error('Failed to load floors:', error);
                    }
                },

                async switchFloor() {
                    // This method is no longer needed for loading zones
                    // Livewire will dispatch 'layout-loaded' event automatically
                    // when floor changes via wire:model.live
                },

                async loadAndPlaceUnpositionedLocations() {
                    if (!this.selectedFloorId) return;

                    try {
                        const response = await fetch(`/api/floors/${this.selectedFloorId}/unpositioned-locations`);
                        const data = await response.json();
                        const unpositionedLocations = data.data || [];

                        // Automatically place unpositioned locations at center
                        const canvas = document.getElementById('floor-plan-canvas');
                        const zoneWidth = 60;
                        const zoneHeight = 40;

                        // Calculate canvas center (use fixed center coordinates)
                        const centerX = 400; // Fixed center X
                        const centerY = 300; // Fixed center Y

                        let offsetX = 0;
                        let offsetY = 0;

                        unpositionedLocations.forEach((location, index) => {
                            // Arrange in a grid pattern around center
                            const col = index % 5; // 5 columns
                            const row = Math.floor(index / 5);

                            const x = centerX + (col * (zoneWidth + 10)) + offsetX;
                            const y = centerY + (row * (zoneHeight + 10)) + offsetY;

                            const snappedX = this.snapToGrid(Math.max(0, x));
                            const snappedY = this.snapToGrid(Math.max(0, y));

                            // Check if zone with same code1+code2 exists
                            const existingZone = this.zones.find(z =>
                                z.code1 === location.code1 && z.code2 === location.code2
                            );

                            if (!existingZone) {
                                // Create new zone at center
                                const newZone = {
                                    id: location.id,
                                    floor_id: location.floor_id,
                                    warehouse_id: location.warehouse_id,
                                    code1: location.code1,
                                    code2: location.code2,
                                    name: location.name,
                                    x1_pos: snappedX,
                                    y1_pos: snappedY,
                                    x2_pos: snappedX + zoneWidth,
                                    y2_pos: snappedY + zoneHeight,
                                    available_quantity_flags: location.available_quantity_flags,
                                    levels: 1,
                                    stock_count: location.stock_count || 0,
                                    isNew: false
                                };
                                this.zones.push(newZone);
                            }
                        });
                    } catch (error) {
                        console.error('Failed to load unpositioned locations:', error);
                    }
                },

                placeLocationInCenter(location) {
                    if (!this.selectedFloorId) {
                        alert('フロアを選択してください');
                        return;
                    }

                    const canvas = document.getElementById('floor-plan-canvas');

                    // Zone size (smaller blocks)
                    const zoneWidth = 60;
                    const zoneHeight = 40;

                    // Calculate center position (viewport center + scroll offset)
                    const viewportWidth = canvas.clientWidth;
                    const viewportHeight = canvas.clientHeight;
                    const scrollX = canvas.scrollLeft;
                    const scrollY = canvas.scrollTop;

                    const centerX = scrollX + (viewportWidth / 2) - (zoneWidth / 2);
                    const centerY = scrollY + (viewportHeight / 2) - (zoneHeight / 2);

                    // Snap to grid
                    const snappedX = this.snapToGrid(Math.max(0, centerX));
                    const snappedY = this.snapToGrid(Math.max(0, centerY));

                    // Check if a zone with the same code1+code2 already exists
                    const existingZone = this.zones.find(z =>
                        z.code1 === location.code1 && z.code2 === location.code2
                    );

                    if (existingZone) {
                        // Update existing zone's position and increment levels
                        existingZone.x1_pos = snappedX;
                        existingZone.y1_pos = snappedY;
                        existingZone.x2_pos = snappedX + zoneWidth;
                        existingZone.y2_pos = snappedY + zoneHeight;
                        existingZone.levels = (existingZone.levels || 1) + 1;
                        existingZone.stock_count = (existingZone.stock_count || 0) + (location.stock_count || 0);

                        console.log('Updated existing zone:', existingZone);
                    } else {
                        // Create a new zone from the unpositioned location
                        const newZone = {
                            id: location.id,
                            floor_id: location.floor_id,
                            warehouse_id: location.warehouse_id,
                            code1: location.code1,
                            code2: location.code2,
                            name: location.name,
                            x1_pos: snappedX,
                            y1_pos: snappedY,
                            x2_pos: snappedX + zoneWidth,
                            y2_pos: snappedY + zoneHeight,
                            available_quantity_flags: location.available_quantity_flags,
                            levels: 1,
                            stock_count: location.stock_count,
                            isNew: false
                        };

                        // Add to zones
                        this.zones.push(newZone);

                        console.log('Created new zone:', newZone);
                    }
                },

                updateGrid() {
                    // Grid style is computed property, just trigger re-render
                },

                snapToGrid(value) {
                    if (!this.gridEnabled) return value;
                    const remainder = value % this.gridSize;
                    if (remainder < this.gridThreshold) {
                        return value - remainder;
                    } else if (remainder > this.gridSize - this.gridThreshold) {
                        return value + (this.gridSize - remainder);
                    }
                    return value;
                },

                addZone() {
                    if (!this.selectedFloorId) {
                        alert('フロアを選択してください');
                        return;
                    }

                    const newZone = {
                        id: 'temp_' + Date.now(),
                        floor_id: this.selectedFloorId,
                        warehouse_id: this.selectedWarehouseId,
                        code1: 'A',
                        code2: String(this.zones.length + 1).padStart(3, '0'),
                        name: 'NEW ZONE',
                        x1_pos: 20,
                        y1_pos: 20,
                        x2_pos: 80,
                        y2_pos: 60,
                        available_quantity_flags: 3,
                        levels: 1,
                        stock_count: 0,
                        isNew: true
                    };

                    this.zones.push(newZone);
                    this.selectedZones = [newZone.id];
                },

                selectZone(event, zone) {
                    // Clear other selections
                    this.selectedWalls = [];
                    this.selectedFixedAreas = [];

                    if (event.ctrlKey || event.metaKey) {
                        const idx = this.selectedZones.indexOf(zone.id);
                        if (idx >= 0) {
                            this.selectedZones.splice(idx, 1);
                        } else {
                            this.selectedZones.push(zone.id);
                        }
                    } else {
                        this.selectedZones = [zone.id];
                    }
                },

                selectWall(event, wall) {
                    // Clear other selections
                    this.selectedZones = [];
                    this.selectedFixedAreas = [];

                    if (event.ctrlKey || event.metaKey) {
                        const idx = this.selectedWalls.indexOf(wall.id);
                        if (idx >= 0) {
                            this.selectedWalls.splice(idx, 1);
                        } else {
                            this.selectedWalls.push(wall.id);
                        }
                    } else {
                        this.selectedWalls = [wall.id];
                    }
                },

                selectFixedArea(event, area) {
                    // Clear other selections
                    this.selectedZones = [];
                    this.selectedWalls = [];

                    if (event.ctrlKey || event.metaKey) {
                        const idx = this.selectedFixedAreas.indexOf(area.id);
                        if (idx >= 0) {
                            this.selectedFixedAreas.splice(idx, 1);
                        } else {
                            this.selectedFixedAreas.push(area.id);
                        }
                    } else {
                        this.selectedFixedAreas = [area.id];
                    }
                },

                async editZone(zone) {
                    this.editingZone = { ...zone, levels: zone.levels || 3 };
                    this.selectedLevel = 1;
                    this.levelStocks = {};
                    this.showEditModal = true;

                    // Load stock data for each level
                    await this.loadLevelStocks(zone);
                },

                async loadLevelStocks(zone) {
                    if (!zone.id || zone.isNew) {
                        // No stock data for new zones
                        return;
                    }

                    try {
                        const response = await fetch(`/api/zones/${zone.id}/stocks`);
                        const data = await response.json();

                        if (data.success) {
                            this.levelStocks = data.data;
                        }
                    } catch (error) {
                        console.error('Failed to load stock data:', error);
                    }
                },

                saveEditedZone() {
                    const idx = this.zones.findIndex(z => z.id === this.editingZone.id);
                    if (idx >= 0) {
                        this.zones[idx] = { ...this.zones[idx], ...this.editingZone };
                    }
                    this.showEditModal = false;
                },

                handleCanvasMouseDown(event) {
                    if (event.button === 0 && !event.target.closest('[data-zone]')) {
                        this.selectedZones = [];
                    }
                },

                handleZoneMouseDown(event, zone) {
                    if (event.button !== 0) return;

                    this.dragState = {
                        zone,
                        startX: event.clientX,
                        startY: event.clientY,
                        originalX1: zone.x1_pos,
                        originalY1: zone.y1_pos,
                        originalX2: zone.x2_pos,
                        originalY2: zone.y2_pos
                    };

                    if (!this.selectedZones.includes(zone.id)) {
                        this.selectedZones = [zone.id];
                    }
                },

                handleResizeMouseDown(event, zone) {
                    this.resizeState = {
                        zone,
                        startX: event.clientX,
                        startY: event.clientY,
                        originalX2: zone.x2_pos,
                        originalY2: zone.y2_pos
                    };
                },

                handleWallMouseDown(event, wall) {
                    if (event.button !== 0) return;

                    this.dragState = {
                        wall,
                        startX: event.clientX,
                        startY: event.clientY,
                        originalX1: wall.x1,
                        originalY1: wall.y1,
                        originalX2: wall.x2,
                        originalY2: wall.y2
                    };
                },

                handleFixedAreaMouseDown(event, area) {
                    if (event.button !== 0) return;

                    this.dragState = {
                        fixedArea: area,
                        startX: event.clientX,
                        startY: event.clientY,
                        originalX1: area.x1,
                        originalY1: area.y1,
                        originalX2: area.x2,
                        originalY2: area.y2
                    };
                },

                handleWallResizeMouseDown(event, wall) {
                    if (event.button !== 0) return;

                    this.resizeState = {
                        wall,
                        startX: event.clientX,
                        startY: event.clientY,
                        originalX2: wall.x2,
                        originalY2: wall.y2
                    };
                },

                handleFixedAreaResizeMouseDown(event, area) {
                    if (event.button !== 0) return;

                    this.resizeState = {
                        fixedArea: area,
                        startX: event.clientX,
                        startY: event.clientY,
                        originalX2: area.x2,
                        originalY2: area.y2
                    };
                },

                handleCanvasMouseMove(event) {
                    if (this.dragState) {
                        const deltaX = event.clientX - this.dragState.startX;
                        const deltaY = event.clientY - this.dragState.startY;

                        const newX1 = this.snapToGrid(this.dragState.originalX1 + deltaX);
                        const newY1 = this.snapToGrid(this.dragState.originalY1 + deltaY);
                        const width = this.dragState.originalX2 - this.dragState.originalX1;
                        const height = this.dragState.originalY2 - this.dragState.originalY1;

                        // Handle zone dragging
                        if (this.dragState.zone) {
                            this.dragState.zone.x1_pos = Math.max(0, newX1);
                            this.dragState.zone.y1_pos = Math.max(0, newY1);
                            this.dragState.zone.x2_pos = this.dragState.zone.x1_pos + width;
                            this.dragState.zone.y2_pos = this.dragState.zone.y1_pos + height;
                        }
                        // Handle wall dragging
                        else if (this.dragState.wall) {
                            this.dragState.wall.x1 = Math.max(0, newX1);
                            this.dragState.wall.y1 = Math.max(0, newY1);
                            this.dragState.wall.x2 = this.dragState.wall.x1 + width;
                            this.dragState.wall.y2 = this.dragState.wall.y1 + height;
                        }
                        // Handle fixed area dragging
                        else if (this.dragState.fixedArea) {
                            this.dragState.fixedArea.x1 = Math.max(0, newX1);
                            this.dragState.fixedArea.y1 = Math.max(0, newY1);
                            this.dragState.fixedArea.x2 = this.dragState.fixedArea.x1 + width;
                            this.dragState.fixedArea.y2 = this.dragState.fixedArea.y1 + height;
                        }
                    } else if (this.resizeState) {
                        const deltaX = event.clientX - this.resizeState.startX;
                        const deltaY = event.clientY - this.resizeState.startY;

                        const newX2 = this.snapToGrid(this.resizeState.originalX2 + deltaX);
                        const newY2 = this.snapToGrid(this.resizeState.originalY2 + deltaY);

                        // Handle zone resizing
                        if (this.resizeState.zone) {
                            this.resizeState.zone.x2_pos = Math.max(this.resizeState.zone.x1_pos + 20, newX2);
                            this.resizeState.zone.y2_pos = Math.max(this.resizeState.zone.y1_pos + 20, newY2);
                        }
                        // Handle wall resizing
                        else if (this.resizeState.wall) {
                            this.resizeState.wall.x2 = Math.max(this.resizeState.wall.x1 + 20, newX2);
                            this.resizeState.wall.y2 = Math.max(this.resizeState.wall.y1 + 20, newY2);
                        }
                        // Handle fixed area resizing
                        else if (this.resizeState.fixedArea) {
                            this.resizeState.fixedArea.x2 = Math.max(this.resizeState.fixedArea.x1 + 20, newX2);
                            this.resizeState.fixedArea.y2 = Math.max(this.resizeState.fixedArea.y1 + 20, newY2);
                        }
                    }
                },

                handleCanvasMouseUp(event) {
                    // Track zone position changes for later save
                    if (this.dragState && this.dragState.zone) {
                        const zone = this.dragState.zone;
                        this.zonePositions[zone.id] = {
                            x1_pos: zone.x1_pos,
                            y1_pos: zone.y1_pos,
                            x2_pos: zone.x2_pos,
                            y2_pos: zone.y2_pos
                        };
                    }

                    if (this.resizeState && this.resizeState.zone) {
                        const zone = this.resizeState.zone;
                        this.zonePositions[zone.id] = {
                            x1_pos: zone.x1_pos,
                            y1_pos: zone.y1_pos,
                            x2_pos: zone.x2_pos,
                            y2_pos: zone.y2_pos
                        };
                    }

                    // Just clear drag state - don't save immediately
                    // Changes will be saved when user clicks "保存" button
                    this.dragState = null;
                    this.resizeState = null;
                },

                getZoneColor(zone) {
                    const stockCount = zone.stock_count || 0;
                    if (stockCount === 0) return '#f3f4f6';
                    if (stockCount < 50) return '#fecaca';
                    if (stockCount < 100) return '#fef9c3';
                    return '#bbf7d0';
                },

                deleteZone() {
                    if (confirm('この区画を削除しますか？')) {
                        const index = this.zones.findIndex(z => z.id === this.editingZone.id);
                        if (index !== -1) {
                            this.zones.splice(index, 1);
                        }
                        this.showEditModal = false;
                        this.editingZone = {};
                        // Remove from selection
                        this.selectedZones = this.selectedZones.filter(id => id !== this.editingZone.id);
                    }
                },

                saveAllChanges() {
                    // Send all changes to Livewire in a single call
                    // Only send zones that have been moved/resized
                    const changedZones = Object.keys(this.zonePositions).map(zoneId => ({
                        id: parseInt(zoneId),
                        ...this.zonePositions[zoneId]
                    }));

                    this.$wire.saveAllPositions(changedZones, this.walls, this.fixedAreas);

                    // Clear tracked changes after save
                    this.zonePositions = {};
                },

                exportCSV() {
                    const headers = ['ロケーションID', '通路', '棚', '段', '名称', '引当可能単位', '在庫数'];
                    const rows = [headers.join(',')];

                    this.zones.forEach(zone => {
                        const levels = zone.levels || 3;
                        for (let level = 1; level <= levels; level++) {
                            const locationId = `${zone.code1}${zone.code2}${level}`;
                            const quantityType = this.getQuantityTypeLabel(zone.available_quantity_flags);
                            rows.push([
                                locationId,
                                zone.code1,
                                zone.code2,
                                level,
                                zone.name,
                                quantityType,
                                level === 1 ? (zone.stock_count || 0) : ''
                            ].map(v => this.csvEscape(v)).join(','));
                        }
                    });

                    const csvContent = rows.join('\r\n');
                    const blob = new Blob(['\uFEFF' + csvContent], { type: 'text/csv;charset=utf-8;' });
                    const link = document.createElement('a');
                    const url = URL.createObjectURL(blob);
                    const timestamp = new Date().toISOString().slice(0, 19).replace(/[-:]/g, '').replace('T', '_');
                    link.href = url;
                    link.download = `floor_plan_export_${timestamp}.csv`;
                    link.click();
                    URL.revokeObjectURL(url);
                },

                csvEscape(value) {
                    const str = String(value || '');
                    if (/[",\n\r]/.test(str)) {
                        return '"' + str.replace(/"/g, '""') + '"';
                    }
                    return str;
                },

                getQuantityTypeLabel(flag) {
                    switch (flag) {
                        case 1: return 'ケース';
                        case 2: return 'バラ';
                        case 3: return 'ケース+バラ';
                        case 4: return 'ボール';
                        default: return '無し';
                    }
                },

                editWall(wall) {
                    this.editingWall = { ...wall };
                    this.showWallEditModal = true;
                },

                editFixedArea(area) {
                    this.editingFixedArea = { ...area };
                    this.showFixedAreaEditModal = true;
                },

                saveWallEdit() {
                    const index = this.walls.findIndex(w => w.id === this.editingWall.id);
                    if (index !== -1) {
                        this.walls[index].name = this.editingWall.name;
                    }
                    this.showWallEditModal = false;
                    this.editingWall = {};
                },

                saveFixedAreaEdit() {
                    const index = this.fixedAreas.findIndex(a => a.id === this.editingFixedArea.id);
                    if (index !== -1) {
                        this.fixedAreas[index].name = this.editingFixedArea.name;
                    }
                    this.showFixedAreaEditModal = false;
                    this.editingFixedArea = {};
                },

                deleteWall() {
                    if (confirm('この柱を削除しますか？')) {
                        const index = this.walls.findIndex(w => w.id === this.editingWall.id);
                        if (index !== -1) {
                            this.walls.splice(index, 1);
                        }
                        this.showWallEditModal = false;
                        this.editingWall = {};
                        // Remove from selection
                        this.selectedWalls = this.selectedWalls.filter(id => id !== this.editingWall.id);
                    }
                },

                deleteFixedArea() {
                    if (confirm('この固定領域を削除しますか？')) {
                        const index = this.fixedAreas.findIndex(a => a.id === this.editingFixedArea.id);
                        if (index !== -1) {
                            this.fixedAreas.splice(index, 1);
                        }
                        this.showFixedAreaEditModal = false;
                        this.editingFixedArea = {};
                        // Remove from selection
                        this.selectedFixedAreas = this.selectedFixedAreas.filter(id => id !== this.editingFixedArea.id);
                    }
                },

                cancelWallEdit() {
                    this.showWallEditModal = false;
                    this.editingWall = {};
                },

                cancelFixedAreaEdit() {
                    this.showFixedAreaEditModal = false;
                    this.editingFixedArea = {};
                },

                async handleImport(event) {
                    const file = event.target.files[0];
                    if (!file) {
                        return;
                    }

                    // Check file type
                    if (!file.name.endsWith('.json')) {
                        alert('JSONファイルを選択してください');
                        event.target.value = '';
                        return;
                    }

                    try {
                        // Read file content
                        const content = await file.text();
                        const layout = JSON.parse(content);

                        // Call Livewire method to import
                        await this.$wire.importLayoutData(layout);

                    } catch (error) {
                        console.error('Import error:', error);
                        alert('ファイルの読み込みに失敗しました: ' + error.message);
                    }

                    // Reset file input
                    event.target.value = '';
                }
            };
        }
    </script>
    @endpush

    <style>
        [x-cloak] { display: none !important; }
    </style>
</x-filament-panels::page>
