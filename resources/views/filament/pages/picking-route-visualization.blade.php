<x-filament-panels::page>
    <div x-data="pickingRouteViewer()"
         x-init="init()"
         @layout-loaded.window="
             console.log('=== layout-loaded event received ===');
             console.log('zones from event:', $event.detail.zones);
             zones = Array.isArray($event.detail.zones) ? $event.detail.zones : [];
             walls = Array.isArray($event.detail.walls) ? $event.detail.walls : [];
             fixedAreas = Array.isArray($event.detail.fixedAreas) ? $event.detail.fixedAreas : [];
             console.log('zones array set to:', zones.length, 'items');
             // Recalculate route lines if picking items are already loaded
             if (pickingItems.length > 0) {
                 console.log('Recalculating route lines after zones loaded');
                 calculateRouteLines();
             }
         "
         @delivery-course-changed.window="loadPickingRoute($event.detail.courseId)"
         @date-changed.window="loadPickingRoute()"
         class="h-full flex flex-col">

        {{-- Combined Toolbar and Canvas --}}
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow flex-1 flex flex-col" style="height: calc(100vh - 120px);">

            {{-- Toolbar --}}
            <div class="flex flex-wrap gap-2 items-center text-sm p-3 border-b border-gray-200 dark:border-gray-700">
                {{-- Warehouse Selection --}}
                <select wire:model.live="selectedWarehouseId"
                    class="rounded-md border border-gray-300 dark:border-gray-600 text-sm px-3 py-1.5">
                    <option value="">倉庫を選択</option>
                    @foreach($this->warehouses as $wh)
                        <option value="{{ $wh->id }}">{{ $wh->name }}</option>
                    @endforeach
                </select>

                {{-- Floor Selection --}}
                <select wire:model.live="selectedFloorId"
                    class="rounded-md border border-gray-300 dark:border-gray-600 text-sm px-3 py-1.5">
                    <option value="">フロアを選択</option>
                    @foreach($this->floors as $floor)
                        <option value="{{ $floor->id }}">{{ $floor->name }}</option>
                    @endforeach
                </select>

                <div class="border-l border-gray-300 dark:border-gray-600 h-6 mx-1"></div>

                {{-- Date Selection --}}
                <label class="flex items-center gap-1">
                    <span class="text-sm font-medium">日付:</span>
                    <input type="date" wire:model.live="selectedDate"
                        class="rounded-md border border-gray-300 dark:border-gray-600 text-sm px-3 py-1.5">
                </label>

                {{-- Delivery Course Selection --}}
                <select wire:model.live="selectedDeliveryCourseId"
                    class="rounded-md border border-gray-300 dark:border-gray-600 text-sm px-3 py-1.5 min-w-[200px]">
                    <option value="">配送コースを選択</option>
                    @foreach($this->deliveryCourses as $course)
                        <option value="{{ $course['id'] }}">{{ $course['code'] }} - {{ $course['name'] }}</option>
                    @endforeach
                </select>

                <div class="border-l border-gray-300 dark:border-gray-600 h-6 mx-1"></div>

                {{-- Display Options --}}
                <label class="flex items-center gap-1.5">
                    <input type="checkbox" x-model="showRouteLines"
                        class="rounded border-gray-300">
                    <span class="text-sm">経路線表示</span>
                </label>

                <label class="flex items-center gap-1.5">
                    <input type="checkbox" x-model="showWalkingOrder"
                        class="rounded border-gray-300">
                    <span class="text-sm">順序番号表示</span>
                </label>

                <div class="ml-auto flex items-center gap-2">
                    <span x-show="pickingItems.length > 0" class="text-sm text-gray-600 dark:text-gray-400">
                        ピッキング: <span x-text="pickingItems.length"></span>件
                    </span>
                </div>
            </div>

            {{-- Floor Plan Canvas (Read-only) --}}
            <div class="relative overflow-auto flex-1 bg-gray-50 dark:bg-gray-900"
                 :style="canvasStyle"
                 id="picking-route-canvas"
                 style="min-height: 0;">

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

            {{-- Picking Items List --}}
            <div class="border-t border-gray-200 dark:border-gray-700 p-3 bg-gray-50 dark:bg-gray-900 max-h-48 overflow-y-auto">
                <h3 class="text-sm font-semibold mb-2">ピッキングアイテム一覧</h3>

                <template x-if="pickingItems.length === 0">
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        配送コースを選択してください
                    </p>
                </template>

                <template x-if="pickingItems.length > 0">
                    <div class="space-y-1">
                        <template x-for="item in pickingItems" :key="item.id">
                            <div class="flex items-center gap-2 text-xs p-2 bg-white dark:bg-gray-800 rounded border border-gray-200 dark:border-gray-700">
                                <span class="font-mono font-semibold text-blue-600 min-w-[30px]"
                                      x-text="item.walking_order"></span>
                                <span class="text-gray-600 dark:text-gray-400 min-w-[100px]"
                                      x-text="item.location_display"></span>
                                <span class="flex-1" x-text="item.item_name"></span>
                                <span class="font-medium"
                                      x-text="`${item.planned_qty} ${item.qty_type}`"></span>
                            </div>
                        </template>
                    </div>
                </template>
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
                showRouteLines: true,
                showWalkingOrder: true,
                routeLines: [],

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
                    console.log('=== loadPickingRoute START ===');
                    const warehouseId = this.$wire.selectedWarehouseId;
                    const floorId = this.$wire.selectedFloorId;
                    const date = this.$wire.selectedDate;
                    const deliveryCourseId = courseId || this.$wire.selectedDeliveryCourseId;

                    console.log('Parameters:', { warehouseId, floorId, date, deliveryCourseId });

                    if (!warehouseId || !floorId || !date || !deliveryCourseId) {
                        console.log('Missing parameters, clearing data');
                        this.pickingItems = [];
                        this.routeLines = [];
                        return;
                    }

                    try {
                        const url = `/api/picking-routes?warehouse_id=${warehouseId}&floor_id=${floorId}&date=${date}&delivery_course_id=${deliveryCourseId}`;
                        console.log('Fetching:', url);

                        const response = await fetch(url);
                        const data = await response.json();

                        console.log('API Response:', data);
                        console.log('pickingItems count:', data.data ? data.data.length : 0);

                        this.pickingItems = data.data || [];
                        console.log('Set pickingItems:', this.pickingItems);

                        // Wait for next tick to ensure zones are loaded
                        await this.$nextTick();
                        console.log('After nextTick, zones.length:', this.zones.length);

                        this.calculateRouteLines();
                    } catch (error) {
                        console.error('Failed to load picking route:', error);
                        this.pickingItems = [];
                        this.routeLines = [];
                    }

                    console.log('=== loadPickingRoute END ===');
                },

                /**
                 * Calculate route lines between picking locations
                 */
                calculateRouteLines() {
                    console.log('=== calculateRouteLines START ===');
                    console.log('showRouteLines:', this.showRouteLines);
                    console.log('pickingItems.length:', this.pickingItems.length);
                    console.log('zones.length:', this.zones.length);

                    this.routeLines = [];

                    if (!this.showRouteLines || this.pickingItems.length < 2) {
                        console.log('Early return: showRouteLines =', this.showRouteLines, ', pickingItems.length =', this.pickingItems.length);
                        return;
                    }

                    if (this.zones.length === 0) {
                        console.warn('Early return: zones array is empty');
                        return;
                    }

                    // Group by location and get center points
                    const locationCenters = {};
                    this.pickingItems.forEach(item => {
                        console.log('Processing item:', item.walking_order, 'location_id:', item.location_id);

                        if (!item.location_id) return;

                        const zone = this.zones.find(z => z.id === item.location_id);
                        console.log('Found zone for location', item.location_id, ':', zone ? 'YES' : 'NO');

                        if (zone && !locationCenters[item.location_id]) {
                            locationCenters[item.location_id] = {
                                x: (zone.x1 + zone.x2) / 2,
                                y: (zone.y1 + zone.y2) / 2
                            };
                            console.log('  Added center for location', item.location_id, ':', locationCenters[item.location_id]);
                        }
                    });

                    console.log('locationCenters:', locationCenters);

                    // Create lines between consecutive locations
                    let prevLocation = null;
                    this.pickingItems.forEach(item => {
                        if (!item.location_id || !locationCenters[item.location_id]) return;

                        if (prevLocation && prevLocation !== item.location_id) {
                            const from = locationCenters[prevLocation];
                            const to = locationCenters[item.location_id];

                            // Skip if coordinates are undefined
                            if (!from || !to || from.x === undefined || to.x === undefined) {
                                console.warn('Skipping route line due to undefined coordinates:', { from, to });
                                return;
                            }

                            const line = {
                                x1: from.x,
                                y1: from.y,
                                x2: to.x,
                                y2: to.y
                            };

                            this.routeLines.push(line);
                            console.log('Added route line:', line);
                        }

                        prevLocation = item.location_id;
                    });

                    console.log('Total routeLines created:', this.routeLines.length);
                    console.log('=== calculateRouteLines END ===');
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
                }
            };
        }
    </script>
    @endpush
</x-filament-panels::page>
