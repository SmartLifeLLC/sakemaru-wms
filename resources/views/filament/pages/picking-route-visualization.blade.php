<x-filament-panels::page>
    <div x-data="pickingRouteViewer()"
         x-init="init()"
         @layout-loaded.window="
             zones = Array.isArray($event.detail.zones) ? $event.detail.zones : [];
             walls = Array.isArray($event.detail.walls) ? $event.detail.walls : [];
             fixedAreas = Array.isArray($event.detail.fixedAreas) ? $event.detail.fixedAreas : [];
             // Recalculate route lines if picking items are already loaded
             if (pickingItems.length > 0) {
                 calculateRouteLines();
             }
         "
         @delivery-course-changed.window="loadPickingRoute($event.detail.courseId)"
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
                                <div class="mt-1 flex flex-wrap gap-1 justify-center">
                                    <template x-for="order in getZoneWalkingOrders(zone.id)" :key="order">
                                        <span class="inline-block bg-blue-500 text-white text-xs px-1.5 py-0.5 rounded"
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
                        </div>
                    </div>
                    </div>
                </div>

                {{-- Picking Task Information --}}
                <div x-show="taskInfo" class="bg-white dark:bg-gray-800 rounded-lg shadow p-3">
                    <h3 class="text-xs font-semibold border-b border-gray-200 dark:border-gray-700 pb-2 mb-2">ピッキング情報</h3>

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
                                            <template x-if="taskInfo?.status === 'PENDING'">
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
                routeLines: [],
                filterExpanded: true,
                draggedIndex: null,
                dragOverIndex: null,

                init() {
                    // Request initial data from Livewire
                    this.$nextTick(() => {
                        this.$wire.loadInitialData();
                    });
                },

                get canvasStyle() {
                    return {
                        backgroundImage: `linear-gradient(to right, rgba(0,0,0,0.03) 1px, transparent 1px),
                                        linear-gradient(to bottom, rgba(0,0,0,0.03) 1px, transparent 1px)`,
                        backgroundSize: `20px 20px`
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

                    if (!warehouseId || !floorId || !date || !deliveryCourseId) {
                        this.pickingItems = [];
                        this.taskInfo = null;
                        this.routeLines = [];
                        return;
                    }

                    try {
                        const url = `/api/picking-routes?warehouse_id=${warehouseId}&floor_id=${floorId}&date=${date}&delivery_course_id=${deliveryCourseId}`;
                        const response = await fetch(url);
                        const data = await response.json();

                        this.pickingItems = data.data || [];
                        this.taskInfo = data.task_info || null;

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
                 */
                calculateRouteLines() {
                    this.routeLines = [];

                    if (!this.showRouteLines || this.pickingItems.length < 2) {
                        return;
                    }

                    if (this.zones.length === 0) {
                        return;
                    }

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

                    // Create lines between consecutive locations
                    let prevLocation = null;
                    this.pickingItems.forEach(item => {
                        if (!item.location_id || !locationCenters[item.location_id]) return;

                        if (prevLocation && prevLocation !== item.location_id) {
                            const from = locationCenters[prevLocation];
                            const to = locationCenters[item.location_id];

                            // Skip if coordinates are undefined
                            if (!from || !to || from.x === undefined || to.x === undefined) {
                                return;
                            }

                            this.routeLines.push({
                                x1: from.x,
                                y1: from.y,
                                x2: to.x,
                                y2: to.y
                            });
                        }

                        prevLocation = item.location_id;
                    });
                },

                /**
                 * Render route SVG markup as HTML string
                 */
                renderRouteSvg() {
                    let svg = '';
                    this.routeLines.forEach(route => {
                        // Route line
                        svg += `<line x1="${route.x1}" y1="${route.y1}" x2="${route.x2}" y2="${route.y2}" stroke="#3B82F6" stroke-width="2" stroke-dasharray="5,5" />`;

                        // Arrow head
                        const arrowPoints = this.getArrowPoints(route);
                        svg += `<polygon points="${arrowPoints}" fill="#3B82F6" />`;
                    });
                    return svg;
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