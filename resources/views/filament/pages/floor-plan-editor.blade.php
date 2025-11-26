<div class="livewire-root"><x-filament-panels::page>
    <div x-data="floorPlanEditor()"
         x-init="init()"
         @wall-added.window="walls.push($event.detail.wall)"
         @fixed-area-added.window="fixedAreas.push($event.detail.fixedArea)"
         @layout-loaded.window="
             zones = Array.isArray($event.detail.zones) ? $event.detail.zones : [];
             walls = Array.isArray($event.detail.walls) ? $event.detail.walls : [];
             fixedAreas = Array.isArray($event.detail.fixedAreas) ? $event.detail.fixedAreas : [];
             zonePositions = {};
             pickingAreas = Array.isArray($event.detail.pickingAreas) ? $event.detail.pickingAreas : [];
             if ($event.detail.canvasWidth && $event.detail.canvasHeight) {
                 $dispatch('canvas-size-updated', { width: $event.detail.canvasWidth, height: $event.detail.canvasHeight });
             }
             // Reload walkable areas when layout changes
             $nextTick(() => {
                 initWalkableCanvas($event.detail.walkableAreas, $event.detail.navmeta);
             });
         "
         class="h-full">

        {{-- Main Layout: Left (3/4) and Right (1/4) --}}
        <div class="flex gap-3" style="height: calc(100vh - 120px);">

            {{-- Left Side: Floor Plan Canvas (75%) --}}
            <div class="w-3/4 bg-white dark:bg-gray-800 rounded-lg shadow relative overflow-auto bg-gray-50 dark:bg-gray-900"
                 @mousedown="handleCanvasMouseDown($event)"
                 @mousemove="handleCanvasMouseMove($event)"
                 @mouseup="handleCanvasMouseUp($event)"
                 @mouseup="handleCanvasMouseUp($event)"
                 @click="handleCanvasClick($event)"
                 @dblclick="handleCanvasDoubleClick($event)"
                 @contextmenu.prevent
                 :style="canvasStyle"
                 id="floor-plan-canvas">

                {{-- Canvas Inner Container with minimum size from Livewire --}}
                <div class="relative" style="min-width: {{ $canvasWidth }}px; min-height: {{ $canvasHeight }}px;">

                {{-- Walkable Area Canvas Layer --}}
                <canvas x-ref="walkableCanvas"
                        :width="{{ $canvasWidth }}"
                        :height="{{ $canvasHeight }}"
                        @mousedown="handleWalkableMouseDown($event)"
                        @mousemove="handleWalkableMouseMove($event)"
                        @mouseup="handleWalkableMouseUp($event)"
                        @mouseleave="handleWalkableMouseUp($event)"
                        class="absolute inset-0 pointer-events-auto"
                        :class="walkablePaintMode ? 'z-10 cursor-crosshair' : 'z-0 pointer-events-none'"
                        style="opacity: 0.4;">
                </canvas>

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
                             border-color: ${zone.is_restricted_area ? '#EF4444' : (selectedZones.includes(zone.id) ? '#1E3A8A' : '{{ $colors['location']['border'] ?? '#D1D5DB' }}')};
                             border-width: ${zone.is_restricted_area ? '2px' : (selectedZones.includes(zone.id) ? '2px' : '1px')};
                             color: {{ $textStyles['location']['color'] ?? '#6B7280' }};
                             font-size: {{ $textStyles['location']['size'] ?? 12 }}px;
                         `"
                         class="cursor-move flex flex-col items-center justify-center p-2 rounded shadow-sm select-none border-solid">

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

                {{-- Picking Start Point --}}
                <div x-data="{ startX: @entangle('pickingStartX'), startY: @entangle('pickingStartY') }"
                     x-show="startX > 0 || startY > 0"
                     @mousedown.stop="handlePickingPointMouseDown($event, 'start')"
                     :style="{
                         position: 'absolute',
                         left: startX + 'px',
                         top: startY + 'px',
                         width: '40px',
                         height: '40px',
                         transform: 'translate(-20px, -20px)',
                         zIndex: 9999
                     }"
                     class="flex items-center justify-center rounded-full bg-green-500 border-4 border-white shadow-xl cursor-move hover:bg-green-600 select-none">
                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                    </svg>
                    <div class="absolute -bottom-6 left-1/2 transform -translate-x-1/2 text-xs font-bold text-green-600 whitespace-nowrap bg-white px-2 py-0.5 rounded shadow-md border border-green-200">開始</div>
                </div>

                {{-- Picking End Point --}}
                <div x-data="{ endX: @entangle('pickingEndX'), endY: @entangle('pickingEndY') }"
                     x-show="endX > 0 || endY > 0"
                     @mousedown.stop="handlePickingPointMouseDown($event, 'end')"
                     :style="{
                         position: 'absolute',
                         left: endX + 'px',
                         top: endY + 'px',
                         width: '40px',
                         height: '40px',
                         transform: 'translate(-20px, -20px)',
                         zIndex: 9999
                     }"
                     class="flex items-center justify-center rounded-full bg-red-500 border-4 border-white shadow-xl cursor-move hover:bg-red-600 select-none">
                    <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                    <div class="absolute -bottom-6 left-1/2 transform -translate-x-1/2 text-xs font-bold text-red-600 whitespace-nowrap bg-white px-2 py-0.5 rounded shadow-md border border-red-200">終了</div>
                </div>

                {{-- Picking Areas (Polygons) --}}
                <template x-for="area in pickingAreas" :key="area.id">
                    <svg class="absolute inset-0 pointer-events-none" :width="canvasWidth" :height="canvasHeight" style="z-index: 5;"
                         x-show="!hiddenPickingAreaIds.includes(area.id)">
                        <polygon :points="getPolygonPoints(area.polygon)"
                                 :fill="area.color || '#8B5CF6'"
                                 :stroke="area.color || '#8B5CF6'"
                                 fill-opacity="0.1"
                                 stroke-width="2"
                                 class="pointer-events-auto cursor-pointer hover:fill-opacity-30"
                                 @click.stop="selectPickingArea(area)">
                        </polygon>
                    </svg>
                </template>

                {{-- Drawing Picking Area (Preview) --}}
                <template x-if="pickingAreaMode === 'draw' && currentPolygonPoints.length > 0">
                    <svg class="absolute inset-0 pointer-events-none" :width="canvasWidth" :height="canvasHeight" style="z-index: 20;">
                        <polyline :points="getPreviewPoints()"
                                  fill="none"
                                  :stroke="newPickingAreaColor || '#8B5CF6'"
                                  stroke-width="2"
                                  stroke-dasharray="5,5">
                        </polyline>
                        
                        {{-- Snap Point Indicator --}}
                        <template x-if="snapPoint">
                            <circle :cx="snapPoint.x" :cy="snapPoint.y" r="10" fill="none" stroke="#10B981" stroke-width="3" />
                        </template>
                        {{-- SVG Circles removed, replaced by HTML overlays below --}}
                    </svg>
                </template>

                {{-- Drawing Points (HTML Overlays) --}}
                <template x-if="pickingAreaMode === 'draw'">
                    <div>
                        <template x-for="(point, index) in currentPolygonPoints" :key="index">
                            <div :style="{
                                     position: 'absolute',
                                     left: point.x + 'px',
                                     top: point.y + 'px',
                                     transform: 'translate(-50%, -50%)',
                                     zIndex: 30
                                 }"
                                 class="rounded-full border-2 border-white shadow-md bg-purple-500 w-4 h-4 flex items-center justify-center transition-all duration-200">
                            </div>
                        </template>
                    </div>
                </template>

                </div>
            </div>

            {{-- Right Side: Toolbar (25%) --}}
            <div class="w-1/4 bg-white dark:bg-gray-800 rounded-lg shadow p-3 flex flex-col gap-3 overflow-y-auto">
                <h3 class="text-sm font-semibold border-b border-gray-200 dark:border-gray-700 pb-2">設定</h3>

                {{-- Save Button (Full Width) --}}
                <button @click="saveAllChangesWithWalkable()"
                    class="w-full px-4 py-2 bg-green-500 hover:bg-green-600 text-white rounded-md text-sm font-medium">
                    保存
                </button>

                {{-- Warehouse & Floor Selection --}}
                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">倉庫</label>
                    <select wire:model.live="selectedWarehouseId"
                        class="w-full rounded-md border border-gray-300 dark:border-gray-600 text-sm px-3 py-1.5">
                        <option value="">倉庫を選択</option>
                        @foreach($this->warehouses as $wh)
                            <option value="{{ $wh->id }}">{{ $wh->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">フロア</label>
                    <select wire:model.live="selectedFloorId"
                        class="w-full rounded-md border border-gray-300 dark:border-gray-600 text-sm px-3 py-1.5">
                        <option value="">フロアを選択</option>
                        @foreach($this->floors as $floor)
                            <option value="{{ $floor->id }}">{{ $floor->name }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Tool Icons (Horizontal) --}}
                <div class="flex flex-wrap gap-3 justify-start">
                    {{-- Add Zone --}}
                    <div class="flex flex-col items-center gap-1">
                        <button @click="saveAllChanges(); $wire.addZone()" title="区画追加"
                            class="p-2 bg-purple-500 hover:bg-purple-600 text-white rounded-md shadow-sm transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                            </svg>
                        </button>
                        <span class="text-[10px] font-medium text-gray-600 dark:text-gray-400">Loc</span>
                    </div>

                    {{-- Add Wall --}}
                    <div class="flex flex-col items-center gap-1">
                        <button @click="saveAllChanges(); $wire.addWall()" title="壁追加"
                            class="p-2 bg-gray-500 hover:bg-gray-600 text-white rounded-md shadow-sm transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M3 14h18m-9-4v8"></path>
                            </svg>
                        </button>
                        <span class="text-[10px] font-medium text-gray-600 dark:text-gray-400">Wall</span>
                    </div>

                    {{-- Add Fixed Area --}}
                    <div class="flex flex-col items-center gap-1">
                        <button @click="saveAllChanges(); $wire.addFixedArea()" title="固定領域追加"
                            class="p-2 bg-yellow-500 hover:bg-yellow-600 text-white rounded-md shadow-sm transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                            </svg>
                        </button>
                        <span class="text-[10px] font-medium text-gray-600 dark:text-gray-400">Fix</span>
                    </div>

                    {{-- Walkable Area Paint --}}
                    <div class="flex flex-col items-center gap-1">
                        <button @click="toggleWalkablePaintMode()"
                                :title="walkablePaintMode === 'paint' ? '歩行領域ペイント中' : '歩行領域をペイント'"
                                :class="walkablePaintMode === 'paint' ? 'bg-green-600 hover:bg-green-700 ring-2 ring-green-300' : 'bg-green-500 hover:bg-green-600'"
                                class="p-2 text-white rounded-md shadow-sm transition-colors relative">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21a4 4 0 01-4-4V5a2 2 0 012-2h4a2 2 0 012 2v12a4 4 0 01-4 4zm0 0h12a2 2 0 002-2v-4a2 2 0 00-2-2h-2.343M11 7.343l1.657-1.657a2 2 0 012.828 0l2.829 2.829a2 2 0 010 2.828l-8.486 8.485M7 17h.01"></path>
                            </svg>
                        </button>
                        <span class="text-[10px] font-medium text-gray-600 dark:text-gray-400">Walk</span>
                    </div>

                    {{-- Walkable Area Erase --}}
                    <div class="flex flex-col items-center gap-1">
                        <button @click="toggleWalkableEraseMode()"
                                :title="walkablePaintMode === 'erase' ? '歩行領域消去中' : '歩行領域を消去'"
                                :class="walkablePaintMode === 'erase' ? 'bg-orange-600 hover:bg-orange-700 ring-2 ring-orange-300' : 'bg-orange-500 hover:bg-orange-600'"
                                class="p-2 text-white rounded-md shadow-sm transition-colors relative">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                        </button>
                        <span class="text-[10px] font-medium text-gray-600 dark:text-gray-400">Erase</span>
                    </div>

                    {{-- Picking Start Point --}}
                    <div class="flex flex-col items-center gap-1">
                        <button @click="togglePickingPoint('start')"
                                :title="hasPickingStartPoint() ? 'ピッキング開始地点を削除' : 'ピッキング開始地点を追加'"
                                :class="hasPickingStartPoint() ? 'bg-green-600 hover:bg-green-700' : 'bg-green-500 hover:bg-green-600'"
                                class="p-2 text-white rounded-md shadow-sm transition-colors relative">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                            </svg>
                            <span x-show="!hasPickingStartPoint()" class="absolute -top-1 -right-1 w-3 h-3 bg-white rounded-full border-2 border-green-500 flex items-center justify-center text-green-600 text-xs font-bold">+</span>
                            <span x-show="hasPickingStartPoint()" class="absolute -top-1 -right-1 w-3 h-3 bg-white rounded-full border-2 border-green-600 flex items-center justify-center text-red-600 text-xs font-bold">×</span>
                        </button>
                        <span class="text-[10px] font-medium text-gray-600 dark:text-gray-400">Start</span>
                    </div>

                    {{-- Picking End Point --}}
                    <div class="flex flex-col items-center gap-1">
                        <button @click="togglePickingPoint('end')"
                                :title="hasPickingEndPoint() ? 'ピッキング終了地点を削除' : 'ピッキング終了地点を追加'"
                                :class="hasPickingEndPoint() ? 'bg-red-600 hover:bg-red-700' : 'bg-red-500 hover:bg-red-600'"
                                class="p-2 text-white rounded-md shadow-sm transition-colors relative">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                            </svg>
                            <span x-show="!hasPickingEndPoint()" class="absolute -top-1 -right-1 w-3 h-3 bg-white rounded-full border-2 border-red-500 flex items-center justify-center text-red-600 text-xs font-bold">+</span>
                            <span x-show="hasPickingEndPoint()" class="absolute -top-1 -right-1 w-3 h-3 bg-white rounded-full border-2 border-red-600 flex items-center justify-center text-red-600 text-xs font-bold">×</span>
                        </button>
                        <span class="text-[10px] font-medium text-gray-600 dark:text-gray-400">End</span>
                    </div>

                    {{-- Picking Area --}}
                    <div class="flex flex-col items-center gap-1">
                        <button @click="togglePickingAreaMode()"
                                :title="pickingAreaMode === 'draw' ? 'ピッキングエリア描画中 (ダブルクリックで完了)' : 'ピッキングエリアを追加'"
                                :class="pickingAreaMode === 'draw' ? 'bg-violet-600 hover:bg-violet-700 ring-2 ring-violet-300' : 'bg-violet-500 hover:bg-violet-600'"
                                class="p-2 text-white rounded-md shadow-sm transition-colors relative">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                            </svg>
                        </button>
                        <span class="text-[10px] font-medium text-gray-600 dark:text-gray-400">Area</span>
                    </div>

                    {{-- Export JSON --}}
                    <div class="flex flex-col items-center gap-1">
                        <button wire:click="exportLayout" title="レイアウト出力(JSON)"
                            class="p-2 bg-blue-500 hover:bg-blue-600 text-white rounded-md shadow-sm transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"></path>
                            </svg>
                        </button>
                        <span class="text-[10px] font-medium text-gray-600 dark:text-gray-400">Exp</span>
                    </div>

                    {{-- Import JSON --}}
                    <div class="flex flex-col items-center gap-1">
                        <button @click="$refs.importFile.click()" title="レイアウト取込(JSON)"
                            class="p-2 bg-indigo-500 hover:bg-indigo-600 text-white rounded-md shadow-sm transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L9 8m4-4v12"></path>
                            </svg>
                        </button>
                        <span class="text-[10px] font-medium text-gray-600 dark:text-gray-400">Imp</span>
                    </div>

                    {{-- Export CSV --}}
                    <div class="flex flex-col items-center gap-1">
                        <button @click="exportCSV()" title="CSV出力"
                            class="p-2 bg-emerald-500 hover:bg-emerald-600 text-white rounded-md shadow-sm transition-colors">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                            </svg>
                        </button>
                        <span class="text-[10px] font-medium text-gray-600 dark:text-gray-400">CSV</span>
                    </div>
                </div>
                <input type="file" x-ref="importFile" accept=".json" @change="handleImport($event)" class="hidden">

                <div class="border-t border-gray-300 dark:border-gray-600 my-1"></div>

                {{-- Walkable Paint Controls --}}
                <div x-show="walkablePaintMode" class="bg-green-50 dark:bg-green-900/20 rounded-lg p-3 space-y-2">
                    <div class="text-xs font-semibold text-green-700 dark:text-green-300">
                        <span x-show="walkablePaintMode === 'paint'">🖌️ ペイントモード</span>
                        <span x-show="walkablePaintMode === 'erase'">🧹 消しゴムモード</span>
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1">セルサイズ</label>
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-medium">10px (固定)</span>
                        </div>
                        <div class="text-xs text-gray-500">システム全体で10pxに固定</div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1">ブラシサイズ</label>
                        <input type="range" x-model.number="walkableBrushSize" min="1" max="10" step="1"
                            class="w-full h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer">
                        <div class="text-xs text-center mt-1" x-text="walkableBrushSize + ' cells'"></div>
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1">エロージョン距離 (カート幅)</label>
                        <div class="flex items-center gap-2">
                            <input type="number" x-model.number="walkableErosionDistance" min="0" max="50" step="5"
                                class="w-20 px-2 py-1 text-xs border rounded">
                            <span class="text-xs text-gray-600">px</span>
                        </div>
                        <div class="text-xs text-gray-500 mt-1">0=エロージョンなし、20=標準カート幅</div>
                    </div>
                    <div class="flex gap-2">
                        <button @click="resetWalkableAreas()"
                            class="flex-1 px-3 py-1 bg-red-500 hover:bg-red-600 text-white rounded-md text-xs">
                            リセット
                        </button>
                        <button @click="walkablePaintMode = null"
                            class="flex-1 px-3 py-1 bg-gray-500 hover:bg-gray-600 text-white rounded-md text-xs">
                            終了
                        </button>
                    </div>
                </div>

                {{-- Picking Area Controls --}}
                <div x-show="pickingAreaMode === 'draw'" class="bg-violet-50 dark:bg-violet-900/20 rounded-lg p-3 space-y-2">
                    <div class="text-xs font-semibold text-violet-700 dark:text-violet-300">
                        🏗️ ピッキングエリア作成
                    </div>
                    
                    <div>
                        <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">エリア名</label>
                        <input type="text" x-model="newPickingAreaName"
                            class="w-full rounded-md border border-gray-300 dark:border-gray-600 text-sm px-3 py-1.5"
                            placeholder="エリア名を入力">
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">色</label>
                        <div class="flex flex-wrap gap-2">
                            <template x-for="color in ['#8B5CF6', '#EF4444', '#F59E0B', '#10B981', '#3B82F6', '#6366F1', '#EC4899', '#6B7280']">
                                <button @click="newPickingAreaColor = color"
                                        class="w-6 h-6 rounded-full border-2 transition-transform hover:scale-110"
                                        :class="newPickingAreaColor === color ? 'border-gray-900 dark:border-white scale-110' : 'border-transparent'"
                                        :style="{ backgroundColor: color }">
                                </button>
                            </template>
                        </div>
                    </div>
                    
                    <div class="text-xs text-gray-500 dark:text-gray-400">
                        <p>クリックして点を追加</p>
                        <p>Ctrl+Z で直前の点を削除</p>
                        <p>最低3点必要です。</p>
                    </div>

                    <div class="flex gap-2">
                         <button @click="savePickingArea()" 
                            class="flex-1 px-3 py-1 bg-violet-600 hover:bg-violet-700 text-white rounded-md text-xs">
                            保存
                        </button>
                        <button @click="resetPickingAreaDrawing()" 
                            class="flex-1 px-3 py-1 bg-red-500 hover:bg-red-600 text-white rounded-md text-xs">
                            リセット
                        </button>
                        <button @click="togglePickingAreaMode()" 
                            class="flex-1 px-3 py-1 bg-gray-500 hover:bg-gray-600 text-white rounded-md text-xs">
                            終了
                        </button>
                    </div>

                    {{-- Saved Areas List --}}
                    <div class="mt-4 border-t border-gray-200 dark:border-gray-700 pt-3">
                        <h4 class="text-xs font-semibold mb-2 text-gray-700 dark:text-gray-300">保存済みエリア</h4>
                        <div class="space-y-2 max-h-40 overflow-y-auto">
                            <template x-for="area in pickingAreas" :key="area.id">
                                <div class="flex items-center justify-between bg-gray-50 dark:bg-gray-700 p-2 rounded text-sm">
                                    <div class="flex items-center gap-2 overflow-hidden">
                                        <input type="checkbox" 
                                               :checked="!hiddenPickingAreaIds.includes(area.id)"
                                               @change="toggleAreaVisibility(area.id)"
                                               class="rounded border-gray-300 text-blue-600 focus:ring-blue-500 w-4 h-4">
                                        <div class="w-3 h-3 rounded-full flex-shrink-0" :style="{ backgroundColor: area.color || '#8B5CF6' }"></div>
                                        <span class="truncate" x-text="area.name"></span>
                                    </div>
                                    <button @click="deletePickingArea(area.id)" class="text-red-500 hover:text-red-700 ml-2">
                                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                                        </svg>
                                    </button>
                                </div>
                            </template>
                            <template x-if="pickingAreas.length === 0">
                                <div class="text-xs text-gray-400 text-center py-2">エリアはありません</div>
                            </template>
                        </div>
                    </div>
                </div>

                <div class="border-t border-gray-300 dark:border-gray-600 my-1"></div>

                {{-- Grid Controls (Single Line) --}}
                <div class="flex items-center gap-2 text-sm">
                    <label class="flex items-center gap-1.5">
                        <input type="checkbox" x-model="gridEnabled" @change="updateGrid()"
                            class="rounded border-gray-300">
                        <span>GRID</span>
                    </label>
                    <label class="flex items-center gap-1">
                        <span>Size: 10px (固定)</span>
                    </label>
                    <label class="flex items-center gap-1">
                        <span>閾値:</span>
                        <input type="number" x-model="gridThreshold" min="0"
                            class="w-12 rounded-md border border-gray-300 dark:border-gray-600 text-sm text-right px-1 py-0.5">
                    </label>
                </div>

                <div class="border-t border-gray-300 dark:border-gray-600 my-1"></div>

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

                <span x-show="selectedZones && selectedZones.length > 0" x-cloak class="text-gray-600 dark:text-gray-400 text-sm mt-2">
                    選択: <span x-text="selectedZones ? selectedZones.length : 0"></span>個
                </span>
            </div>
        </div>

        {{-- Detail Modal --}}
        <div x-show="showEditModal" x-cloak
             class="fixed inset-0 flex items-center justify-center"
             style="z-index: 10000;"
             @click.self="showEditModal = false">
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl p-6 w-full max-w-5xl max-h-[90vh] overflow-y-auto text-xs" @click.stop>
                <h3 class="text-2xl font-bold mb-4">区画詳細</h3>

                {{-- Basic Info --}}
                <div class="grid grid-cols-3 gap-4 mb-6">
                    <div>
                        <label class="block text-sm font-medium mb-1">名称</label>
                        <input type="text" x-model="editingZone.name"
                            class="w-full rounded-md border border-gray-300 dark:border-gray-600 px-3 py-2 focus:border-blue-500 focus:ring-1 focus:ring-blue-500"/>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">通路 (code1)</label>
                        <input type="text" x-model="editingZone.code1" maxlength="10"
                            class="w-full rounded-md border border-gray-300 dark:border-gray-600 px-3 py-2 focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                    </div>

                    <div>
                        <label class="block text-sm font-medium mb-1">棚番号 (code2)</label>
                        <input type="text" x-model="editingZone.code2" maxlength="10"
                            class="w-full rounded-md border border-gray-300 dark:border-gray-600 px-3 py-2 focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
                    </div>




                    <div class="col-span-3 w-full flex gap-4 mb-6">
    <div class="flex-1">
        <label class="block text-sm font-medium mb-1">引当可能単位</label>
        <select x-model.number="editingZone.available_quantity_flags"
                class="w-full rounded-md border border-gray-300 dark:border-gray-600 px-3 py-2 focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
            <option value="1">ケース</option>
            <option value="2">バラ</option>
            <option value="3">ケース+バラ</option>
            <option value="4">ボール</option>
        </select>
    </div>
    <div class="flex-1 w-full">
        <label class="block text-sm font-medium mb-1">温度帯</label>
        <select x-model="editingZone.temperature_type"
                class="w-full rounded-md border border-gray-300 dark:border-gray-600 px-3 py-2 focus:border-blue-500 focus:ring-1 focus:ring-blue-500">
            <option value="NORMAL">常温</option>
            <option value="CHILLED">冷蔵</option>
            <option value="FROZEN">冷凍</option>
        </select>
    </div>
    <div class="flex items-center gap-2 h-10 flex-1">
        <input type="checkbox"
               x-model="editingZone.is_restricted_area"
               class="rounded border border-gray-300 dark:border-gray-600 w-5 h-5" />
        <span class="text-sm text-gray-600">制限エリアとして設定</span>
    </div>
</div>
                </div>

                {{-- Stock Information --}}
                <h3 class="text-xl font-semibold mt-4 mb-2">在庫情報</h3>
                <div style="max-height:300px; overflow-y:auto;">
                    <table class="w-full table-auto border divide-y divide-gray-200">
                        <thead class="bg-gray-100 dark:bg-gray-700">
                            <tr>
                                <th class="px-2 py-1">商品名</th>
                                <th class="px-2 py-1 text-center">入り数</th>
                                <th class="px-2 py-1 text-center">容量</th>
                                <th class="px-2 py-1 text-center">単位</th>
                                <th class="px-2 py-1 text-center">賞味期限</th>
                                <th class="px-2 py-1 text-center">総バラ数</th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="item in levelStocks[1]?.items" :key="item.item_id">
                                <tr class="odd:bg-gray-100 dark:odd:bg-gray-800">
                                    <td class="border px-2 py-1" x-text="item.item_name"></td>
                                    <td class="border px-2 py-1 text-center" x-text="item.capacity_case"></td>
                                    <td class="border px-2 py-1 text-center" x-text="item.volume"></td>
                                    <td class="border px-2 py-1 text-center" x-text="item.volume_unit_name || item.volume_unit"></td>
                                    <td class="border px-2 py-1 text-center" x-text="item.expiration_date || '―'"></td>
                                    <td class="border px-2 py-1 text-center" x-text="item.total_qty"></td>
                                </tr>
                            </template>
                            <tr x-show="!levelStocks[1]?.items?.length">
                                <td colspan="6" class="text-center py-2 text-gray-500">在庫なし</td>
                            </tr>
                        </tbody>
                    </table>
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
                gridSize: 10, // Fixed grid size for entire system
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
                pickingPointMode: null, // 'start' or 'end' when setting picking points
                walkablePaintMode: null, // 'paint' or 'erase' when painting walkable areas
                walkableBitmap: null, // 2D array for walkable area bitmap
                walkableCellSize: 10, // Cell size in pixels for bitmap
                walkableBrushSize: 3, // Brush size in cells
                walkableErosionDistance: 20, // Erosion distance in pixels (cart width)
                isWalkablePainting: false, // Track if currently painting
                walkablePaintStartPoint: null, // Starting point for line drawing [x, y]
                walkableTempBitmap: null, // Temporary bitmap for preview while drawing line
                walkableUndoStack: [], // Undo history (array of bitmaps)
                walkableRedoStack: [], // Redo history (array of bitmaps)
                maxUndoSteps: 20, // Maximum undo steps to keep in memory
                pickingAreas: @entangle('pickingAreas'),
                pickingAreaMode: null, // 'draw'
                currentPolygonPoints: [],
                snapPoint: null, // For snapping to start point
                hiddenPickingAreaIds: [], // IDs of hidden areas
                showPickingAreaNameModal: false,
                newPickingAreaName: '',
                newPickingAreaColor: '#8B5CF6', // Default color for new picking areas
                canvasWidth: {{ $canvasWidth }},
                canvasHeight: {{ $canvasHeight }},

                init() {
                    // Request initial data from Livewire
                    this.$nextTick(() => {
                        this.$wire.loadInitialData();
                        this.initWalkableCanvas();
                    });

                    // Add keyboard event listener for undo/redo
                    document.addEventListener('keydown', (e) => {
                        // Only handle undo/redo when in paint mode
                        if (this.walkablePaintMode && e.ctrlKey && e.key === 'z') {
                            e.preventDefault();
                            if (e.shiftKey) {
                                // CTRL+SHIFT+Z for redo
                                this.redoWalkable();
                            } else {
                                // CTRL+Z for undo
                                this.undoWalkable();
                            }
                        }
                        // Also support CTRL+Y for redo
                        if (this.walkablePaintMode && e.ctrlKey && e.key === 'y') {
                            e.preventDefault();
                            this.redoWalkable();
                        }
                    });
                },

                initWalkableCanvas(walkableAreas = null, navmeta = null) {
                    const canvas = this.$refs.walkableCanvas;
                    if (!canvas) return;

                    const ctx = canvas.getContext('2d');

                    // Load existing walkable areas from parameter or Livewire
                    const areas = walkableAreas || @js($walkableAreas);
                    const meta = navmeta || @js($navmeta);

                    // Cell size is now fixed at 10px system-wide (not loaded from meta)
                    // Load erosion distance if available
                    if (meta && meta.erosion_distance !== undefined) {
                        this.walkableErosionDistance = meta.erosion_distance;
                    }
                    // Grid size is now fixed at 10 system-wide (not loaded from meta)
                    // Load threshold for A* pathfinding
                    if (meta && meta.grid_threshold !== undefined) {
                        this.gridThreshold = meta.grid_threshold;
                    }

                    // Initialize bitmap based on canvas size and cell size
                    const bitmapWidth = Math.ceil(canvas.width / this.walkableCellSize);
                    const bitmapHeight = Math.ceil(canvas.height / this.walkableCellSize);

                    this.walkableBitmap = Array(bitmapHeight).fill(null).map(() =>
                        Array(bitmapWidth).fill(false)
                    );

                    // Load from original_rectangles if available (compact and fast)
                    // Always use fixed cell size of 10px
                    if (meta && meta.original_rectangles && Array.isArray(meta.original_rectangles)) {
                        console.log('Loading from rectangles (fast method) with cell size 10px');
                        this.loadWalkableAreasFromRectangles(meta.original_rectangles, 10);
                    }
                    // Fall back to original_bitmap if available (legacy support)
                    else if (meta && meta.original_bitmap && Array.isArray(meta.original_bitmap)) {
                        console.log('Loading from original bitmap (legacy)');
                        this.loadWalkableAreasFromBitmap(meta.original_bitmap);
                    }
                    // Fall back to original_polygons if available
                    else if (meta && meta.original_polygons && meta.original_polygons.length > 0) {
                        console.log('Loading from original polygons (before erosion) with cell size 10px');
                        this.loadWalkableAreasFromPolygons(meta.original_polygons, 10);
                    }
                    // Fall back to eroded walkableAreas (least accurate)
                    else if (areas && areas.length > 0) {
                        console.log('Loading from eroded walkable areas with cell size 10px');
                        this.loadWalkableAreasFromPolygons(areas, 10);
                    }

                    this.renderWalkableCanvas();
                },

                loadWalkableAreasFromRectangles(rectangles, cellSize) {
                    // Convert rectangles back to bitmap (fast and accurate)
                    for (const rect of rectangles) {
                        // Convert pixel coordinates to cell coordinates
                        const x1 = Math.floor(rect.x1 / cellSize);
                        const y1 = Math.floor(rect.y1 / cellSize);
                        const x2 = Math.floor(rect.x2 / cellSize);
                        const y2 = Math.floor(rect.y2 / cellSize);

                        // Fill cells
                        for (let y = y1; y < y2 && y < this.walkableBitmap.length; y++) {
                            for (let x = x1; x < x2 && x < this.walkableBitmap[0].length; x++) {
                                this.walkableBitmap[y][x] = true;
                            }
                        }
                    }
                },

                loadWalkableAreasFromBitmap(savedBitmap) {
                    // Direct bitmap restoration - for backward compatibility
                    const savedHeight = savedBitmap.length;
                    const savedWidth = savedBitmap[0] ? savedBitmap[0].length : 0;

                    const currentHeight = this.walkableBitmap.length;
                    const currentWidth = this.walkableBitmap[0].length;

                    // Copy the saved bitmap, handling size differences
                    for (let y = 0; y < Math.min(savedHeight, currentHeight); y++) {
                        for (let x = 0; x < Math.min(savedWidth, currentWidth); x++) {
                            this.walkableBitmap[y][x] = savedBitmap[y][x];
                        }
                    }
                },

                loadWalkableAreasFromPolygons(polygons, cellSize) {
                    // Convert polygons back to bitmap
                    // This is a simplified rasterization - we check if cell centers are inside polygons
                    const bitmapWidth = this.walkableBitmap[0].length;
                    const bitmapHeight = this.walkableBitmap.length;

                    for (let y = 0; y < bitmapHeight; y++) {
                        for (let x = 0; x < bitmapWidth; x++) {
                            const px = x * this.walkableCellSize + this.walkableCellSize / 2;
                            const py = y * this.walkableCellSize + this.walkableCellSize / 2;

                            // Check if point is in any polygon
                            let isInside = false;
                            for (const polygon of polygons) {
                                if (this.pointInPolygon([px, py], polygon.outer)) {
                                    // Check if in any hole
                                    let inHole = false;
                                    if (polygon.holes && polygon.holes.length > 0) {
                                        for (const hole of polygon.holes) {
                                            if (this.pointInPolygon([px, py], hole)) {
                                                inHole = true;
                                                break;
                                            }
                                        }
                                    }
                                    if (!inHole) {
                                        isInside = true;
                                        break;
                                    }
                                }
                            }
                            this.walkableBitmap[y][x] = isInside;
                        }
                    }
                },

                pointInPolygon(point, polygon) {
                    let inside = false;
                    const x = point[0];
                    const y = point[1];

                    for (let i = 0, j = polygon.length - 1; i < polygon.length; j = i++) {
                        const xi = polygon[i][0], yi = polygon[i][1];
                        const xj = polygon[j][0], yj = polygon[j][1];

                        const intersect = ((yi > y) !== (yj > y))
                            && (x < (xj - xi) * (y - yi) / (yj - yi) + xi);
                        if (intersect) inside = !inside;
                    }

                    return inside;
                },

                renderWalkableCanvas() {
                    const canvas = this.$refs.walkableCanvas;
                    if (!canvas || !this.walkableBitmap) return;

                    const ctx = canvas.getContext('2d');
                    ctx.clearRect(0, 0, canvas.width, canvas.height);

                    // Draw walkable cells as semi-transparent green
                    ctx.fillStyle = 'rgba(34, 197, 94, 0.3)'; // green-500 with transparency

                    for (let y = 0; y < this.walkableBitmap.length; y++) {
                        for (let x = 0; x < this.walkableBitmap[y].length; x++) {
                            if (this.walkableBitmap[y][x]) {
                                ctx.fillRect(
                                    x * this.walkableCellSize,
                                    y * this.walkableCellSize,
                                    this.walkableCellSize,
                                    this.walkableCellSize
                                );
                            }
                        }
                    }
                },

                toggleWalkablePaintMode() {
                    if (this.walkablePaintMode === 'paint') {
                        this.walkablePaintMode = null;
                    } else {
                        this.walkablePaintMode = 'paint';
                    }
                },

                toggleWalkableEraseMode() {
                    if (this.walkablePaintMode === 'erase') {
                        this.walkablePaintMode = null;
                    } else {
                        this.walkablePaintMode = 'erase';
                    }
                },

                handleWalkableMouseDown(event) {
                    if (!this.walkablePaintMode) return;

                    event.preventDefault();
                    event.stopPropagation();

                    // Save state to undo stack before starting new operation
                    this.saveWalkableState();

                    this.isWalkablePainting = true;

                    const canvas = this.$refs.walkableCanvas;
                    if (!canvas) return;

                    const rect = canvas.getBoundingClientRect();
                    const x = Math.floor((event.clientX - rect.left) / this.walkableCellSize);
                    const y = Math.floor((event.clientY - rect.top) / this.walkableCellSize);

                    // Store starting point
                    this.walkablePaintStartPoint = [x, y];

                    // Create a copy of the current bitmap for temporary drawing
                    this.walkableTempBitmap = this.walkableBitmap.map(row => [...row]);

                    // Paint the starting point
                    this.paintWalkableBrush(x, y, this.walkablePaintMode === 'paint');
                    this.renderWalkableCanvas();
                },

                handleWalkableMouseMove(event) {
                    if (!this.walkablePaintMode || !this.isWalkablePainting || !this.walkablePaintStartPoint) return;

                    const canvas = this.$refs.walkableCanvas;
                    if (!canvas) return;

                    const rect = canvas.getBoundingClientRect();
                    const x = Math.floor((event.clientX - rect.left) / this.walkableCellSize);
                    const y = Math.floor((event.clientY - rect.top) / this.walkableCellSize);

                    // Restore from temp bitmap
                    this.walkableBitmap = this.walkableTempBitmap.map(row => [...row]);

                    // Draw line from start point to current point
                    this.drawWalkableLine(
                        this.walkablePaintStartPoint[0],
                        this.walkablePaintStartPoint[1],
                        x,
                        y,
                        this.walkablePaintMode === 'paint'
                    );

                    this.renderWalkableCanvas();
                },

                handleWalkableMouseUp(event) {
                    if (!this.walkablePaintMode || !this.walkablePaintStartPoint) return;

                    const canvas = this.$refs.walkableCanvas;
                    if (canvas) {
                        const rect = canvas.getBoundingClientRect();
                        const x = Math.floor((event.clientX - rect.left) / this.walkableCellSize);
                        const y = Math.floor((event.clientY - rect.top) / this.walkableCellSize);

                        // Restore from temp bitmap
                        this.walkableBitmap = this.walkableTempBitmap.map(row => [...row]);

                        // Draw final line
                        this.drawWalkableLine(
                            this.walkablePaintStartPoint[0],
                            this.walkablePaintStartPoint[1],
                            x,
                            y,
                            this.walkablePaintMode === 'paint'
                        );

                        this.renderWalkableCanvas();
                    }

                    this.isWalkablePainting = false;
                    this.walkablePaintStartPoint = null;
                    this.walkableTempBitmap = null;
                },

                paintWalkableBrush(x, y, isPaint) {
                    const brushRadius = Math.floor(this.walkableBrushSize / 2);

                    // Paint with brush
                    for (let dy = -brushRadius; dy <= brushRadius; dy++) {
                        for (let dx = -brushRadius; dx <= brushRadius; dx++) {
                            const px = x + dx;
                            const py = y + dy;

                            // Check bounds
                            if (py >= 0 && py < this.walkableBitmap.length &&
                                px >= 0 && px < this.walkableBitmap[0].length) {

                                // Circular brush
                                if (dx * dx + dy * dy <= brushRadius * brushRadius) {
                                    // Convert bitmap coordinates to pixel coordinates
                                    const pixelX = px * this.walkableCellSize + this.walkableCellSize / 2;
                                    const pixelY = py * this.walkableCellSize + this.walkableCellSize / 2;

                                    // Check if pixel is inside any location
                                    if (!this.isPointInsideLocation(pixelX, pixelY)) {
                                        this.walkableBitmap[py][px] = isPaint;
                                    }
                                }
                            }
                        }
                    }
                },

                isPointInsideLocation(x, y) {
                    // Check if point (x, y) is inside any location zone
                    for (const zone of this.zones) {
                        if (x >= zone.x1_pos && x <= zone.x2_pos &&
                            y >= zone.y1_pos && y <= zone.y2_pos) {
                            return true;
                        }
                    }

                    // Check if point is inside any wall
                    for (const wall of this.walls) {
                        if (x >= wall.x1 && x <= wall.x2 &&
                            y >= wall.y1 && y <= wall.y2) {
                            return true;
                        }
                    }

                    // Check if point is inside any fixed area
                    for (const fixedArea of this.fixedAreas) {
                        if (x >= fixedArea.x1 && x <= fixedArea.x2 &&
                            y >= fixedArea.y1 && y <= fixedArea.y2) {
                            return true;
                        }
                    }

                    return false;
                },

                drawWalkableLine(x0, y0, x1, y1, isPaint) {
                    // Bresenham's line algorithm
                    const dx = Math.abs(x1 - x0);
                    const dy = Math.abs(y1 - y0);
                    const sx = x0 < x1 ? 1 : -1;
                    const sy = y0 < y1 ? 1 : -1;
                    let err = dx - dy;

                    let x = x0;
                    let y = y0;

                    while (true) {
                        // Paint brush at current position
                        this.paintWalkableBrush(x, y, isPaint);

                        if (x === x1 && y === y1) break;

                        const e2 = 2 * err;
                        if (e2 > -dy) {
                            err -= dy;
                            x += sx;
                        }
                        if (e2 < dx) {
                            err += dx;
                            y += sy;
                        }
                    }
                },

                resetWalkableAreas() {
                    if (!confirm('歩行領域をすべてクリアしますか？')) {
                        return;
                    }

                    // Save current state to undo stack before clearing
                    this.saveWalkableState();

                    const canvas = this.$refs.walkableCanvas;
                    if (!canvas) return;

                    // Clear all bitmap
                    const bitmapWidth = Math.ceil(canvas.width / this.walkableCellSize);
                    const bitmapHeight = Math.ceil(canvas.height / this.walkableCellSize);

                    this.walkableBitmap = Array(bitmapHeight).fill(null).map(() =>
                        Array(bitmapWidth).fill(false)
                    );

                    this.renderWalkableCanvas();

                    // Show notification
                    alert('歩行領域をリセットしました。保存ボタンをクリックして変更を保存してください。');
                },

                saveWalkableState() {
                    if (!this.walkableBitmap) return;

                    // Deep copy current bitmap
                    const state = this.walkableBitmap.map(row => [...row]);

                    // Add to undo stack
                    this.walkableUndoStack.push(state);

                    // Limit stack size
                    if (this.walkableUndoStack.length > this.maxUndoSteps) {
                        this.walkableUndoStack.shift(); // Remove oldest
                    }

                    // Clear redo stack on new operation
                    this.walkableRedoStack = [];
                },

                undoWalkable() {
                    if (this.walkableUndoStack.length === 0) {
                        console.log('Undo stack is empty');
                        return;
                    }

                    // Save current state to redo stack
                    const currentState = this.walkableBitmap.map(row => [...row]);
                    this.walkableRedoStack.push(currentState);

                    // Restore previous state
                    this.walkableBitmap = this.walkableUndoStack.pop();
                    this.renderWalkableCanvas();

                    console.log('Undo performed. Undo stack size:', this.walkableUndoStack.length);
                },

                redoWalkable() {
                    if (this.walkableRedoStack.length === 0) {
                        console.log('Redo stack is empty');
                        return;
                    }

                    // Save current state to undo stack
                    const currentState = this.walkableBitmap.map(row => [...row]);
                    this.walkableUndoStack.push(currentState);

                    // Limit undo stack size
                    if (this.walkableUndoStack.length > this.maxUndoSteps) {
                        this.walkableUndoStack.shift();
                    }

                    // Restore redo state
                    this.walkableBitmap = this.walkableRedoStack.pop();
                    this.renderWalkableCanvas();

                    console.log('Redo performed. Redo stack size:', this.walkableRedoStack.length);
                },

                // This init function is duplicated, keeping the one from the original code.
                // The instruction's init() block seems to be a partial snippet.
                // The original init() already has the keydown listener.
                // The instruction's init() block for pickingAreaMode undo is already handled by the existing keydown listener.

                async saveWalkableAreas() {
                    if (!this.walkableBitmap) {
                        return;
                    }

                    try {
                        // Call Livewire to save walkable bitmap with all parameters
                        await this.$wire.saveWalkableBitmap(
                            this.walkableBitmap,
                            this.walkableCellSize,
                            this.walkableErosionDistance,
                            this.gridSize,
                            this.gridThreshold
                        );
                    } catch (error) {
                        console.error('Failed to save walkable areas:', error);
                        alert('歩行領域の保存に失敗しました: ' + error.message);
                    }
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

                async saveEditedZone() {
                    const idx = this.zones.findIndex(z => z.id === this.editingZone.id);
                    if (idx >= 0) {
                        // Update local zones array
                        this.zones[idx] = { ...this.zones[idx], ...this.editingZone };

                        // Update database via Livewire
                        await this.$wire.updateLocation({
                            id: this.editingZone.id,
                            code1: this.editingZone.code1,
                            code2: this.editingZone.code2,
                            name: this.editingZone.name,
                            available_quantity_flags: this.editingZone.available_quantity_flags,
                            temperature_type: this.editingZone.temperature_type,
                            is_restricted_area: this.editingZone.is_restricted_area
                        });
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
                    if (this.pickingAreaMode === 'draw') return;

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
                    if (this.pickingAreaMode === 'draw') return;
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
                    if (this.pickingAreaMode === 'draw') return;

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
                    if (this.pickingAreaMode === 'draw') return;

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
                    if (this.pickingAreaMode === 'draw') return;

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
                    if (this.pickingAreaMode === 'draw') return;

                    this.resizeState = {
                        fixedArea: area,
                        startX: event.clientX,
                        startY: event.clientY,
                        originalX2: area.x2,
                        originalY2: area.y2
                    };
                },

                handleCanvasMouseMove(event) {
                    // Handle picking point dragging
                    if (this.dragState && this.dragState.type === 'picking-point') {
                        this.handlePickingPointMouseMove(event);
                        return;
                    }

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
                    } else if (this.pickingAreaMode === 'draw') {
                        // Handle snapping to start point
                        const rect = document.getElementById('floor-plan-canvas').getBoundingClientRect();
                        const x = Math.round(event.clientX - rect.left + document.getElementById('floor-plan-canvas').scrollLeft);
                        const y = Math.round(event.clientY - rect.top + document.getElementById('floor-plan-canvas').scrollTop);

                        this.snapPoint = null;
                        if (this.currentPolygonPoints.length > 2) {
                            const startPoint = this.currentPolygonPoints[0];
                            const dist = Math.sqrt(Math.pow(x - startPoint.x, 2) + Math.pow(y - startPoint.y, 2));
                            if (dist < 20) {
                                this.snapPoint = { x: startPoint.x, y: startPoint.y };
                            }
                        }
                    }
                },

                handleCanvasMouseUp(event) {
                    // Handle picking point mouse up
                    if (this.dragState && this.dragState.type === 'picking-point') {
                        this.handlePickingPointMouseUp();
                        return;
                    }

                    if (this.walkablePaintMode === 'paint' || this.walkablePaintMode === 'erase') {
                        this.handleWalkableClick(event);
                        return;
                    }

                    if (this.pickingAreaMode === 'draw') {
                        if (this.snapPoint) {
                            // Use snapped point
                            this.currentPolygonPoints.push(this.snapPoint);
                            this.snapPoint = null;
                            
                            // Check if we closed the loop (snap to start)
                            // Since we snapped, it is likely the start point if we are near it.
                            // But snapPoint logic in mouseMove ensures it IS the start point.
                            
                            // Ask to finish
                            if (confirm('区画を終了して保存しますか？')) {
                                this.savePickingArea();
                            } else {
                                // Undo the last point (the closing point) so user can continue
                                this.currentPolygonPoints.pop();
                            }
                        } else {
                            const rect = document.getElementById('floor-plan-canvas').getBoundingClientRect();
                            const x = Math.round(event.clientX - rect.left + document.getElementById('floor-plan-canvas').scrollLeft);
                            const y = Math.round(event.clientY - rect.top + document.getElementById('floor-plan-canvas').scrollTop);
                            
                            this.currentPolygonPoints.push({x, y});
                        }
                        
                        // Double click is handled separately, but we need to prevent single click logic if needed
                        return;
                    }

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

                async saveAllChangesWithWalkable() {
                    // Save normal changes first
                    this.saveAllChanges();

                    // Save walkable areas if bitmap exists
                    if (this.walkableBitmap) {
                        await this.saveWalkableAreas();
                    }
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
                },

                hasPickingStartPoint() {
                    return this.$wire.pickingStartX > 0 || this.$wire.pickingStartY > 0;
                },

                hasPickingEndPoint() {
                    return this.$wire.pickingEndX > 0 || this.$wire.pickingEndY > 0;
                },

                togglePickingPoint(type) {
                    if (type === 'start') {
                        if (this.hasPickingStartPoint()) {
                            // Delete start point
                            this.$wire.updatePickingStartPoint(0, 0);
                        } else {
                            // Add start point - enter placement mode
                            this.pickingPointMode = 'start';
                        }
                    } else if (type === 'end') {
                        if (this.hasPickingEndPoint()) {
                            // Delete end point
                            this.$wire.updatePickingEndPoint(0, 0);
                        } else {
                            // Add end point - enter placement mode
                            this.pickingPointMode = 'end';
                        }
                    }
                },

                async handleCanvasClick(event) {
                    if (!this.pickingPointMode) {
                        return;
                    }

                    const rect = event.currentTarget.getBoundingClientRect();
                    const x = Math.round(event.clientX - rect.left + event.currentTarget.scrollLeft);
                    const y = Math.round(event.clientY - rect.top + event.currentTarget.scrollTop);

                    if (this.pickingPointMode === 'start') {
                        await this.$wire.updatePickingStartPoint(x, y);
                    } else if (this.pickingPointMode === 'end') {
                        await this.$wire.updatePickingEndPoint(x, y);
                    }

                    this.pickingPointMode = null;
                },

                handlePickingPointMouseDown(event, type) {
                    event.preventDefault();

                    const canvas = document.getElementById('floor-plan-canvas');
                    const rect = canvas.getBoundingClientRect();

                    this.dragState = {
                        type: 'picking-point',
                        pointType: type,
                        startX: event.clientX,
                        startY: event.clientY,
                        initialX: type === 'start' ? this.$wire.pickingStartX : this.$wire.pickingEndX,
                        initialY: type === 'start' ? this.$wire.pickingStartY : this.$wire.pickingEndY,
                    };
                },

                handlePickingPointMouseMove(event) {
                    if (!this.dragState || this.dragState.type !== 'picking-point') {
                        return;
                    }

                    const dx = event.clientX - this.dragState.startX;
                    const dy = event.clientY - this.dragState.startY;

                    let newX = this.dragState.initialX + dx;
                    let newY = this.dragState.initialY + dy;

                    // Apply grid snapping if enabled
                    if (this.gridEnabled && this.gridSize >= 4) {
                        const threshold = this.gridThreshold || 6;
                        const modX = newX % this.gridSize;
                        const modY = newY % this.gridSize;

                        if (Math.abs(modX) < threshold || Math.abs(modX - this.gridSize) < threshold) {
                            newX = Math.round(newX / this.gridSize) * this.gridSize;
                        }
                        if (Math.abs(modY) < threshold || Math.abs(modY - this.gridSize) < threshold) {
                            newY = Math.round(newY / this.gridSize) * this.gridSize;
                        }
                    }

                    // Store the dragged position (will be saved on mouse up)
                    newX = Math.max(0, Math.round(newX));
                    newY = Math.max(0, Math.round(newY));

                    this.dragState.currentX = newX;
                    this.dragState.currentY = newY;

                    // Update position via Livewire (for live preview)
                    if (this.dragState.pointType === 'start') {
                        this.$wire.set('pickingStartX', newX, false);
                        this.$wire.set('pickingStartY', newY, false);
                    } else {
                        this.$wire.set('pickingEndX', newX, false);
                        this.$wire.set('pickingEndY', newY, false);
                    }
                },

                handlePickingPointMouseUp() {
                    if (this.dragState && this.dragState.type === 'picking-point') {
                        // Save the final position
                        const finalX = this.dragState.currentX || this.dragState.initialX;
                        const finalY = this.dragState.currentY || this.dragState.initialY;

                        if (this.dragState.pointType === 'start') {
                            this.$wire.updatePickingStartPoint(finalX, finalY);
                        } else {
                            this.$wire.updatePickingEndPoint(finalX, finalY);
                        }
                        this.dragState = null;
                    }
                },

                // --- Picking Area Functions ---

                togglePickingAreaMode() {
                    if (this.pickingAreaMode === 'draw') {
                        this.pickingAreaMode = null;
                        this.currentPolygonPoints = [];
                        this.snapPoint = null;
                        this.newPickingAreaName = '';
                        this.newPickingAreaColor = '#8B5CF6';
                    } else {
                        this.pickingAreaMode = 'draw';
                        this.currentPolygonPoints = [];
                        this.snapPoint = null;
                        this.newPickingAreaName = '';
                        this.newPickingAreaColor = '#8B5CF6';
                        this.walkablePaintMode = null; // Disable other modes
                        this.pickingPointMode = null;
                    }
                },

                resetPickingAreaDrawing() {
                    this.currentPolygonPoints = [];
                },

                savePickingArea() {
                    if (!this.newPickingAreaName) {
                        // Set default name if empty
                        this.newPickingAreaName = 'Area ' + (this.pickingAreas.length + 1);
                    }

                    if (this.currentPolygonPoints.length < 3) {
                        alert('最低3つの点が必要です');
                        return;
                    }
                    
                    this.$wire.savePickingArea(this.newPickingAreaName, this.currentPolygonPoints, this.newPickingAreaColor);
                    
                    // Reset for next area, but keep drawing mode
                    this.currentPolygonPoints = [];
                    this.newPickingAreaName = '';
                    this.newPickingAreaColor = '#8B5CF6';
                    // this.pickingAreaMode = null; // Keep mode active
                },

                toggleAreaVisibility(id) {
                    if (this.hiddenPickingAreaIds.includes(id)) {
                        this.hiddenPickingAreaIds = this.hiddenPickingAreaIds.filter(i => i !== id);
                    } else {
                        this.hiddenPickingAreaIds.push(id);
                    }
                },

                deletePickingArea(id) {
                    if (confirm('このピッキングエリアを削除しますか？')) {
                        this.$wire.deletePickingArea(id);
                    }
                },

                selectPickingArea(area) {
                    // Placeholder for selection logic if needed
                    console.log('Selected area:', area);
                },

                getPolygonPoints(polygon) {
                    if (!polygon || !Array.isArray(polygon)) return '';
                    return polygon.map(p => `${p.x},${p.y}`).join(' ');
                },

                getPreviewPoints() {
                    if (this.currentPolygonPoints.length === 0) return '';
                    return this.currentPolygonPoints.map(p => `${p.x},${p.y}`).join(' ');
                },

                getPolygonCentroid(polygon) {
                    if (!polygon || polygon.length === 0) return {x: 0, y: 0};
                    let x = 0, y = 0;
                    polygon.forEach(p => {
                        x += p.x;
                        y += p.y;
                    });
                    return {
                        x: x / polygon.length,
                        y: y / polygon.length
                    };
                },

                // Handle double click to finish drawing (optional shortcut)
                handleCanvasDoubleClick(e) {
                    if (this.pickingAreaMode === 'draw') {
                        e.preventDefault();
                        e.stopPropagation();
                        // Just stop drawing, user can click save
                    }
                },

                getDrawingPoints() {
                    return this.currentPolygonPoints.map((p, index) => {
                        let r = 5;
                        let color = '#8B5CF6'; // Purple

                        if (index === 0) {
                            r = 8;
                            color = '#10B981'; // Green
                        } else if (index === this.currentPolygonPoints.length - 1) {
                            r = 8;
                            color = '#EF4444'; // Red
                        }

                        return { x: p.x, y: p.y, r, color };
                    });
                }
            };
        }
    </script>
    @endpush

    <style>
        [x-cloak] { display: none !important; }
    </style>
</x-filament-panels::page></div>
