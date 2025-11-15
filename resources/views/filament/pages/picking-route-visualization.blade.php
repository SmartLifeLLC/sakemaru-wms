<x-filament-panels::page>
    <div x-data="pickingRouteViewer()"
         x-init="init()"
         @layout-loaded.window="
             zones = Array.isArray($event.detail.zones) ? $event.detail.zones : [];
             walls = Array.isArray($event.detail.walls) ? $event.detail.walls : [];
             fixedAreas = Array.isArray($event.detail.fixedAreas) ? $event.detail.fixedAreas : [];
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

        {{-- Main Layout: Left (3/4) and Right (1/4) --}}
        <div class="flex gap-3" style="height: calc(100vh - 120px);">

            {{-- Left Side: Floor Plan Canvas (75%) --}}
            <div class="w-3/4 bg-white dark:bg-gray-800 rounded-lg shadow relative overflow-auto bg-gray-50 dark:bg-gray-900"
                 :style="canvasStyle"
                 id="picking-route-canvas">

                {{-- Canvas Inner Container --}}
                <div class="relative" style="min-width: {{ $canvasWidth }}px; min-height: {{ $canvasHeight }}px;">

                    {{-- Walkable Area Visualization Layer --}}
                    <canvas x-ref="walkableVisualizationCanvas"
                            :width="{{ $canvasWidth }}"
                            :height="{{ $canvasHeight }}"
                            class="absolute inset-0 pointer-events-none z-0"
                            :style="showWalkableAreas ? 'opacity: 0.3;' : 'opacity: 0; pointer-events: none;'">
                    </canvas>

                    {{-- Zone Blocks (Locations) - Read-only --}}
                    <template x-for="zone in zones" :key="zone.id">
                        <div :style="`
                                 position: absolute;
                                 left: ${zone.x1}px;
                                 top: ${zone.y1}px;
                                 width: ${zone.x2 - zone.x1}px;
                                 height: ${zone.y2 - zone.y1}px;
                                 background-color: ${getZoneColor(zone.id)};
                                 border-color: {{ $colors['location']['border'] ?? '#D1D5DB' }};
                                 color: {{ $textStyles['location']['color'] ?? '#6B7280' }};
                                 font-size: {{ $textStyles['location']['size'] ?? 12 }}px;
                             `"
                             :class="hasPickingItems(zone.id) ? 'border-2 border-blue-500' : 'border'"
                             class="flex flex-col items-center justify-center p-2 rounded shadow-sm select-none">

                            <div x-text="zone.name"></div>

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
                                 background-color: {{ $colors['wall']['rectangle'] ?? '#9CA3AF' }};
                                 border: 1px solid {{ $colors['wall']['border'] ?? '#6B7280' }};
                                 color: {{ $textStyles['wall']['color'] ?? '#374151' }};
                                 font-size: {{ $textStyles['wall']['size'] ?? 12 }}px;
                             `"
                             class="flex items-center justify-center select-none rounded">
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
                                 background-color: {{ $colors['fixed_area']['rectangle'] ?? '#FEF3C7' }};
                                 border: 1px solid {{ $colors['fixed_area']['border'] ?? '#F59E0B' }};
                                 color: {{ $textStyles['fixed_area']['color'] ?? '#92400E' }};
                                 font-size: {{ $textStyles['fixed_area']['size'] ?? 12 }}px;
                             `"
                             class="flex items-center justify-center select-none rounded">
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
                                z-index: 9999;"
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
                                z-index: 9999;"
                         class="flex items-center justify-center rounded-full bg-red-500 border-4 border-white shadow-xl select-none pointer-events-none">
                        <svg class="w-6 h-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                        <div class="absolute -bottom-6 left-1/2 transform -translate-x-1/2 text-xs font-bold text-red-600 whitespace-nowrap bg-white px-2 py-0.5 rounded shadow-md border border-red-200">終了</div>
                    </div>
                    @endif

                    {{-- Route Lines --}}
                    <template x-if="showRouteLines && routeLines.length > 0">
                        <div class="absolute inset-0 pointer-events-none">
                            <svg style="width: 100%; height: 100%;" x-html="renderRouteSvg()">
                            </svg>
                        </div>
                    </template>
                </div>
            </div>

            {{-- Right Side: Toolbar and Picking Items (25%) --}}
            <div class="w-1/4 flex flex-col gap-3">

                {{-- Toolbar --}}
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow">
                    {{-- Filter Header (Collapsible) --}}
                    <div @click="filterExpanded = !filterExpanded"
                         class="flex items-center justify-between p-3 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-700 rounded-t-lg">
                        <h3 class="text-sm font-semibold">フィルター</h3>
                        <svg class="w-4 h-4 transition-transform duration-200"
                             :class="{ 'rotate-180': filterExpanded }"
                             fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
                        </svg>
                    </div>

                    {{-- Filter Content --}}
                    <div x-show="filterExpanded"
                         x-collapse
                         class="p-3 pt-0 flex flex-col gap-3 border-t border-gray-200 dark:border-gray-700">

                    {{-- Warehouse and Floor Selection (2:1 ratio) --}}
                    <div class="flex gap-2">
                        <div class="w-2/3">
                            <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">倉庫</label>
                            <select wire:model.live="selectedWarehouseId"
                                class="w-full rounded-md border border-gray-300 dark:border-gray-600 text-sm px-3 py-1.5">
                                <option value="">選択してください</option>
                                @foreach($this->warehouses as $wh)
                                    <option value="{{ $wh->id }}">{{ $wh->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="w-1/3">
                            <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">フロア</label>
                            <select wire:model.live="selectedFloorId"
                                class="w-full rounded-md border border-gray-300 dark:border-gray-600 text-sm px-3 py-1.5">
                                <option value="">選択</option>
                                @foreach($this->floors as $floor)
                                    <option value="{{ $floor->id }}">{{ $floor->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    {{-- Delivery Course and Date Selection (2:1 ratio) --}}
                    <div class="flex gap-2">
                        <div class="w-2/3">
                            <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">配送コース</label>
                            <select wire:model.live="selectedDeliveryCourseId"
                                class="w-full rounded-md border border-gray-300 dark:border-gray-600 text-sm px-3 py-1.5">
                                <option value="">選択してください</option>
                                @foreach($this->deliveryCourses as $course)
                                    <option value="{{ $course['id'] }}">{{ $course['code'] }} - {{ $course['name'] }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="w-1/3">
                            <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">日付</label>
                            <input type="date" wire:model.live="selectedDate"
                                class="w-full rounded-md border border-gray-300 dark:border-gray-600 text-sm px-3 py-1.5">
                        </div>
                    </div>

                    {{-- Picking Task Selection --}}
                    <div>
                        <label class="block text-xs font-medium text-gray-700 dark:text-gray-300 mb-1">ピッキングタスク</label>
                        <select wire:model.live="selectedPickingTaskId"
                            class="w-full rounded-md border border-gray-300 dark:border-gray-600 text-sm px-3 py-1.5">
                            <option value="">選択してください</option>
                            @foreach($this->pickingTasks as $task)
                                <option value="{{ $task['id'] }}">
                                    タスク #{{ $task['id'] }}
                                    @if($task['picker_name'])
                                        - {{ $task['picker_name'] }}
                                    @endif
                                    ({{ $task['status'] }})
                                </option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Display Options (Single Line) --}}
                    <div class="border-t border-gray-200 dark:border-gray-700 pt-3">
                        <h4 class="text-xs font-medium text-gray-700 dark:text-gray-300 mb-2">表示オプション</h4>
                        <div class="flex items-center gap-4">
                            <label class="flex items-center gap-2">
                                <input type="checkbox" x-model="showRouteLines"
                                    class="rounded border-gray-300">
                                <span class="text-sm">経路線表示</span>
                            </label>
                            <label class="flex items-center gap-2">
                                <input type="checkbox" x-model="showWalkingOrder"
                                    class="rounded border-gray-300">
                                <span class="text-sm">順序番号表示</span>
                            </label>
                            <label class="flex items-center gap-2">
                                <input type="checkbox" x-model="showWalkableAreas"
                                       @change="renderWalkableAreas()"
                                    class="rounded border-gray-300">
                                <span class="text-sm">歩行領域表示</span>
                            </label>
                        </div>
                    </div>
                    </div>
                </div>

                {{-- Picking Task Information --}}
                <div x-show="taskInfo" class="bg-white dark:bg-gray-800 rounded-lg shadow p-3">
                    <div class="flex items-center justify-between border-b border-gray-200 dark:border-gray-700 pb-2 mb-2">
                        <h3 class="text-xs font-semibold">ピッキング情報</h3>
                        <div class="flex items-center gap-2">
                            <span class="text-xs bg-blue-100 dark:bg-blue-900/30 text-blue-600 dark:text-blue-400 px-2 py-0.5 rounded font-mono"
                                  x-text="'#' + (taskInfo?.task_id || '-')"></span>
                            <button @click="recalculateRoute()"
                                    x-show="taskInfo?.task_id && taskInfo?.status === 'PENDING'"
                                    :disabled="isRecalculating"
                                    class="text-xs bg-green-600 hover:bg-green-700 disabled:bg-gray-400 text-white px-2 py-0.5 rounded flex items-center gap-1 transition-colors"
                                    title="経路を再計算">
                                <svg class="w-3 h-3" :class="{'animate-spin': isRecalculating}" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                                </svg>
                                <span x-text="isRecalculating ? '計算中...' : '再計算'"></span>
                            </button>
                        </div>
                    </div>

                    <div class="space-y-1.5 text-xs">
                        <div class="flex justify-between">
                            <span class="text-gray-600 dark:text-gray-400">状態:</span>
                            <span class="font-medium"
                                  :class="{
                                      'text-green-600': taskInfo?.status === 'COMPLETED',
                                      'text-blue-600': taskInfo?.status === 'PICKING',
                                      'text-yellow-600': taskInfo?.status === 'PENDING',
                                      'text-gray-600': !taskInfo?.status
                                  }"
                                  x-text="getStatusLabel(taskInfo?.status)"></span>
                        </div>

                        <div x-show="taskInfo?.picker_name" class="flex justify-between">
                            <span class="text-gray-600 dark:text-gray-400">ピッカー:</span>
                            <span class="font-medium" x-text="taskInfo?.picker_name || '-'"></span>
                        </div>

                        <div x-show="taskInfo?.started_at" class="flex justify-between">
                            <span class="text-gray-600 dark:text-gray-400">開始:</span>
                            <span class="font-medium" x-text="formatDateTime(taskInfo?.started_at)"></span>
                        </div>

                        <div x-show="taskInfo?.completed_at" class="flex justify-between">
                            <span class="text-gray-600 dark:text-gray-400">終了:</span>
                            <span class="font-medium" x-text="formatDateTime(taskInfo?.completed_at)"></span>
                        </div>

                        <div x-show="taskInfo?.task_count > 1" class="flex justify-between">
                            <span class="text-gray-600 dark:text-gray-400">タスク数:</span>
                            <span class="font-medium" x-text="taskInfo?.task_count || 1"></span>
                        </div>
                    </div>
                </div>

                {{-- Route Optimization Explanation --}}
                <div x-show="pickingItems.length > 0" class="bg-white dark:bg-gray-800 rounded-lg shadow p-3">
                    <h3 class="text-xs font-semibold border-b border-gray-200 dark:border-gray-700 pb-2 mb-2">経路最適化について</h3>

                    <div class="space-y-2 text-xs text-gray-600 dark:text-gray-400">
                        <div class="flex items-start gap-1.5">
                            <svg class="w-3 h-3 mt-0.5 text-blue-500 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1 1 0 012 0zM9 9a1 1 0 000 2v3a1 1 0 001 1h1a1 1 0 100-2v-3a1 1 0 00-1-1H9z" clip-rule="evenodd"/>
                            </svg>
                            <p class="leading-relaxed">
                                ピッキング順序は<span class="font-semibold text-gray-800 dark:text-gray-200">A*アルゴリズム</span>と<span class="font-semibold text-gray-800 dark:text-gray-200">最近挿入法＋2-opt法</span>により最適化されています。
                            </p>
                        </div>

                        <div class="flex items-start gap-1.5">
                            <svg class="w-3 h-3 mt-0.5 text-green-500 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                            </svg>
                            <p class="leading-relaxed">
                                各ロケーションへの訪問順序は倉庫レイアウトを考慮し、<span class="font-semibold text-gray-800 dark:text-gray-200">総移動距離が最小</span>になるよう計算されます。
                            </p>
                        </div>

                        <div class="flex items-start gap-1.5">
                            <svg class="w-3 h-3 mt-0.5 text-purple-500 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                                <path d="M7 3a1 1 0 000 2h6a1 1 0 100-2H7zM4 7a1 1 0 011-1h10a1 1 0 110 2H5a1 1 0 01-1-1zM2 11a2 2 0 012-2h12a2 2 0 012 2v4a2 2 0 01-2 2H4a2 2 0 01-2-2v-4z"/>
                            </svg>
                            <p class="leading-relaxed">
                                同じロケーション内の商品は<span class="font-semibold text-gray-800 dark:text-gray-200">商品コード順</span>に並べられます。
                            </p>
                        </div>

                        <div class="pt-2 border-t border-gray-200 dark:border-gray-700 mt-2">
                            <p class="text-xs text-gray-500 dark:text-gray-500 italic">
                                PENDINGステータスのタスクはドラッグ＆ドロップで順序を手動調整できます。
                            </p>
                        </div>
                    </div>
                </div>

                {{-- Picking Items List --}}
                <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-3 flex-1 overflow-hidden flex flex-col">
                    <h3 class="text-xs font-semibold border-b border-gray-200 dark:border-gray-700 pb-1.5 mb-2">
                        <span>ピッキングアイテム</span>
                        <span x-show="pickingItems.length > 0" class="text-gray-500 dark:text-gray-400 font-normal">
                            (<span x-text="pickingItems.length"></span>件)
                        </span>
                    </h3>

                    <div class="overflow-y-auto flex-1">
                        <template x-if="pickingItems.length === 0">
                            <p class="text-xs text-gray-500 dark:text-gray-400 text-center py-4">
                                データなし
                            </p>
                        </template>

                        <template x-if="pickingItems.length > 0">
                            <div class="space-y-1.5">
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
                                         class="text-xs p-1.5 bg-gray-50 dark:bg-gray-900 rounded border border-gray-200 dark:border-gray-700 transition-colors">
                                        <div class="flex items-center gap-1.5 mb-0.5">
                                            <span class="font-mono font-semibold text-blue-600 bg-blue-50 dark:bg-blue-900/20 px-1 py-0.5 rounded text-xs"
                                                  x-text="item.walking_order"></span>
                                            <span class="text-gray-600 dark:text-gray-400 text-xs"
                                                  x-text="item.location_display"></span>
                                            <template x-if="item.distance_from_previous">
                                                <span class="ml-auto text-xs font-mono text-green-600 dark:text-green-400 bg-green-50 dark:bg-green-900/20 px-1 py-0.5 rounded"
                                                      x-text="Math.round(item.distance_from_previous) + 'px'"></span>
                                            </template>
                                            <template x-if="taskInfo?.status === 'PENDING' && !item.distance_from_previous">
                                                <svg class="w-3 h-3 text-gray-400 ml-auto" fill="currentColor" viewBox="0 0 20 20">
                                                    <path d="M10 6a2 2 0 110-4 2 2 0 010 4zM10 12a2 2 0 110-4 2 2 0 010 4zM10 18a2 2 0 110-4 2 2 0 010 4z"></path>
                                                </svg>
                                            </template>
                                        </div>
                                        <div class="text-gray-900 dark:text-gray-100 mb-0.5 text-xs leading-tight" x-text="item.item_name"></div>
                                        <div class="text-gray-600 dark:text-gray-400 text-xs">
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
    </div>

    @push('scripts')
    <script>
        function pickingRouteViewer() {
            return {
                zones: [],
                walls: [],
                fixedAreas: [],
                pickingItems: [],
                taskInfo: null,
                showRouteLines: true,
                showWalkingOrder: true,
                showWalkableAreas: false,
                walkablePolygons: null,
                walkableNavmeta: null,
                routeLines: [],
                routePaths: [], // A* calculated paths
                filterExpanded: true,
                draggedIndex: null,
                dragOverIndex: null,
                isRecalculating: false,

                async init() {
                    // Request initial data from Livewire
                    await this.$nextTick();
                    this.$wire.loadInitialData();
                    await this.loadWalkableAreas();

                    // Auto-load picking route if delivery course and task are selected
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
                            console.log('Loaded walkable areas:', {
                                polygonCount: this.walkablePolygons.length,
                                navmeta: this.walkableNavmeta
                            });
                            // Always render to initialize canvas, even if not shown
                            this.renderWalkableAreas();
                        }
                    } catch (error) {
                        console.error('Failed to load walkable areas:', error);
                    }
                },

                renderWalkableAreas() {
                    const canvas = this.$refs.walkableVisualizationCanvas;
                    if (!canvas) {
                        console.warn('Walkable canvas not found');
                        return;
                    }

                    const ctx = canvas.getContext('2d');
                    ctx.clearRect(0, 0, canvas.width, canvas.height);

                    if (!this.showWalkableAreas) {
                        return;
                    }

                    // Use original_rectangles to match floor-plan-editor display
                    // Note: Route calculation uses eroded walkable_areas (20px smaller for cart width)
                    const rectangles = this.walkableNavmeta?.original_rectangles;
                    const cellSize = 10; // Fixed cell size

                    if (!rectangles || rectangles.length === 0) {
                        console.warn('No walkable rectangles to render');
                        return;
                    }

                    console.log('Rendering walkable areas as bitmap:', {
                        rectangleCount: rectangles.length,
                        cellSize: cellSize,
                        canvasSize: [canvas.width, canvas.height]
                    });

                    // Draw walkable bitmap cells (same as floor-plan-editor)
                    ctx.fillStyle = 'rgba(34, 197, 94, 0.3)'; // green-500 with transparency

                    // Convert rectangles to bitmap and draw
                    for (const rect of rectangles) {
                        // Each rectangle represents a block of cells
                        ctx.fillRect(
                            rect.x1,
                            rect.y1,
                            rect.x2 - rect.x1,
                            rect.y2 - rect.y1
                        );
                    }
                },

                /**
                 * Recalculate route for current task
                 */
                async recalculateRoute() {
                    if (!this.taskInfo?.task_id || this.isRecalculating) {
                        return;
                    }

                    this.isRecalculating = true;

                    try {
                        await this.$wire.recalculatePickingRoute(this.taskInfo.task_id);
                        // Reload the route after recalculation
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

                /**
                 * Load picking route data for selected delivery course
                 */
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

                        // Wait for next tick to ensure zones are loaded
                        await this.$nextTick();

                        this.calculateRouteLines();
                    } catch (error) {
                        console.error('Failed to load picking route:', error);
                        this.pickingItems = [];
                        this.taskInfo = null;
                        this.routeLines = [];
                    }
                },

                /**
                 * Calculate route lines between picking locations
                 * Uses A* calculated paths from API if available
                 */
                calculateRouteLines() {
                    this.routeLines = [];

                    if (!this.showRouteLines || this.pickingItems.length === 0) {
                        return;
                    }

                    // Use A* calculated paths if available
                    if (this.routePaths && this.routePaths.length > 0) {
                        this.routeLines = this.routePaths.map(pathSegment => ({
                            path: pathSegment.path,
                            from: pathSegment.from,
                            to: pathSegment.to,
                            distance: pathSegment.distance
                        }));
                        return;
                    }

                    // Fallback: simple straight lines (for backward compatibility)
                    if (this.zones.length === 0) {
                        return;
                    }

                    // Get picking start and end points from Livewire
                    const startPoint = {
                        x: this.$wire.pickingStartX || 0,
                        y: this.$wire.pickingStartY || 0
                    };
                    const endPoint = {
                        x: this.$wire.pickingEndX || 0,
                        y: this.$wire.pickingEndY || 0
                    };

                    // Group by location and get center points
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

                    // Get ordered list of unique locations
                    const orderedLocations = [];
                    let prevLocationId = null;
                    this.pickingItems.forEach(item => {
                        if (item.location_id && item.location_id !== prevLocationId) {
                            orderedLocations.push(item.location_id);
                            prevLocationId = item.location_id;
                        }
                    });

                    if (orderedLocations.length === 0) {
                        return;
                    }

                    // 1. Line from START point to first location
                    if (startPoint.x > 0 || startPoint.y > 0) {
                        const firstLocationId = orderedLocations[0];
                        const firstLocation = locationCenters[firstLocationId];

                        if (firstLocation) {
                            this.routeLines.push({
                                path: [[startPoint.x, startPoint.y], [firstLocation.x, firstLocation.y]],
                                from: 'START',
                                to: firstLocationId
                            });
                        }
                    }

                    // 2. Lines between consecutive locations
                    for (let i = 0; i < orderedLocations.length - 1; i++) {
                        const fromLocationId = orderedLocations[i];
                        const toLocationId = orderedLocations[i + 1];

                        const from = locationCenters[fromLocationId];
                        const to = locationCenters[toLocationId];

                        if (from && to) {
                            this.routeLines.push({
                                path: [[from.x, from.y], [to.x, to.y]],
                                from: fromLocationId,
                                to: toLocationId
                            });
                        }
                    }

                    // 3. Line from last location to END point
                    if ((endPoint.x > 0 || endPoint.y > 0) &&
                        (endPoint.x !== startPoint.x || endPoint.y !== startPoint.y)) {
                        const lastLocationId = orderedLocations[orderedLocations.length - 1];
                        const lastLocation = locationCenters[lastLocationId];

                        if (lastLocation) {
                            this.routeLines.push({
                                path: [[lastLocation.x, lastLocation.y], [endPoint.x, endPoint.y]],
                                from: lastLocationId,
                                to: 'END'
                            });
                        }
                    }
                },

                /**
                 * Render route SVG markup as HTML string
                 * Draws polylines following A* calculated paths
                 */
                renderRouteSvg() {
                    let svg = '';

                    if (this.routeLines.length === 0) {
                        return svg;
                    }

                    // Use gradient color based on picking order (green -> blue -> red)
                    this.routeLines.forEach((routeSegment, index) => {
                        if (!routeSegment.path || routeSegment.path.length < 2) {
                            return;
                        }

                        // Calculate color based on sequence (gradient from green to red)
                        const ratio = this.routeLines.length > 1 ? index / (this.routeLines.length - 1) : 0;
                        const color = this.getSequenceColor(ratio);

                        // Convert path points to polyline
                        const points = routeSegment.path.map(p => `${p[0]},${p[1]}`).join(' ');

                        // Draw polyline with thicker stroke for better visibility
                        svg += `<polyline points="${points}" stroke="${color}" stroke-width="3" fill="none" stroke-linejoin="round" stroke-linecap="round" opacity="0.8" />`;

                        // Draw arrows along the path (every few segments to show direction clearly)
                        const arrowInterval = Math.max(1, Math.floor(routeSegment.path.length / 3));
                        for (let i = arrowInterval; i < routeSegment.path.length; i += arrowInterval) {
                            const p1 = routeSegment.path[i - 1];
                            const p2 = routeSegment.path[i];

                            const arrowPoints = this.getArrowPoints({
                                x1: p1[0],
                                y1: p1[1],
                                x2: p2[0],
                                y2: p2[1]
                            });
                            svg += `<polygon points="${arrowPoints}" fill="${color}" opacity="0.9" />`;
                        }

                        // Always draw arrow at the end
                        const lastPoint = routeSegment.path[routeSegment.path.length - 1];
                        const secondLastPoint = routeSegment.path[routeSegment.path.length - 2];

                        const arrowPoints = this.getArrowPoints({
                            x1: secondLastPoint[0],
                            y1: secondLastPoint[1],
                            x2: lastPoint[0],
                            y2: lastPoint[1]
                        });
                        svg += `<polygon points="${arrowPoints}" fill="${color}" opacity="0.9" />`;

                        // Draw sequence number at the midpoint of the path
                        const midIndex = Math.floor(routeSegment.path.length / 2);
                        const midPoint = routeSegment.path[midIndex];
                        svg += `<circle cx="${midPoint[0]}" cy="${midPoint[1]}" r="12" fill="white" stroke="${color}" stroke-width="2" />`;
                        svg += `<text x="${midPoint[0]}" y="${midPoint[1]}" text-anchor="middle" dominant-baseline="central" font-size="11" font-weight="bold" fill="${color}">${index + 1}</text>`;
                    });
                    return svg;
                },

                /**
                 * Get color based on sequence ratio (0.0 to 1.0)
                 * Green (start) -> Yellow (middle) -> Red (end)
                 */
                getSequenceColor(ratio) {
                    // Green -> Yellow -> Red gradient
                    if (ratio < 0.5) {
                        // Green to Yellow
                        const r = Math.round(34 + (251 - 34) * (ratio * 2));
                        const g = Math.round(197 - (197 - 191) * (ratio * 2));
                        const b = 94;
                        return `rgb(${r}, ${g}, ${b})`;
                    } else {
                        // Yellow to Red
                        const r = 239;
                        const g = Math.round(191 - (191 - 68) * ((ratio - 0.5) * 2));
                        const b = Math.round(94 - 94 * ((ratio - 0.5) * 2));
                        return `rgb(${r}, ${g}, ${b})`;
                    }
                },

                /**
                 * Calculate arrow points for route direction
                 */
                getArrowPoints(route) {
                    const angle = Math.atan2(route.y2 - route.y1, route.x2 - route.x1);
                    const arrowLength = 10;
                    const arrowAngle = Math.PI / 6; // 30 degrees

                    const x = route.x2;
                    const y = route.y2;

                    const x1 = x - arrowLength * Math.cos(angle - arrowAngle);
                    const y1 = y - arrowLength * Math.sin(angle - arrowAngle);
                    const x2 = x - arrowLength * Math.cos(angle + arrowAngle);
                    const y2 = y - arrowLength * Math.sin(angle + arrowAngle);

                    return `${x},${y} ${x1},${y1} ${x2},${y2}`;
                },

                /**
                 * Check if zone has any picking items
                 */
                hasPickingItems(zoneId) {
                    return this.pickingItems.some(item => item.location_id === zoneId);
                },

                /**
                 * Get walking orders for a specific zone
                 */
                getZoneWalkingOrders(zoneId) {
                    return this.pickingItems
                        .filter(item => item.location_id === zoneId)
                        .map(item => item.walking_order)
                        .filter((v, i, a) => a.indexOf(v) === i) // unique values
                        .sort((a, b) => a - b);
                },

                /**
                 * Get zone color based on picking items
                 */
                getZoneColor(zoneId) {
                    const defaultColor = '{{ $colors["location"]["rectangle"] ?? "#E0F2FE" }}';

                    if (!this.hasPickingItems(zoneId)) {
                        return defaultColor;
                    }

                    // Highlight zones with picking items
                    return '#DBEAFE'; // Light blue
                },

                /**
                 * Get status label in Japanese
                 */
                getStatusLabel(status) {
                    const labels = {
                        'PENDING': '待機中',
                        'PICKING': 'ピッキング中',
                        'COMPLETED': '完了',
                    };
                    return labels[status] || status || '-';
                },

                /**
                 * Format datetime string
                 */
                formatDateTime(datetime) {
                    if (!datetime) return '-';

                    try {
                        const date = new Date(datetime);
                        return date.toLocaleString('ja-JP', {
                            year: 'numeric',
                            month: '2-digit',
                            day: '2-digit',
                            hour: '2-digit',
                            minute: '2-digit',
                        });
                    } catch (error) {
                        return datetime;
                    }
                },

                /**
                 * Get quantity type label in Japanese
                 */
                getQuantityTypeLabel(qtyType) {
                    const labels = {
                        'CASE': 'ケース',
                        'PIECE': 'バラ',
                    };
                    return labels[qtyType] || qtyType || '';
                },

                /**
                 * Handle drag start
                 */
                handleDragStart(event, index) {
                    if (this.taskInfo?.status !== 'PENDING') {
                        event.preventDefault();
                        return;
                    }
                    this.draggedIndex = index;
                    event.dataTransfer.effectAllowed = 'move';
                    event.target.style.opacity = '0.5';
                },

                /**
                 * Handle drag over
                 */
                handleDragOver(event, index) {
                    if (this.draggedIndex === null || this.taskInfo?.status !== 'PENDING') {
                        return;
                    }
                    event.preventDefault();
                    this.dragOverIndex = index;
                },

                /**
                 * Handle drop
                 */
                handleDrop(event, dropIndex) {
                    if (this.draggedIndex === null || this.taskInfo?.status !== 'PENDING') {
                        return;
                    }
                    event.preventDefault();

                    const dragIndex = this.draggedIndex;

                    if (dragIndex !== dropIndex) {
                        // Reorder array
                        const items = [...this.pickingItems];
                        const [draggedItem] = items.splice(dragIndex, 1);
                        items.splice(dropIndex, 0, draggedItem);

                        this.pickingItems = items;

                        // Send new order to backend
                        const itemIds = items.map(item => item.id);
                        this.$wire.updateWalkingOrder(itemIds);
                    }

                    this.dragOverIndex = null;
                },

                /**
                 * Handle drag end
                 */
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