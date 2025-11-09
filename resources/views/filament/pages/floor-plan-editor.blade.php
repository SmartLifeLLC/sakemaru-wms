<x-filament-panels::page>
    <div x-data="floorPlanEditor()" x-init="init()" class="space-y-4">
        {{-- Toolbar --}}
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
            <div class="flex flex-col gap-4">
                {{-- First Row: Layout Management --}}
                <div class="flex flex-wrap gap-2 items-center">
                    <select x-model="selectedWarehouseId" @change="loadWarehouses()"
                        class="rounded-md border-gray-300 dark:border-gray-600 text-sm">
                        <option value="">倉庫を選択</option>
                        <template x-for="wh in warehouses" :key="wh.id">
                            <option :value="wh.id" x-text="wh.name"></option>
                        </template>
                    </select>

                    <select x-model="selectedFloorId" @change="switchFloor()"
                        class="rounded-md border-gray-300 dark:border-gray-600 text-sm">
                        <option value="">フロアを選択</option>
                        <template x-for="floor in floors" :key="floor.id">
                            <option :value="floor.id" x-text="floor.name"></option>
                        </template>
                    </select>

                    <button @click="saveLayout()"
                        class="px-4 py-2 bg-green-500 hover:bg-green-600 text-white rounded-md text-sm font-medium">
                        保存
                    </button>

                    <button @click="addZone()"
                        class="px-4 py-2 bg-purple-500 hover:bg-purple-600 text-white rounded-md text-sm font-medium">
                        区画追加
                    </button>

                    <button @click="exportCSV()"
                        class="px-4 py-2 bg-emerald-500 hover:bg-emerald-600 text-white rounded-md text-sm font-medium">
                        CSV出力
                    </button>

                    <button @click="deleteSelected()" x-show="selectedZones.length > 0"
                        class="px-4 py-2 bg-red-500 hover:bg-red-600 text-white rounded-md text-sm font-medium">
                        選択削除
                    </button>
                </div>

                {{-- Second Row: Grid Controls --}}
                <div class="flex flex-wrap gap-4 items-center text-sm">
                    <label class="flex items-center gap-2">
                        <input type="checkbox" x-model="gridEnabled" @change="updateGrid()"
                            class="rounded border-gray-300">
                        <span>グリッド表示</span>
                    </label>

                    <label class="flex items-center gap-2">
                        <span>グリッドサイズ:</span>
                        <input type="number" x-model="gridSize" @change="updateGrid()" min="4"
                            class="w-20 rounded-md border-gray-300 text-sm">
                        <span>px</span>
                    </label>

                    <label class="flex items-center gap-2">
                        <span>吸着しきい値:</span>
                        <input type="number" x-model="gridThreshold" min="0"
                            class="w-20 rounded-md border-gray-300 text-sm">
                        <span>px</span>
                    </label>

                    <span x-show="selectedZones.length > 0" class="text-gray-600 dark:text-gray-400">
                        選択: <span x-text="selectedZones.length"></span>個
                    </span>
                </div>
            </div>
        </div>

        {{-- Floor Plan Canvas --}}
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
            <div @mousedown="handleCanvasMouseDown($event)"
                 @mousemove="handleCanvasMouseMove($event)"
                 @mouseup="handleCanvasMouseUp($event)"
                 @contextmenu.prevent
                 class="relative border-2 border-gray-300 dark:border-gray-600 rounded-lg overflow-auto"
                 style="min-height: 800px; max-height: 800px;"
                 :style="canvasStyle"
                 id="floor-plan-canvas">

                {{-- Zone Blocks --}}
                <template x-for="zone in zones" :key="zone.id">
                    <div @mousedown.stop="handleZoneMouseDown($event, zone)"
                         @click="selectZone($event, zone)"
                         @dblclick="editZone(zone)"
                         :style="{
                             left: zone.x1_pos + 'px',
                             top: zone.y1_pos + 'px',
                             width: (zone.x2_pos - zone.x1_pos) + 'px',
                             height: (zone.y2_pos - zone.y1_pos) + 'px',
                             backgroundColor: getZoneColor(zone)
                         }"
                         :class="{
                             'border-2 border-blue-500': selectedZones.includes(zone.id),
                             'border border-gray-300 dark:border-gray-600': !selectedZones.includes(zone.id)
                         }"
                         class="absolute cursor-move flex flex-col items-center justify-center p-2 rounded shadow-sm select-none">

                        <div class="text-xs text-gray-500 dark:text-gray-400" x-text="zone.code1 + zone.code2"></div>

                        {{-- Resize Handle --}}
                        <div @mousedown.stop="handleResizeMouseDown($event, zone)"
                             class="absolute bottom-0 right-0 w-4 h-4 bg-blue-500 hover:bg-blue-600 cursor-se-resize rounded-tl opacity-75 hover:opacity-100 transition-opacity">
                        </div>
                    </div>
                </template>
            </div>
        </div>

        {{-- Unpositioned Locations Area --}}
        <div class="bg-white dark:bg-gray-800 rounded-lg shadow p-4">
            <h3 class="text-sm font-semibold mb-2 flex items-center gap-2">
                <span>未配置ロケーション</span>
                <span class="text-xs text-gray-500 dark:text-gray-400" x-text="`(${unpositionedLocations.length}件)`"></span>
            </h3>

            <div x-show="unpositionedLocations.length === 0" class="text-xs text-gray-500 dark:text-gray-400 py-3 text-center">
                すべてのロケーションが配置されています
            </div>

            <div x-show="unpositionedLocations.length > 0" class="overflow-x-auto" style="max-height: 120px;">
                <div class="flex gap-2 pb-2">
                    <template x-for="location in unpositionedLocations" :key="location.id">
                        <div class="border-2 border-dashed border-gray-300 dark:border-gray-600 rounded p-2 hover:border-blue-500 hover:bg-blue-50 dark:hover:bg-blue-900/20 transition-colors cursor-pointer flex-shrink-0"
                             @click="placeLocationInCenter(location)"
                             style="width: 100px;">
                            <div class="font-semibold text-xs truncate" x-text="location.code1 + location.code2"></div>
                            <div class="text-xs text-gray-600 dark:text-gray-400 truncate" x-text="location.name"></div>
                            <div class="text-xs text-gray-500 mt-1">
                                <span x-text="`在庫: ${location.stock_count || 0}`"></span>
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
                    <button @click="showEditModal = false"
                        class="flex-1 px-4 py-2 bg-gray-500 hover:bg-gray-600 text-white rounded-md">
                        閉じる
                    </button>
                </div>
            </div>
        </div>
    </div>

    @push('scripts')
    <script>
        function floorPlanEditor() {
            return {
                warehouses: [],
                floors: [],
                zones: [],
                unpositionedLocations: [],
                selectedWarehouseId: '',
                selectedFloorId: '',
                gridEnabled: true,
                gridSize: 20,
                gridThreshold: 6,
                selectedZones: [],
                dragState: null,
                resizeState: null,
                showEditModal: false,
                editingZone: {},
                selectedLevel: 1,
                levelStocks: {},

                init() {
                    this.loadWarehouses();
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
                    if (!this.selectedFloorId) return;

                    try {
                        const response = await fetch(`/api/floors/${this.selectedFloorId}/zones`);
                        const data = await response.json();
                        this.zones = data.data || [];
                        this.selectedZones = [];

                        // Load unpositioned locations
                        await this.loadUnpositionedLocations();
                    } catch (error) {
                        console.error('Failed to load zones:', error);
                    }
                },

                async loadUnpositionedLocations() {
                    if (!this.selectedFloorId) return;

                    try {
                        const response = await fetch(`/api/floors/${this.selectedFloorId}/unpositioned-locations`);
                        const data = await response.json();
                        this.unpositionedLocations = data.data || [];
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

                    // Remove from unpositioned
                    this.unpositionedLocations = this.unpositionedLocations.filter(l => l.id !== location.id);
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

                    const rect = event.currentTarget.getBoundingClientRect();
                    const canvas = document.getElementById('floor-plan-canvas');
                    const canvasRect = canvas.getBoundingClientRect();

                    this.dragState = {
                        zone,
                        startX: event.clientX,
                        startY: event.clientY,
                        offsetX: event.clientX - rect.left - canvasRect.left + canvas.scrollLeft,
                        offsetY: event.clientY - rect.top - canvasRect.top + canvas.scrollTop,
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

                handleCanvasMouseMove(event) {
                    if (this.dragState) {
                        const canvas = document.getElementById('floor-plan-canvas');
                        const canvasRect = canvas.getBoundingClientRect();

                        const deltaX = event.clientX - this.dragState.startX;
                        const deltaY = event.clientY - this.dragState.startY;

                        const newX1 = this.snapToGrid(this.dragState.originalX1 + deltaX);
                        const newY1 = this.snapToGrid(this.dragState.originalY1 + deltaY);
                        const width = this.dragState.originalX2 - this.dragState.originalX1;
                        const height = this.dragState.originalY2 - this.dragState.originalY1;

                        this.dragState.zone.x1_pos = Math.max(0, newX1);
                        this.dragState.zone.y1_pos = Math.max(0, newY1);
                        this.dragState.zone.x2_pos = this.dragState.zone.x1_pos + width;
                        this.dragState.zone.y2_pos = this.dragState.zone.y1_pos + height;
                    } else if (this.resizeState) {
                        const deltaX = event.clientX - this.resizeState.startX;
                        const deltaY = event.clientY - this.resizeState.startY;

                        const newX2 = this.snapToGrid(this.resizeState.originalX2 + deltaX);
                        const newY2 = this.snapToGrid(this.resizeState.originalY2 + deltaY);

                        this.resizeState.zone.x2_pos = Math.max(this.resizeState.zone.x1_pos + 20, newX2);
                        this.resizeState.zone.y2_pos = Math.max(this.resizeState.zone.y1_pos + 20, newY2);
                    }
                },

                handleCanvasMouseUp(event) {
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

                deleteSelected() {
                    if (!confirm(`選択した${this.selectedZones.length}個の区画を削除しますか？`)) {
                        return;
                    }
                    this.zones = this.zones.filter(z => !this.selectedZones.includes(z.id));
                    this.selectedZones = [];
                },

                async saveLayout() {
                    if (!this.selectedFloorId) {
                        alert('フロアを選択してください');
                        return;
                    }

                    try {
                        const response = await fetch(`/api/floors/${this.selectedFloorId}/zones`, {
                            method: 'POST',
                            headers: {
                                'Content-Type': 'application/json',
                                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content
                            },
                            body: JSON.stringify({ zones: this.zones })
                        });

                        if (response.ok) {
                            alert('保存しました');
                            await this.switchFloor();
                        } else {
                            alert('保存に失敗しました');
                        }
                    } catch (error) {
                        console.error('Failed to save layout:', error);
                        alert('保存に失敗しました');
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
                }
            };
        }
    </script>
    @endpush

    <style>
        [x-cloak] { display: none !important; }
    </style>
</x-filament-panels::page>
