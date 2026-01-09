<x-filament-panels::page>
    <div x-data="pickingRouteViewer()"
         x-init="init()"
         @layout-loaded.window="
             zones = Array.isArray($event.detail.zones) ? $event.detail.zones : [];
             walls = Array.isArray($event.detail.walls) ? $event.detail.walls : [];
             fixedAreas = Array.isArray($event.detail.fixedAreas) ? $event.detail.fixedAreas : [];
             pickingAreas = Array.isArray($event.detail.pickingAreas) ? $event.detail.pickingAreas : [];
             if ($event.detail.canvasWidth && $event.detail.canvasHeight) {
                 canvasWidth = $event.detail.canvasWidth;
                 canvasHeight = $event.detail.canvasHeight;
             }
             // Reload walkable areas when layout changes
             loadWalkableAreas();
             // Recalculate route lines if picking items are already loaded
             if (pickingItems.length > 0) {
                 calculateRouteLines();
             }
         "
         @delivery-course-changed.window="loadPickingRoute($event.detail.courseId)"
         @picking-task-changed.window="loadPickingRoute()"
         @date-changed.window="loadPickingRoute()"
         @walking-order-updated.window="loadPickingRoute()"
         @reorder-failed.window="alert($event.detail.message)"
         class="h-full">

        {{-- Main Layout: Left and Right (same as floor-plan-editor) --}}
        <div class="flex" style="height: calc(100vh - 120px);">

            {{-- Left Side: Floor Plan Canvas --}}
            <div class="flex-1 bg-gray-200 dark:bg-gray-900 rounded-lg shadow relative overflow-auto"
                 id="picking-route-canvas-wrapper">

                {{-- Centering wrapper --}}
                <div class="flex justify-center" :style="{minWidth: canvasWidth + 'px', minHeight: canvasHeight + 'px'}">
                    {{-- Canvas Inner Container with grid and exact size --}}
                    <div class="relative bg-white dark:bg-gray-800 flex-shrink-0"
                         :style="{...canvasStyle, width: canvasWidth + 'px', height: canvasHeight + 'px'}"
                         id="picking-route-canvas">

                    {{-- Walkable Area Canvas Layer --}}
                    <canvas x-ref="walkableCanvas"
                            :width="canvasWidth"
                            :height="canvasHeight"
                            class="absolute inset-0 pointer-events-none z-0"
                            :style="showWalkableAreas ? 'opacity: 0.4;' : 'opacity: 0;'">
                    </canvas>

                    {{-- Zone Blocks (Locations) - Read-only, same style as floor-plan-editor --}}
                    <template x-for="zone in zones" :key="zone.id">
                        <div @dblclick="showZoneStockModal(zone)"
                             :style="`
                                 position: absolute;
                                 left: ${zone.x1}px;
                                 top: ${zone.y1}px;
                                 width: ${zone.x2 - zone.x1}px;
                                 height: ${zone.y2 - zone.y1}px;
                                 z-index: 10;
                                 background-color: ${getZoneColor(zone.id)};
                                 border-color: ${zone.is_restricted_area ? '#EF4444' : (hasPickingItems(zone.id) ? '#3B82F6' : '{{ $colors['location']['border'] ?? '#D1D5DB' }}')};
                                 border-width: ${zone.is_restricted_area ? '2px' : (hasPickingItems(zone.id) ? '2px' : '1px')};
                                 color: {{ $textStyles['location']['color'] ?? '#6B7280' }};
                                 font-size: {{ $textStyles['location']['size'] ?? 12 }}px;
                             `"
                             class="cursor-pointer flex flex-col items-center justify-center p-2 rounded shadow-sm select-none border-solid hover:shadow-md transition-shadow">

                            <div x-text="zone.code1 + zone.code2"></div>

                            {{-- Show walking order if enabled --}}
                            <template x-if="showWalkingOrder && getZoneWalkingOrders(zone.id).length > 0">
                                <div class="mt-1 flex flex-wrap gap-1 justify-center relative z-10">
                                    <template x-for="order in getZoneWalkingOrders(zone.id)" :key="order">
                                        <span class="inline-block bg-blue-500 text-white text-xs px-1.5 py-0.5 rounded shadow-md"
                                              x-text="order"></span>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </template>

                    {{-- Walls (Read-only) --}}
                    <template x-for="wall in walls" :key="wall.id">
                        <div :style="`
                                 position: absolute;
                                 left: ${wall.x1}px;
                                 top: ${wall.y1}px;
                                 width: ${wall.x2 - wall.x1}px;
                                 height: ${wall.y2 - wall.y1}px;
                                 z-index: 9;
                                 background-color: {{ $colors['wall']['rectangle'] ?? '#9CA3AF' }};
                                 border: 1px solid {{ $colors['wall']['border'] ?? '#6B7280' }};
                                 color: {{ $textStyles['wall']['color'] ?? '#FFFFFF' }};
                                 font-size: {{ $textStyles['wall']['size'] ?? 10 }}px;
                             `"
                             class="flex items-center justify-center select-none rounded cursor-default">
                            <span x-text="wall.name"></span>
                        </div>
                    </template>

                    {{-- Fixed Areas (Read-only) --}}
                    <template x-for="area in fixedAreas" :key="area.id">
                        <div :style="`
                                 position: absolute;
                                 left: ${area.x1}px;
                                 top: ${area.y1}px;
                                 width: ${area.x2 - area.x1}px;
                                 height: ${area.y2 - area.y1}px;
                                 z-index: 9;
                                 background-color: {{ $colors['fixed_area']['rectangle'] ?? '#FEF3C7' }};
                                 border: 2px solid {{ $colors['fixed_area']['border'] ?? '#F59E0B' }};
                                 color: {{ $textStyles['fixed_area']['color'] ?? '#92400E' }};
                                 font-size: {{ $textStyles['fixed_area']['size'] ?? 12 }}px;
                             `"
                             class="flex items-center justify-center select-none rounded-lg font-medium cursor-default">
                            <span x-text="area.name"></span>
                        </div>
                    </template>

                    {{-- Picking Start Point --}}
                    @if($pickingStartX > 0 || $pickingStartY > 0)
                    <div style="position: absolute;
                                left: {{ $pickingStartX }}px;
                                top: {{ $pickingStartY }}px;
                                width: 40px;
                                height: 40px;
                                transform: translate(-20px, -20px);
                                z-index: 20;"
                         class="flex items-center justify-center rounded-full bg-green-500 border-4 border-white shadow-xl select-none pointer-events-none">
                        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                        <div class="absolute -bottom-6 left-1/2 transform -translate-x-1/2 text-xs font-bold text-green-600 whitespace-nowrap bg-white px-2 py-0.5 rounded shadow-md border border-green-200">開始</div>
                    </div>
                    @endif

                    {{-- Picking End Point --}}
                    @if($pickingEndX > 0 || $pickingEndY > 0)
                    <div style="position: absolute;
                                left: {{ $pickingEndX }}px;
                                top: {{ $pickingEndY }}px;
                                width: 40px;
                                height: 40px;
                                transform: translate(-20px, -20px);
                                z-index: 20;"
                         class="flex items-center justify-center rounded-full bg-red-500 border-4 border-white shadow-xl select-none pointer-events-none">
                        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                        <div class="absolute -bottom-6 left-1/2 transform -translate-x-1/2 text-xs font-bold text-red-600 whitespace-nowrap bg-white px-2 py-0.5 rounded shadow-md border border-red-200">終了</div>
                    </div>
                    @endif

                    {{-- Picking Areas (Polygons) --}}
                    <template x-if="showPickingAreas">
                        <template x-for="area in pickingAreas" :key="area.id">
                            <svg class="absolute inset-0 pointer-events-none" :width="canvasWidth" :height="canvasHeight" style="z-index: 5;">
                                <polygon :points="getPolygonPoints(area.polygon)"
                                         :fill="area.color || '#8B5CF6'"
                                         :stroke="area.color || '#8B5CF6'"
                                         fill-opacity="0.1"
                                         stroke-width="2">
                                </polygon>
                            </svg>
                        </template>
                    </template>

                    {{-- Route Lines --}}
                    <template x-if="showRouteLines && routeLines.length > 0">
                        <div class="absolute inset-0 pointer-events-none" style="z-index: 15;">
                            <svg style="width: 100%; height: 100%;" x-html="renderRouteSvg()">
                            </svg>
                        </div>
                    </template>

                    </div>
                </div>
            </div>

            {{-- Right Side: Toolbar (same width as floor-plan-editor) --}}
            <div class="w-[220px] bg-white dark:bg-gray-800 rounded-lg shadow px-2 py-2 flex flex-col gap-2 overflow-y-auto">

                {{-- Warehouse & Floor Selection --}}
                <div class="flex flex-col gap-1">
                    <div>
                        <label class="block text-[10px] font-medium text-gray-700 dark:text-gray-300">倉庫</label>
                        <select wire:model.live="selectedWarehouseId"
                            class="w-full rounded border border-gray-300 dark:border-gray-600 text-xs px-1 py-1">
                            <option value="">選択</option>
                            @foreach($this->warehouses as $wh)
                                <option value="{{ $wh->id }}">{{ $wh->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-[10px] font-medium text-gray-700 dark:text-gray-300">フロア</label>
                        <select wire:model.live="selectedFloorId"
                            class="w-full rounded border border-gray-300 dark:border-gray-600 text-xs px-1 py-1">
                            <option value="">選択</option>
                            @foreach($this->floors as $floor)
                                <option value="{{ $floor->id }}">{{ $floor->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="border-t border-gray-200 dark:border-gray-600 my-1"></div>

                {{-- Date & Delivery Course Selection --}}
                <div class="flex flex-col gap-1">
                    <div>
                        <label class="block text-[10px] font-medium text-gray-700 dark:text-gray-300">出荷日</label>
                        <input type="date" wire:model.live="selectedDate"
                            class="w-full rounded border border-gray-300 dark:border-gray-600 text-xs px-1 py-1">
                    </div>
                    <div>
                        <label class="block text-[10px] font-medium text-gray-700 dark:text-gray-300">配送コース</label>
                        <select wire:model.live="selectedDeliveryCourseId"
                            class="w-full rounded border border-gray-300 dark:border-gray-600 text-xs px-1 py-1">
                            <option value="">選択</option>
                            @foreach($this->deliveryCourses as $course)
                                <option value="{{ $course['id'] }}">{{ $course['code'] }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-[10px] font-medium text-gray-700 dark:text-gray-300">タスク</label>
                        <select wire:model.live="selectedPickingTaskId"
                            class="w-full rounded border border-gray-300 dark:border-gray-600 text-xs px-1 py-1">
                            <option value="">選択</option>
                            @foreach($this->pickingTasks as $task)
                                <option value="{{ $task['id'] }}">
                                    #{{ $task['id'] }}
                                    @if($task['picker_name'])
                                        {{ $task['picker_name'] }}
                                    @endif
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="border-t border-gray-200 dark:border-gray-600 my-1"></div>

                {{-- Display Options --}}
                <div class="space-y-1">
                    <h4 class="text-[10px] font-semibold text-gray-700 dark:text-gray-300">表示オプション</h4>
                    <div class="space-y-1">
                        <label class="flex items-center gap-2">
                            <input type="checkbox" x-model="showRouteLines"
                                class="rounded border-gray-300 w-3.5 h-3.5">
                            <span class="text-xs">経路線</span>
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" x-model="showWalkingOrder"
                                class="rounded border-gray-300 w-3.5 h-3.5">
                            <span class="text-xs">順序番号</span>
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" x-model="showPickingAreas"
                                class="rounded border-gray-300 w-3.5 h-3.5">
                            <span class="text-xs">ピッキングエリア</span>
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="checkbox" x-model="showWalkableAreas"
                                   @change="renderWalkableAreas()"
                                class="rounded border-gray-300 w-3.5 h-3.5">
                            <span class="text-xs">歩行領域</span>
                        </label>
                    </div>
                </div>

                {{-- Route Recalculation Button --}}
                <div x-show="taskInfo?.task_id">
                    <button @click="recalculateRoute()"
                            :disabled="isRecalculating || !['PENDING', 'PICKING_READY'].includes(taskInfo?.status)"
                            class="w-full text-xs bg-green-500 hover:bg-green-600 disabled:bg-gray-400 disabled:cursor-not-allowed text-white px-2 py-1.5 rounded flex items-center justify-center gap-1 transition-colors">
                        <svg class="w-3.5 h-3.5" :class="{'animate-spin': isRecalculating}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                        </svg>
                        <span x-text="isRecalculating ? '計算中...' : '経路再計算'"></span>
                    </button>
                    <p x-show="!['PENDING', 'PICKING_READY'].includes(taskInfo?.status)" class="text-[10px] text-gray-500 mt-1 text-center">
                        ※PENDING/READYのみ
                    </p>
                </div>

                <div class="border-t border-gray-200 dark:border-gray-600 my-1"></div>

                {{-- Picking Areas List --}}
                <div x-show="pickingAreas.length > 0" class="bg-gray-50 dark:bg-gray-700/50 rounded p-1.5">
                    <h4 class="text-[10px] font-semibold mb-1 text-gray-700 dark:text-gray-300">
                        ピッキングエリア
                        <span class="text-gray-500 font-normal">(<span x-text="pickingAreas.length"></span>)</span>
                    </h4>
                    <div class="space-y-1 max-h-32 overflow-y-auto">
                        <template x-for="area in pickingAreas" :key="area.id">
                            <div @click="showAreaDetail(area)"
                                 class="flex items-center gap-1.5 p-1 rounded cursor-pointer hover:bg-gray-100 dark:hover:bg-gray-600 text-xs">
                                <div class="w-3 h-3 rounded-full flex-shrink-0"
                                     :style="{backgroundColor: area.color || '#8B5CF6'}"></div>
                                <span class="truncate" x-text="area.name"></span>
                                <span class="text-[10px] text-gray-400 ml-auto" x-text="getTemperatureLabel(area.temperature_type)"></span>
                            </div>
                        </template>
                    </div>
                </div>

                {{-- Picking Task Information --}}
                <div x-show="taskInfo" class="bg-blue-50 dark:bg-blue-900/20 rounded p-1.5">
                    <div class="flex items-center justify-between mb-1">
                        <h4 class="text-[10px] font-semibold text-gray-700 dark:text-gray-300">タスク情報</h4>
                        <span class="text-[10px] bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 px-1.5 py-0.5 rounded font-mono"
                              x-text="'#' + (taskInfo?.task_id || '-')"></span>
                    </div>

                    <div class="space-y-0.5 text-[10px]">
                        <div class="flex justify-between">
                            <span class="text-gray-600 dark:text-gray-400">状態:</span>
                            <span class="font-medium"
                                  :class="{
                                      'text-green-600': taskInfo?.status === 'COMPLETED',
                                      'text-blue-600': taskInfo?.status === 'PICKING',
                                      'text-yellow-600': taskInfo?.status === 'PENDING'
                                  }"
                                  x-text="getStatusLabel(taskInfo?.status)"></span>
                        </div>
                        <div x-show="taskInfo?.picker_name" class="flex justify-between">
                            <span class="text-gray-600 dark:text-gray-400">ピッカー:</span>
                            <span class="font-medium truncate ml-2" x-text="taskInfo?.picker_name || '-'"></span>
                        </div>
                        <div x-show="taskInfo?.started_at" class="flex justify-between">
                            <span class="text-gray-600 dark:text-gray-400">開始:</span>
                            <span class="font-medium" x-text="formatDateTime(taskInfo?.started_at)"></span>
                        </div>
                        <div x-show="taskInfo?.completed_at" class="flex justify-between">
                            <span class="text-gray-600 dark:text-gray-400">終了:</span>
                            <span class="font-medium" x-text="formatDateTime(taskInfo?.completed_at)"></span>
                        </div>
                    </div>
                </div>

                {{-- Picking Items List --}}
                <div class="flex-1 overflow-hidden flex flex-col bg-gray-50 dark:bg-gray-700/50 rounded p-1.5">
                    <h4 class="text-[10px] font-semibold mb-1 text-gray-700 dark:text-gray-300">
                        ピッキングアイテム
                        <span x-show="pickingItems.length > 0" class="text-gray-500 font-normal">
                            (<span x-text="pickingItems.length"></span>件)
                        </span>
                    </h4>

                    <div class="overflow-y-auto flex-1 max-h-64">
                        <template x-if="pickingItems.length === 0">
                            <p class="text-[10px] text-gray-500 text-center py-2">データなし</p>
                        </template>

                        <template x-if="pickingItems.length > 0">
                            <div class="space-y-1">
                                <template x-for="(item, index) in pickingItems" :key="item.id">
                                    <div :draggable="taskInfo?.status === 'PENDING'"
                                         @dragstart="handleDragStart($event, index)"
                                         @dragover.prevent="handleDragOver($event, index)"
                                         @drop="handleDrop($event, index)"
                                         @dragend="handleDragEnd($event)"
                                         :class="{
                                             'cursor-move': taskInfo?.status === 'PENDING',
                                             'cursor-not-allowed opacity-60': taskInfo?.status !== 'PENDING',
                                             'bg-blue-100 dark:bg-blue-900/30': dragOverIndex === index
                                         }"
                                         class="text-[10px] p-1 bg-white dark:bg-gray-800 rounded border border-gray-200 dark:border-gray-600 transition-colors">
                                        <div class="flex items-center gap-1 mb-0.5">
                                            <span class="font-mono font-semibold text-blue-600 bg-blue-50 dark:bg-blue-900/20 px-1 py-0.5 rounded"
                                                  x-text="item.walking_order"></span>
                                            <span class="text-gray-600 dark:text-gray-400"
                                                  x-text="item.location_display"></span>
                                            <template x-if="item.distance_from_previous">
                                                <span class="ml-auto font-mono text-green-600 dark:text-green-400 bg-green-50 dark:bg-green-900/20 px-1 py-0.5 rounded"
                                                      x-text="Math.round(item.distance_from_previous) + 'px'"></span>
                                            </template>
                                        </div>
                                        <div class="text-gray-900 dark:text-gray-100 truncate" x-text="item.item_name"></div>
                                        <div class="text-gray-600 dark:text-gray-400">
                                            <span class="font-medium" x-text="`${Math.floor(item.planned_qty)} ${getQuantityTypeLabel(item.qty_type)}`"></span>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>
                </div>

            </div>
        </div>

        {{-- Area Detail Modal --}}
        <div x-show="showAreaModal" x-cloak
             class="fixed inset-0 flex items-center justify-center z-50"
             @click.self="showAreaModal = false">
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl p-5 w-full max-w-2xl max-h-[90vh] overflow-y-auto"
                 @click.stop>
                {{-- Header --}}
                <div class="flex items-center justify-between mb-4 pb-3 border-b border-gray-200 dark:border-gray-700">
                    <h3 class="text-lg font-bold flex items-center gap-2">
                        <div class="w-5 h-5 rounded-full border-2 border-white shadow"
                             :style="{backgroundColor: selectedArea?.color || '#8B5CF6'}"></div>
                        <span x-text="selectedArea?.name"></span>
                        <span x-show="selectedArea?.is_restricted_area"
                              class="text-xs px-2 py-0.5 bg-red-100 text-red-600 rounded ml-2">制限エリア</span>
                    </h3>
                    <button @click="showAreaModal = false" class="text-gray-400 hover:text-gray-600">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>

                <div class="grid grid-cols-2 gap-6">
                    {{-- Left Column: Basic Info --}}
                    <div class="space-y-3">
                        <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300">基本情報</h4>

                        <div class="bg-gray-50 dark:bg-gray-700/50 rounded-lg p-3 space-y-2">
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-500 dark:text-gray-400">エリアID</span>
                                <span class="font-medium" x-text="selectedArea?.id"></span>
                            </div>
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-500 dark:text-gray-400">カラー</span>
                                <div class="flex items-center gap-2">
                                    <div class="w-4 h-4 rounded border"
                                         :style="{backgroundColor: selectedArea?.color || '#8B5CF6'}"></div>
                                    <span class="font-mono text-xs" x-text="selectedArea?.color || '#8B5CF6'"></span>
                                </div>
                            </div>
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-500 dark:text-gray-400">引当可能単位</span>
                                <span class="font-medium" x-text="getAvailableQuantityLabel(selectedArea?.available_quantity_flags)"></span>
                            </div>
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-500 dark:text-gray-400">温度帯</span>
                                <span class="font-medium" x-text="getTemperatureLabel(selectedArea?.temperature_type)"></span>
                            </div>
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-500 dark:text-gray-400">制限エリア</span>
                                <span class="font-medium" x-text="selectedArea?.is_restricted_area ? 'はい' : 'いいえ'"></span>
                            </div>
                            <div class="flex justify-between text-sm">
                                <span class="text-gray-500 dark:text-gray-400">ロケーション数</span>
                                <span class="font-medium"><span x-text="selectedArea?.location_count || 0"></span>件</span>
                            </div>
                        </div>
                    </div>

                    {{-- Right Column: Assigned Pickers --}}
                    <div>
                        <h4 class="text-sm font-semibold text-gray-700 dark:text-gray-300 mb-3">
                            担当ピッカー
                            <span class="font-normal text-gray-500">(<span x-text="selectedArea?.pickers?.length || 0"></span>名)</span>
                        </h4>

                        <div class="border border-gray-200 dark:border-gray-600 rounded-lg max-h-48 overflow-y-auto">
                            <template x-if="!selectedArea?.pickers || selectedArea?.pickers?.length === 0">
                                <div class="text-sm text-gray-400 text-center py-6">
                                    担当ピッカーなし
                                </div>
                            </template>
                            <template x-if="selectedArea?.pickers?.length > 0">
                                <div class="divide-y divide-gray-100 dark:divide-gray-700">
                                    <template x-for="picker in selectedArea.pickers" :key="picker.id">
                                        <div class="flex items-center gap-2 p-2.5 text-sm">
                                            <div class="w-8 h-8 rounded-full bg-gray-200 dark:bg-gray-600 flex items-center justify-center text-xs font-medium text-gray-600 dark:text-gray-300"
                                                 x-text="picker.name?.charAt(0) || 'P'"></div>
                                            <div class="flex-1 min-w-0">
                                                <div class="font-medium truncate" x-text="picker.name"></div>
                                                <div class="text-xs text-gray-500" x-text="picker.code"></div>
                                            </div>
                                            <span x-show="picker.can_access_restricted_area"
                                                  class="text-xs px-1.5 py-0.5 bg-red-100 text-red-600 rounded whitespace-nowrap">制限可</span>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>

                {{-- Footer --}}
                <div class="mt-5 pt-4 border-t border-gray-200 dark:border-gray-700 flex justify-end">
                    <button @click="showAreaModal = false"
                            class="px-4 py-2 bg-gray-500 hover:bg-gray-600 text-white rounded-md text-sm">
                        閉じる
                    </button>
                </div>
            </div>
        </div>

        {{-- Zone Stock Modal (Read-only) --}}
        <div x-show="showStockModal" x-cloak
             class="fixed inset-0 flex items-center justify-center"
             style="z-index: 10000;"
             @click.self="showStockModal = false">
            <div class="bg-white dark:bg-gray-800 rounded-lg shadow-xl w-full max-w-5xl h-[700px] flex flex-col" @click.stop>

                {{-- Modal Header --}}
                <div class="bg-[#1e3a5f] border-b border-gray-700 px-6 py-4 flex justify-between items-center rounded-t-lg flex-shrink-0">
                    <div class="flex items-center gap-6">
                        <span class="text-2xl font-bold text-white" x-text="selectedZone?.code1 + selectedZone?.code2"></span>
                        <div class="flex items-center gap-1">
                            <span class="text-xs text-gray-400">通路:</span>
                            <span class="text-sm font-medium text-white" x-text="selectedZone?.code1 || '-'"></span>
                        </div>
                        <div class="flex items-center gap-1">
                            <span class="text-xs text-gray-400">棚番号:</span>
                            <span class="text-sm font-medium text-white" x-text="selectedZone?.code2 || '-'"></span>
                        </div>
                        <div class="flex items-center gap-1">
                            <span class="text-xs text-gray-400">棚数:</span>
                            <span class="text-sm font-medium text-white" x-text="selectedZone?.shelf_count || 0"></span>
                        </div>
                    </div>
                    <button @click="showStockModal = false" class="text-gray-400 hover:text-white">
                        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>

                {{-- Stock Table Section --}}
                <div class="flex-1 overflow-hidden flex flex-col px-6 py-4">
                    <div class="flex justify-between items-center mb-3 flex-shrink-0">
                        <div class="flex items-center gap-4">
                            <h3 class="font-bold text-gray-700 dark:text-gray-300">在庫リスト</h3>
                            <span class="text-sm text-gray-500" x-text="'該当 ' + filteredStockItems.length + '件'"></span>
                        </div>
                        <div class="flex items-center gap-2">
                            <div class="relative">
                                <div class="absolute inset-y-0 left-0 flex items-center pl-3 pointer-events-none">
                                    <svg class="w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                                    </svg>
                                </div>
                                <input type="text"
                                       x-model="stockSearchQuery"
                                       x-on:input="
                                           setTimeout(() => {
                                               const q = (stockSearchQuery || '').toString().toLowerCase().trim();
                                               if (!q) {
                                                   filteredStockItems = [...stockItems];
                                               } else {
                                                   filteredStockItems = stockItems.filter(s => {
                                                       const loc = (s.location_code || '').toString().toLowerCase();
                                                       const code = (s.item_code || '').toString().toLowerCase();
                                                       const name = (s.item_name || '').toString().toLowerCase();
                                                       return loc.includes(q) || code.includes(q) || name.includes(q);
                                                   });
                                               }
                                           }, 200);
                                       "
                                       class="w-56 pl-9 pr-8 py-1.5 text-sm border border-gray-300 dark:border-gray-600 rounded bg-gray-50 dark:bg-gray-800 focus:ring-blue-500 focus:border-blue-500"
                                       placeholder="棚番 / 商品コード / 商品名">
                                <button x-show="stockSearchQuery && stockSearchQuery.length > 0"
                                        x-on:click="stockSearchQuery = ''; filteredStockItems = [...stockItems];"
                                        class="absolute inset-y-0 right-0 flex items-center pr-2 text-gray-400 hover:text-gray-600">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>

                    {{-- Stock Table --}}
                    <div class="flex-1 overflow-auto border border-gray-200 dark:border-gray-600 rounded-lg">
                        <table class="w-full text-sm">
                            <thead class="bg-gray-100 dark:bg-gray-700 sticky top-0">
                                <tr>
                                    <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300 w-32">棚番</th>
                                    <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300 w-32">商品コード</th>
                                    <th class="px-3 py-2 text-left font-medium text-gray-600 dark:text-gray-300">商品名</th>
                                    <th class="px-3 py-2 text-right font-medium text-gray-600 dark:text-gray-300 w-28">ケース</th>
                                    <th class="px-3 py-2 text-right font-medium text-gray-600 dark:text-gray-300 w-28">バラ</th>
                                    <th class="px-3 py-2 text-center font-medium text-gray-600 dark:text-gray-300 w-28">賞味期限</th>
                                    <th class="px-3 py-2 text-center font-medium text-gray-600 dark:text-gray-300 w-28">入荷日</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-600">
                                <template x-if="filteredStockItems.length === 0">
                                    <tr>
                                        <td colspan="7" class="px-3 py-8 text-center text-gray-400">
                                            在庫データがありません
                                        </td>
                                    </tr>
                                </template>
                                <template x-for="stock in filteredStockItems" :key="stock.id">
                                    <tr class="hover:bg-gray-50 dark:hover:bg-gray-700/50">
                                        <td class="px-3 py-2 font-mono text-gray-600 dark:text-gray-300" x-text="stock.location_code || '-'"></td>
                                        <td class="px-3 py-2 font-mono" x-text="stock.item_code"></td>
                                        <td class="px-3 py-2 truncate max-w-xs" x-text="stock.item_name"></td>
                                        <td class="px-3 py-2 text-right font-mono" x-text="stock.case_qty || 0"></td>
                                        <td class="px-3 py-2 text-right font-mono" x-text="stock.piece_qty || 0"></td>
                                        <td class="px-3 py-2 text-center text-xs" x-text="stock.expiration_date || '-'"></td>
                                        <td class="px-3 py-2 text-center text-xs" x-text="stock.received_at || '-'"></td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>
                </div>

                {{-- Footer --}}
                <div class="px-6 py-4 border-t border-gray-200 dark:border-gray-700 flex justify-end">
                    <button @click="showStockModal = false"
                            class="px-4 py-2 bg-gray-500 hover:bg-gray-600 text-white rounded font-medium text-sm">
                        閉じる
                    </button>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
    <script>
        function pickingRouteViewer() {
            return {
                zones: [],
                walls: [],
                fixedAreas: [],
                pickingAreas: [],
                pickingItems: [],
                taskInfo: null,
                showRouteLines: true,
                showWalkingOrder: true,
                showPickingAreas: true,
                showWalkableAreas: false,
                walkablePolygons: null,
                walkableNavmeta: null,
                routeLines: [],
                routePaths: [],
                draggedIndex: null,
                dragOverIndex: null,
                isRecalculating: false,
                canvasWidth: {{ $canvasWidth }},
                canvasHeight: {{ $canvasHeight }},
                showAreaModal: false,
                selectedArea: null,
                showStockModal: false,
                selectedZone: null,
                stockItems: [],
                filteredStockItems: [],
                stockSearchQuery: '',

                async init() {
                    await this.$nextTick();
                    this.$wire.loadInitialData();
                    await this.loadWalkableAreas();

                    if (this.$wire.selectedDeliveryCourseId && this.$wire.selectedPickingTaskId) {
                        await this.loadPickingRoute(this.$wire.selectedDeliveryCourseId);
                    }
                },

                async loadWalkableAreas() {
                    const warehouseId = this.$wire.selectedWarehouseId;
                    const floorId = this.$wire.selectedFloorId;

                    if (!warehouseId || !floorId) {
                        return;
                    }

                    try {
                        const response = await fetch(`/api/walkable-areas?warehouse_id=${warehouseId}&floor_id=${floorId}`);
                        const data = await response.json();

                        if (data.success && data.data) {
                            this.walkablePolygons = data.data.walkable_areas || [];
                            this.walkableNavmeta = data.data.navmeta || null;
                            this.renderWalkableAreas();
                        }
                    } catch (error) {
                        console.error('Failed to load walkable areas:', error);
                    }
                },

                renderWalkableAreas() {
                    const canvas = this.$refs.walkableCanvas;
                    if (!canvas) return;

                    const ctx = canvas.getContext('2d');
                    ctx.clearRect(0, 0, canvas.width, canvas.height);

                    if (!this.showWalkableAreas) return;

                    const rectangles = this.walkableNavmeta?.original_rectangles;
                    if (!rectangles || rectangles.length === 0) return;

                    ctx.fillStyle = 'rgba(34, 197, 94, 0.3)';
                    for (const rect of rectangles) {
                        ctx.fillRect(rect.x1, rect.y1, rect.x2 - rect.x1, rect.y2 - rect.y1);
                    }
                },

                async recalculateRoute() {
                    if (!this.taskInfo?.task_id || this.isRecalculating) return;

                    this.isRecalculating = true;
                    try {
                        await this.$wire.recalculatePickingRoute(this.taskInfo.task_id);
                        await this.loadPickingRoute();
                    } catch (error) {
                        console.error('Failed to recalculate route:', error);
                        alert('経路再計算に失敗しました');
                    } finally {
                        this.isRecalculating = false;
                    }
                },

                get canvasStyle() {
                    return {
                        backgroundImage: `linear-gradient(to right, rgba(0,0,0,0.06) 1px, transparent 1px),
                                        linear-gradient(to bottom, rgba(0,0,0,0.06) 1px, transparent 1px)`,
                        backgroundSize: `10px 10px`
                    };
                },

                async loadPickingRoute(courseId) {
                    const warehouseId = this.$wire.selectedWarehouseId;
                    const floorId = this.$wire.selectedFloorId;
                    const date = this.$wire.selectedDate;
                    const deliveryCourseId = courseId || this.$wire.selectedDeliveryCourseId;
                    const taskId = this.$wire.selectedPickingTaskId;

                    if (!warehouseId || !floorId || !date || !deliveryCourseId || !taskId) {
                        this.pickingItems = [];
                        this.taskInfo = null;
                        this.routeLines = [];
                        return;
                    }

                    try {
                        let url = `/api/picking-routes?warehouse_id=${warehouseId}&floor_id=${floorId}&date=${date}&delivery_course_id=${deliveryCourseId}&task_id=${taskId}`;
                        const response = await fetch(url);
                        const data = await response.json();

                        this.pickingItems = data.data || [];
                        this.taskInfo = data.task_info || null;
                        this.routePaths = data.route_paths || [];

                        await this.$nextTick();
                        this.calculateRouteLines();
                    } catch (error) {
                        console.error('Failed to load picking route:', error);
                        this.pickingItems = [];
                        this.taskInfo = null;
                        this.routeLines = [];
                    }
                },

                calculateRouteLines() {
                    this.routeLines = [];
                    if (!this.showRouteLines || this.pickingItems.length === 0) return;

                    if (this.routePaths && this.routePaths.length > 0) {
                        this.routeLines = this.routePaths.map(pathSegment => ({
                            path: pathSegment.path,
                            from: pathSegment.from,
                            to: pathSegment.to,
                            distance: pathSegment.distance
                        }));
                        return;
                    }

                    if (this.zones.length === 0) return;

                    const startPoint = { x: this.$wire.pickingStartX || 0, y: this.$wire.pickingStartY || 0 };
                    const endPoint = { x: this.$wire.pickingEndX || 0, y: this.$wire.pickingEndY || 0 };

                    const locationCenters = {};
                    this.pickingItems.forEach(item => {
                        if (!item.location_id) return;
                        const zone = this.zones.find(z => z.id === item.location_id);
                        if (zone && !locationCenters[item.location_id]) {
                            locationCenters[item.location_id] = {
                                x: (zone.x1 + zone.x2) / 2,
                                y: (zone.y1 + zone.y2) / 2
                            };
                        }
                    });

                    const orderedLocations = [];
                    let prevLocationId = null;
                    this.pickingItems.forEach(item => {
                        if (item.location_id && item.location_id !== prevLocationId) {
                            orderedLocations.push(item.location_id);
                            prevLocationId = item.location_id;
                        }
                    });

                    if (orderedLocations.length === 0) return;

                    if (startPoint.x > 0 || startPoint.y > 0) {
                        const firstLocation = locationCenters[orderedLocations[0]];
                        if (firstLocation) {
                            this.routeLines.push({
                                path: [[startPoint.x, startPoint.y], [firstLocation.x, firstLocation.y]],
                                from: 'START',
                                to: orderedLocations[0]
                            });
                        }
                    }

                    for (let i = 0; i < orderedLocations.length - 1; i++) {
                        const from = locationCenters[orderedLocations[i]];
                        const to = locationCenters[orderedLocations[i + 1]];
                        if (from && to) {
                            this.routeLines.push({
                                path: [[from.x, from.y], [to.x, to.y]],
                                from: orderedLocations[i],
                                to: orderedLocations[i + 1]
                            });
                        }
                    }

                    if ((endPoint.x > 0 || endPoint.y > 0) && (endPoint.x !== startPoint.x || endPoint.y !== startPoint.y)) {
                        const lastLocation = locationCenters[orderedLocations[orderedLocations.length - 1]];
                        if (lastLocation) {
                            this.routeLines.push({
                                path: [[lastLocation.x, lastLocation.y], [endPoint.x, endPoint.y]],
                                from: orderedLocations[orderedLocations.length - 1],
                                to: 'END'
                            });
                        }
                    }
                },

                renderRouteSvg() {
                    let svg = '';
                    if (this.routeLines.length === 0) return svg;

                    this.routeLines.forEach((routeSegment, index) => {
                        if (!routeSegment.path || routeSegment.path.length < 2) return;

                        const ratio = this.routeLines.length > 1 ? index / (this.routeLines.length - 1) : 0;
                        const color = this.getSequenceColor(ratio);
                        const points = routeSegment.path.map(p => `${p[0]},${p[1]}`).join(' ');

                        svg += `<polyline points="${points}" stroke="${color}" stroke-width="3" fill="none" stroke-linejoin="round" stroke-linecap="round" opacity="0.8" />`;

                        const arrowInterval = Math.max(1, Math.floor(routeSegment.path.length / 3));
                        for (let i = arrowInterval; i < routeSegment.path.length; i += arrowInterval) {
                            const arrowPoints = this.getArrowPoints({
                                x1: routeSegment.path[i - 1][0], y1: routeSegment.path[i - 1][1],
                                x2: routeSegment.path[i][0], y2: routeSegment.path[i][1]
                            });
                            svg += `<polygon points="${arrowPoints}" fill="${color}" opacity="0.9" />`;
                        }

                        const lastPoint = routeSegment.path[routeSegment.path.length - 1];
                        const secondLastPoint = routeSegment.path[routeSegment.path.length - 2];
                        const arrowPoints = this.getArrowPoints({
                            x1: secondLastPoint[0], y1: secondLastPoint[1],
                            x2: lastPoint[0], y2: lastPoint[1]
                        });
                        svg += `<polygon points="${arrowPoints}" fill="${color}" opacity="0.9" />`;

                        const midIndex = Math.floor(routeSegment.path.length / 2);
                        const midPoint = routeSegment.path[midIndex];
                        svg += `<circle cx="${midPoint[0]}" cy="${midPoint[1]}" r="12" fill="white" stroke="${color}" stroke-width="2" />`;
                        svg += `<text x="${midPoint[0]}" y="${midPoint[1]}" text-anchor="middle" dominant-baseline="central" font-size="11" font-weight="bold" fill="${color}">${index + 1}</text>`;
                    });
                    return svg;
                },

                getSequenceColor(ratio) {
                    if (ratio < 0.5) {
                        const r = Math.round(34 + (251 - 34) * (ratio * 2));
                        const g = Math.round(197 - (197 - 191) * (ratio * 2));
                        return `rgb(${r}, ${g}, 94)`;
                    } else {
                        const g = Math.round(191 - (191 - 68) * ((ratio - 0.5) * 2));
                        const b = Math.round(94 - 94 * ((ratio - 0.5) * 2));
                        return `rgb(239, ${g}, ${b})`;
                    }
                },

                getArrowPoints(route) {
                    const angle = Math.atan2(route.y2 - route.y1, route.x2 - route.x1);
                    const arrowLength = 10;
                    const arrowAngle = Math.PI / 6;
                    const x = route.x2, y = route.y2;
                    const x1 = x - arrowLength * Math.cos(angle - arrowAngle);
                    const y1 = y - arrowLength * Math.sin(angle - arrowAngle);
                    const x2 = x - arrowLength * Math.cos(angle + arrowAngle);
                    const y2 = y - arrowLength * Math.sin(angle + arrowAngle);
                    return `${x},${y} ${x1},${y1} ${x2},${y2}`;
                },

                hasPickingItems(zoneId) {
                    return this.pickingItems.some(item => item.location_id === zoneId);
                },

                getZoneWalkingOrders(zoneId) {
                    return this.pickingItems
                        .filter(item => item.location_id === zoneId)
                        .map(item => item.walking_order)
                        .filter((v, i, a) => a.indexOf(v) === i)
                        .sort((a, b) => a - b);
                },

                getZoneColor(zoneId) {
                    const defaultColor = '{{ $colors["location"]["rectangle"] ?? "#E0F2FE" }}';
                    return this.hasPickingItems(zoneId) ? '#DBEAFE' : defaultColor;
                },

                getPolygonPoints(polygon) {
                    if (!polygon || !Array.isArray(polygon)) return '';
                    return polygon.map(point => `${point.x},${point.y}`).join(' ');
                },

                showAreaDetail(area) {
                    this.selectedArea = area;
                    this.showAreaModal = true;
                },

                async showZoneStockModal(zone) {
                    this.selectedZone = zone;
                    this.stockSearchQuery = '';
                    this.stockItems = [];
                    this.filteredStockItems = [];
                    this.showStockModal = true;

                    // Load stocks for the zone using existing API endpoint
                    await this.loadZoneStocks(zone.id);
                },

                async loadZoneStocks(zoneId) {
                    if (!zoneId) return;

                    try {
                        const response = await fetch(`/api/zones/${zoneId}/stocks`);
                        const data = await response.json();

                        if (data.success && data.data) {
                            // Flatten the shelf-grouped data into a single list
                            const items = [];
                            const zone = this.selectedZone;
                            Object.values(data.data).forEach(shelf => {
                                if (shelf.items && Array.isArray(shelf.items)) {
                                    // Build full location code: code1 + code2 + code3
                                    const locationCode = (zone?.code1 || '') + (zone?.code2 || '') + (shelf.code3 || '');
                                    shelf.items.forEach(item => {
                                        items.push({
                                            id: item.real_stock_id,
                                            location_code: locationCode,
                                            code3: shelf.code3,
                                            item_code: item.item_code,
                                            item_name: item.item_name,
                                            case_qty: item.capacity_case > 0 ? Math.floor(item.total_qty / item.capacity_case) : 0,
                                            piece_qty: item.capacity_case > 0 ? item.total_qty % item.capacity_case : item.total_qty,
                                            expiration_date: item.expiration_date,
                                            received_at: item.received_at || null
                                        });
                                    });
                                }
                            });
                            this.stockItems = items;
                            this.filteredStockItems = [...items];
                        }
                    } catch (error) {
                        console.error('Failed to load zone stocks:', error);
                        this.stockItems = [];
                        this.filteredStockItems = [];
                    }
                },

                filterStockItems() {
                    const query = this.stockSearchQuery.toLowerCase().trim();
                    if (!query) {
                        this.filteredStockItems = [...this.stockItems];
                        return;
                    }

                    this.filteredStockItems = this.stockItems.filter(stock =>
                        (stock.item_code && stock.item_code.toLowerCase().includes(query)) ||
                        (stock.item_name && stock.item_name.toLowerCase().includes(query))
                    );
                },

                getTemperatureLabel(temperatureType) {
                    const labels = { 'NORMAL': '常温', 'CONSTANT': '定温', 'CHILLED': '冷蔵', 'FROZEN': '冷凍' };
                    return labels[temperatureType] || temperatureType || '-';
                },

                getAvailableQuantityLabel(flags) {
                    const labels = { 1: 'ケース', 2: 'バラ', 3: 'ケース+バラ', 4: 'ボール' };
                    return labels[flags] || '未設定';
                },

                getStatusLabel(status) {
                    const labels = { 'PENDING': '待機中', 'PICKING': 'ピッキング中', 'COMPLETED': '完了' };
                    return labels[status] || status || '-';
                },

                formatDateTime(datetime) {
                    if (!datetime) return '-';
                    try {
                        const date = new Date(datetime);
                        return date.toLocaleString('ja-JP', {
                            year: 'numeric', month: '2-digit', day: '2-digit',
                            hour: '2-digit', minute: '2-digit'
                        });
                    } catch (error) {
                        return datetime;
                    }
                },

                getQuantityTypeLabel(qtyType) {
                    const labels = { 'CASE': 'ケース', 'PIECE': 'バラ' };
                    return labels[qtyType] || qtyType || '';
                },

                handleDragStart(event, index) {
                    if (this.taskInfo?.status !== 'PENDING') {
                        event.preventDefault();
                        return;
                    }
                    this.draggedIndex = index;
                    event.dataTransfer.effectAllowed = 'move';
                    event.target.style.opacity = '0.5';
                },

                handleDragOver(event, index) {
                    if (this.draggedIndex === null || this.taskInfo?.status !== 'PENDING') return;
                    event.preventDefault();
                    this.dragOverIndex = index;
                },

                handleDrop(event, dropIndex) {
                    if (this.draggedIndex === null || this.taskInfo?.status !== 'PENDING') return;
                    event.preventDefault();

                    const dragIndex = this.draggedIndex;
                    if (dragIndex !== dropIndex) {
                        const items = [...this.pickingItems];
                        const [draggedItem] = items.splice(dragIndex, 1);
                        items.splice(dropIndex, 0, draggedItem);
                        this.pickingItems = items;
                        this.$wire.updateWalkingOrder(items.map(item => item.id));
                    }
                    this.dragOverIndex = null;
                },

                handleDragEnd(event) {
                    event.target.style.opacity = '1';
                    this.draggedIndex = null;
                    this.dragOverIndex = null;
                }
            };
        }
    </script>
    @endpush
</x-filament-panels::page>
