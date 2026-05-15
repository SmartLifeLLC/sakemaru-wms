<style>
    .proxy-shipment-modal {
        max-width: 82vw !important;
    }
    .proxy-shipment-modal .fi-modal-header {
        background-color: #1e3a5f;
        border-radius: 0.75rem 0.75rem 0 0;
        padding-top: 0.75rem;
        padding-bottom: 0.75rem;
    }
    .proxy-shipment-modal .fi-modal-header .fi-modal-heading {
        color: #ffffff;
    }
    .proxy-shipment-modal .fi-modal-header .fi-modal-close-btn {
        color: #ffffff;
    }
    .proxy-shipment-modal .fi-modal-footer {
        justify-content: flex-end !important;
    }
    .proxy-shipment-modal .fi-modal-footer > * {
        justify-content: flex-end !important;
    }
    .proxy-shipment-left-pane {
        font-size: 0.75rem;
        line-height: 1rem;
    }
    .proxy-shipment-left-pane table,
    .proxy-shipment-left-pane th,
    .proxy-shipment-left-pane td,
    .proxy-shipment-left-pane select,
    .proxy-shipment-left-pane input,
    .proxy-shipment-left-pane button {
        font-size: 0.75rem !important;
        line-height: 1rem !important;
    }
    .proxy-shipment-map-label {
        padding: 1px 5px !important;
        border: 1px solid rgba(31, 41, 55, 0.25) !important;
        border-radius: 4px !important;
        background: rgba(255, 255, 255, 0.9) !important;
        color: #111827 !important;
        font-size: 11px !important;
        font-weight: 600 !important;
        line-height: 1.2 !important;
        box-shadow: 0 1px 2px rgba(15, 23, 42, 0.16) !important;
    }
</style>

<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    <div x-data="{
        state: $wire.entangle('{{ $getStatePath() }}'),
        shortageQty: {{ $shortage_qty }},
        stocks: {{ json_encode($stocks) }},
        nearestWarehouseId: {{ $nearest_warehouse_id ?? 'null' }},
        sameCourseAllocations: {{ json_encode($same_course_allocations ?? []) }},
        courseNearestWarehouses: {{ json_encode($course_nearest_warehouses ?? []) }},
        hasDeliveryCourse: {{ json_encode($has_delivery_course ?? false) }},
        locations: {{ json_encode($locations ?? []) }},
        map: null,
        markers: {},
        routeLine: null,
        totalDistance: 0,

        get allocatedQty() {
            return (this.state || []).reduce((sum, item) => sum + (parseInt(item.assign_qty) || 0), 0);
        },

        get remainingQty() {
            return Math.max(0, this.shortageQty - this.allocatedQty);
        },

        get sortedStocks() {
            return [...this.stocks].sort((a, b) => {
                if (a.warehouse_id == this.nearestWarehouseId) return -1;
                if (b.warehouse_id == this.nearestWarehouseId) return 1;
                return 0;
            });
        },

        get stockRows() {
            const sorted = this.sortedStocks;
            const half = Math.ceil(sorted.length / 2);
            const rows = [];
            for (let i = 0; i < half; i++) {
                rows.push({
                    left: sorted[i] || null,
                    right: sorted[i + half] || null,
                });
            }
            return rows;
        },

        calcDistance(lat1, lng1, lat2, lng2) {
            const R = 6371;
            const dLat = (lat2 - lat1) * Math.PI / 180;
            const dLng = (lng2 - lng1) * Math.PI / 180;
            const a = Math.sin(dLat/2)**2 + Math.cos(lat1*Math.PI/180) * Math.cos(lat2*Math.PI/180) * Math.sin(dLng/2)**2;
            return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
        },

        loadLeaflet() {
            return new Promise((resolve, reject) => {
                if (window.L) { resolve(); return; }

                if (!document.querySelector('link[href*=leaflet]')) {
                    const link = document.createElement('link');
                    link.rel = 'stylesheet';
                    link.href = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css';
                    document.head.appendChild(link);
                }

                const existing = document.querySelector('script[src*=leaflet]');
                if (existing) {
                    existing.addEventListener('load', () => resolve());
                    if (window.L) resolve();
                    return;
                }

                const script = document.createElement('script');
                script.src = 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js';
                script.onload = () => resolve();
                script.onerror = () => reject(new Error('Leaflet load failed'));
                document.head.appendChild(script);
            });
        },

        async initMap() {
            if (!this.locations.length) return;
            if (this.map) return;

            await this.loadLeaflet();

            const departure = this.locations.find(loc => loc.type === 'departure');
            const initialCenter = departure ? [departure.lat, departure.lng] : [36.5, 136.5];

            this.map = L.map(this.$refs.mapContainer).setView(initialCenter, 12);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap'
            }).addTo(this.map);

            this.locations.filter(loc => loc.type !== 'warehouse' || loc.stock_info).forEach(loc => {
                let color, opacity;
                if (loc.type === 'departure') { color = '#000000'; opacity = 0.9; }
                else if (loc.type === 'customer') { color = '#ef4444'; opacity = 0.8; }
                else { color = '#3b82f6'; opacity = 0.8; }

                const marker = L.circleMarker([loc.lat, loc.lng], {
                    radius: 8, fillColor: color, color: '#fff', weight: 2, fillOpacity: opacity
                }).addTo(this.map);

                let popup = '<b>' + loc.name + '</b>';
                if (loc.type === 'warehouse' && loc.stock_info) popup += '<br>在庫: ' + loc.stock_info;
                else if (loc.type === 'warehouse') popup += '<br><span style=\'color:#999\'>在庫なし</span>';
                if (loc.type === 'departure') popup += '<br><span style=\'color:#666\'>出発倉庫</span>';
                if (loc.type === 'customer') popup += '<br><span style=\'color:#ef4444\'>納品先</span>';
                marker.bindPopup(popup);
                marker.bindTooltip(loc.name, {
                    permanent: true,
                    direction: 'top',
                    offset: [0, -10],
                    className: 'proxy-shipment-map-label',
                });

                this.markers[loc.type + '_' + loc.id] = marker;
            });
        },

        updateRoute() {
            if (!this.map) return;

            if (this.routeLine) {
                this.map.removeLayer(this.routeLine);
                this.routeLine = null;
            }
            this.totalDistance = 0;

            // マーカーをリセット
            Object.entries(this.markers).forEach(([key, marker]) => {
                if (key.startsWith('warehouse_')) {
                    marker.setStyle({ radius: 8, fillColor: '#3b82f6' });
                }
            });

            if (!this.state || this.state.length === 0) return;

            const departure = this.locations.find(l => l.type === 'departure');
            if (!departure) return;

            const selectedIds = this.state.map(a => parseInt(a.from_warehouse_id)).filter(Boolean);
            const selectedWarehouses = selectedIds.map(id => this.locations.find(l => l.type === 'warehouse' && l.id === id)).filter(Boolean);

            if (selectedWarehouses.length === 0) return;

            const customers = this.locations.filter(l => l.type === 'customer');

            // ルート: 出発倉庫 → 選択倉庫 → 納品先
            const points = [departure, ...selectedWarehouses, ...customers];
            const latlngs = points.map(p => [p.lat, p.lng]);

            this.routeLine = L.polyline(latlngs, { color: '#2563eb', weight: 3, opacity: 0.7, dashArray: '8, 4' }).addTo(this.map);

            for (let i = 0; i < points.length - 1; i++) {
                this.totalDistance += this.calcDistance(points[i].lat, points[i].lng, points[i+1].lat, points[i+1].lng);
            }

            // 選択倉庫マーカーを強調
            selectedIds.forEach(id => {
                const key = 'warehouse_' + id;
                if (this.markers[key]) {
                    this.markers[key].setStyle({ radius: 12, fillColor: '#f59e0b' });
                }
            });
        },

        addAllocation(warehouseId, qty) {
            if (qty <= 0) {
                alert('残欠品数が0のため追加できません。');
                return;
            }

            this.state = this.state || [];

            if (this.state.find(item => item.from_warehouse_id == warehouseId)) {
                alert('この倉庫は既に選択されています。');
                return;
            }

            this.state.push({
                from_warehouse_id: warehouseId,
                assign_qty: qty,
                assign_qty_type: '{{ $qty_type }}',
            });
        },

        removeAllocation(index) {
            this.state.splice(index, 1);
        },

        validateQty(index) {
            const item = this.state[index];
            let val = parseInt(item.assign_qty);

            if (isNaN(val) || val < 0) {
                val = 0;
            }

            const otherTotal = (this.state || []).reduce((sum, it, i) => {
                if (i === index) return sum;
                return sum + (parseInt(it.assign_qty) || 0);
            }, 0);

            if (val + otherTotal > this.shortageQty) {
                val = Math.max(0, this.shortageQty - otherTotal);
            }

            item.assign_qty = val;
        },

        addManualAllocation() {
            this.state = this.state || [];
            this.state.push({
                from_warehouse_id: '',
                assign_qty: this.remainingQty > 0 ? this.remainingQty : 0,
                assign_qty_type: '{{ $qty_type }}',
            });
        }
    }"
    x-init="$nextTick(async () => { await initMap(); if (map) { setTimeout(() => map.invalidateSize(), 200); setTimeout(() => map.invalidateSize(), 600); } updateRoute(); $watch('state', () => updateRoute(), { deep: true }); })"
    >
        <!-- 2カラムレイアウト: 左=情報＋フォーム / 右=マップ -->
        <div class="grid grid-cols-12 gap-4 -mt-2" style="height: 62vh;">
            <!-- 左カラム: 商品情報＋倉庫情報＋在庫リスト＋横持ち出荷指示 -->
            <div class="proxy-shipment-left-pane col-span-7 overflow-y-auto pr-1">
                <!-- 商品基本情報 -->
                <div class="mb-3 overflow-hidden rounded-lg border border-gray-300 dark:border-gray-600">
                    <table class="w-full text-sm border-collapse">
                        <tr class="border-b border-gray-200 dark:border-gray-700">
                            <th class="text-left px-2 py-1 bg-gray-50 dark:bg-gray-800 text-gray-500 dark:text-gray-400 font-medium w-[5.5rem] whitespace-nowrap">商品CD</th>
                            <td class="px-2 py-1 text-gray-900 dark:text-gray-100 bg-white dark:bg-gray-900" colspan="3">{{ $item_code }}　{{ $item_name }}</td>
                        </tr>
                        <tr>
                            <th class="text-left px-2 py-1 bg-gray-50 dark:bg-gray-800 text-gray-500 dark:text-gray-400 font-medium whitespace-nowrap">{{ $partner_label ?? '得意先CD' }}</th>
                            <td class="px-2 py-1 text-gray-900 dark:text-gray-100 bg-white dark:bg-gray-900" colspan="3">{{ $partner_code }}　{{ $partner_name }}</td>
                        </tr>
                    </table>
                </div>

                <!-- 倉庫・受注情報 -->
                <div class="mb-3 overflow-hidden rounded-lg border border-gray-300 dark:border-gray-600">
                    <table class="w-full text-sm border-collapse">
                        <tr class="border-b border-gray-200 dark:border-gray-700">
                            <th class="text-left px-2 py-1 bg-gray-50 dark:bg-gray-800 text-gray-500 dark:text-gray-400 font-medium w-[5.5rem] whitespace-nowrap">依頼元倉庫</th>
                            <td class="px-2 py-1 text-gray-900 dark:text-gray-100 bg-white dark:bg-gray-900">{{ $warehouse_name }}</td>
                            <th class="text-left px-2 py-1 bg-gray-50 dark:bg-gray-800 text-gray-500 dark:text-gray-400 font-medium whitespace-nowrap">受注単位</th>
                            <td class="px-2 py-1 text-gray-900 dark:text-gray-100 bg-white dark:bg-gray-900">{{ $qty_type_label }}</td>
                            <th class="text-left px-2 py-1 bg-gray-50 dark:bg-gray-800 text-gray-500 dark:text-gray-400 font-medium whitespace-nowrap">受注数</th>
                            <td class="px-2 py-1 text-gray-900 dark:text-gray-100 bg-white dark:bg-gray-900">{{ $order_qty }}</td>
                            <th class="text-left px-2 py-1 bg-gray-50 dark:bg-gray-800 text-gray-500 dark:text-gray-400 font-medium whitespace-nowrap">{{ $picked_qty_label ?? '引当数' }}</th>
                            <td class="px-2 py-1 text-gray-900 dark:text-gray-100 bg-white dark:bg-gray-900">{{ $picked_qty }}</td>
                        </tr>
                        <tr class="border-b border-gray-200 dark:border-gray-700">
                            <th class="text-left px-2 py-1 bg-gray-50 dark:bg-gray-800 text-gray-500 dark:text-gray-400 font-medium whitespace-nowrap">欠品数</th>
                            <td class="px-2 py-1 text-red-600 dark:text-red-400 font-bold bg-white dark:bg-gray-900">{{ $shortage_qty }}</td>
                            <th class="text-left px-2 py-1 bg-gray-50 dark:bg-gray-800 text-gray-500 dark:text-gray-400 font-medium whitespace-nowrap">横持出荷数</th>
                            <td class="px-2 py-1 text-blue-600 dark:text-blue-400 font-bold bg-white dark:bg-gray-900" x-text="allocatedQty"></td>
                            <th class="text-left px-2 py-1 bg-gray-50 dark:bg-gray-800 text-gray-500 dark:text-gray-400 font-medium whitespace-nowrap">残欠品数</th>
                            <td class="px-2 py-1 font-bold bg-white dark:bg-gray-900" :class="remainingQty > 0 ? 'text-red-600 dark:text-red-400' : 'text-gray-700 dark:text-gray-300'" x-text="remainingQty"></td>
                            <th class="text-left px-2 py-1 bg-gray-50 dark:bg-gray-800 text-gray-500 dark:text-gray-400 font-medium whitespace-nowrap">欠品内訳</th>
                            <td class="px-2 py-1 text-gray-700 dark:text-gray-300 bg-white dark:bg-gray-900">{{ $shortage_details }}</td>
                        </tr>
                    </table>
                </div>
                <!-- 同一配送コース上横持ち出荷予定倉庫 | コース内最短倉庫 -->
                <div x-show="hasDeliveryCourse" x-cloak class="mb-4 grid grid-cols-2 gap-4">
                    <!-- 左: 同一配送コース上横持ち出荷予定倉庫 -->
                    <div class="overflow-hidden rounded-lg border border-amber-300 dark:border-amber-600">
                        <div class="px-4 py-2 bg-amber-50 dark:bg-amber-900/30 border-b border-amber-300 dark:border-amber-600">
                            <span class="font-bold text-sm text-amber-700 dark:text-amber-300">同一配送コース上横持ち出荷予定倉庫</span>
                        </div>
                        <template x-if="sameCourseAllocations.length > 0">
                            <table class="w-full text-sm text-left rtl:text-right text-gray-500 dark:text-gray-400">
                                <thead class="text-xs text-gray-700 uppercase bg-amber-50/50 dark:bg-amber-900/20 dark:text-gray-400 border-b border-amber-200 dark:border-amber-700">
                                    <tr>
                                        <th class="px-4 py-2 border-r border-amber-200 dark:border-amber-700 last:border-r-0">倉庫名</th>
                                        <th class="px-4 py-2 text-center border-r border-amber-200 dark:border-amber-700 last:border-r-0">件数</th>
                                        <th class="px-4 py-2 text-center">合計数量</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-amber-200 dark:divide-amber-700">
                                    <template x-for="alloc in sameCourseAllocations" :key="alloc.warehouse_id">
                                        <tr
                                            class="bg-white border-b dark:bg-gray-800 dark:border-amber-700"
                                        >
                                            <td class="px-4 py-2 border-r border-amber-200 dark:border-amber-700 last:border-r-0 text-gray-900 dark:text-gray-100" x-text="alloc.warehouse_name"></td>
                                            <td class="px-4 py-2 text-center border-r border-amber-200 dark:border-amber-700 last:border-r-0 text-gray-900 dark:text-gray-100 font-medium" x-text="alloc.allocation_count + '件'"></td>
                                            <td class="px-4 py-2 text-center text-gray-900 dark:text-gray-100" x-text="alloc.total_qty"></td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </template>
                        <div x-show="sameCourseAllocations.length === 0" class="px-4 py-4 text-sm text-gray-500 dark:text-gray-400 text-center">
                            横持ち出荷予定なし
                        </div>
                    </div>

                    <!-- 右: コース内最短倉庫 -->
                    <div class="overflow-hidden rounded-lg border border-teal-300 dark:border-teal-600">
                        <div class="px-4 py-2 bg-teal-50 dark:bg-teal-900/30 border-b border-teal-300 dark:border-teal-600">
                            <span class="font-bold text-sm text-teal-700 dark:text-teal-300">コース内最短倉庫</span>
                        </div>
                        <template x-if="courseNearestWarehouses.length > 0">
                            <table class="w-full text-sm text-left rtl:text-right text-gray-500 dark:text-gray-400">
                                <thead class="text-xs text-gray-700 uppercase bg-teal-50/50 dark:bg-teal-900/20 dark:text-gray-400 border-b border-teal-200 dark:border-teal-700">
                                    <tr>
                                        <th class="px-4 py-2 border-r border-teal-200 dark:border-teal-700 last:border-r-0">倉庫名</th>
                                        <th class="px-4 py-2 text-center">距離</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-teal-200 dark:divide-teal-700">
                                    <template x-for="wh in courseNearestWarehouses" :key="wh.warehouse_id">
                                        <tr class="bg-white dark:bg-gray-800">
                                            <td class="px-4 py-2 border-r border-teal-200 dark:border-teal-700 last:border-r-0 text-gray-900 dark:text-gray-100" x-text="wh.warehouse_name"></td>
                                            <td class="px-4 py-2 text-center text-gray-900 dark:text-gray-100" x-text="wh.distance_km + 'km'"></td>
                                        </tr>
                                    </template>
                                </tbody>
                            </table>
                        </template>
                        <div x-show="courseNearestWarehouses.length === 0" class="px-4 py-4 text-sm text-center">
                            <span class="text-red-600 dark:text-red-400 font-medium">最寄倉庫算出失敗。</span>
                            <span class="text-gray-500 dark:text-gray-400">緯度経度情報を更新してください。</span>
                        </div>
                    </div>
                </div>

                <!-- 在庫リスト（2グループ横並び） -->
                <div class="mb-4 overflow-hidden rounded-lg border border-gray-300 dark:border-gray-600">
                    <table class="w-full table-fixed text-sm text-left rtl:text-right text-gray-500 dark:text-gray-400">
                        <thead class="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-gray-700 dark:text-gray-400 border-b border-gray-200 dark:border-gray-600">
                            <tr>
                                <th class="px-3 py-2 border-r border-gray-200 dark:border-gray-600">倉庫名</th>
                                <th class="px-3 py-2 text-center border-r border-gray-200 dark:border-gray-600 w-[12%]">ケース数</th>
                                <th class="px-3 py-2 text-center border-r-2 border-gray-300 dark:border-gray-500 w-[14%]">総バラ数</th>
                                <th class="px-3 py-2 border-r border-gray-200 dark:border-gray-600">倉庫名</th>
                                <th class="px-3 py-2 text-center border-r border-gray-200 dark:border-gray-600 w-[12%]">ケース数</th>
                                <th class="px-3 py-2 text-center w-[14%]">総バラ数</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            <template x-for="(row, idx) in stockRows" :key="idx">
                                <tr class="bg-white dark:bg-gray-800">
                                    <!-- 左グループ -->
                                    <td
                                        class="px-3 py-1.5 border-r border-gray-200 dark:border-gray-700 text-gray-900 dark:text-gray-100 cursor-pointer hover:bg-blue-50 dark:hover:bg-blue-900 transition-colors"
                                        @click="row.left && addAllocation(row.left.warehouse_id, remainingQty)"
                                    >
                                        <span x-text="row.left?.warehouse_name ?? ''"></span>
                                        <span
                                            x-show="row.left && row.left.warehouse_id == nearestWarehouseId"
                                            class="ml-1 inline-flex items-center px-1.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200"
                                        >おすすめ</span>
                                    </td>
                                    <td
                                        class="px-3 py-1.5 text-center border-r border-gray-200 dark:border-gray-700 text-gray-900 dark:text-gray-100 font-medium cursor-pointer hover:bg-blue-50 dark:hover:bg-blue-900 transition-colors"
                                        @click="row.left && addAllocation(row.left.warehouse_id, remainingQty)"
                                        x-text="row.left ? new Intl.NumberFormat('ja-JP').format(row.left.cases) + 'CS' : ''"
                                    ></td>
                                    <td
                                        class="px-3 py-1.5 text-center border-r-2 border-gray-300 dark:border-gray-500 text-gray-900 dark:text-gray-100 cursor-pointer hover:bg-blue-50 dark:hover:bg-blue-900 transition-colors"
                                        @click="row.left && addAllocation(row.left.warehouse_id, remainingQty)"
                                        x-text="row.left ? new Intl.NumberFormat('ja-JP').format(row.left.total_pieces) + 'バラ' : ''"
                                    ></td>
                                    <!-- 右グループ -->
                                    <td
                                        class="px-3 py-1.5 border-r border-gray-200 dark:border-gray-700 text-gray-900 dark:text-gray-100 cursor-pointer hover:bg-blue-50 dark:hover:bg-blue-900 transition-colors"
                                        :class="{ 'cursor-default': !row.right }"
                                        @click="row.right && addAllocation(row.right.warehouse_id, remainingQty)"
                                    >
                                        <span x-text="row.right?.warehouse_name ?? ''"></span>
                                        <span
                                            x-show="row.right && row.right.warehouse_id == nearestWarehouseId"
                                            class="ml-1 inline-flex items-center px-1.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200"
                                        >おすすめ</span>
                                    </td>
                                    <td
                                        class="px-3 py-1.5 text-center border-r border-gray-200 dark:border-gray-700 text-gray-900 dark:text-gray-100 font-medium cursor-pointer hover:bg-blue-50 dark:hover:bg-blue-900 transition-colors"
                                        :class="{ 'cursor-default': !row.right }"
                                        @click="row.right && addAllocation(row.right.warehouse_id, remainingQty)"
                                        x-text="row.right ? new Intl.NumberFormat('ja-JP').format(row.right.cases) + 'CS' : ''"
                                    ></td>
                                    <td
                                        class="px-3 py-1.5 text-center text-gray-900 dark:text-gray-100 cursor-pointer hover:bg-blue-50 dark:hover:bg-blue-900 transition-colors"
                                        :class="{ 'cursor-default': !row.right }"
                                        @click="row.right && addAllocation(row.right.warehouse_id, remainingQty)"
                                        x-text="row.right ? new Intl.NumberFormat('ja-JP').format(row.right.total_pieces) + 'バラ' : ''"
                                    ></td>
                                </tr>
                            </template>
                            <tr x-show="stocks.length === 0" class="bg-white dark:bg-gray-900">
                                <td colspan="6" class="px-4 py-4 text-center text-gray-500 dark:text-gray-400">
                                    在庫のある倉庫がありません
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- 横持ち出荷指示リスト -->
                <div class="space-y-3">
                    <div class="flex items-center justify-between">
                        <div class="font-bold text-gray-700 dark:text-gray-300">横持ち出荷指示</div>
                        <div class="flex items-center gap-4">
                            <div class="text-sm">
                                残欠品数: <span class="font-bold" :class="remainingQty > 0 ? 'text-red-600' : 'text-green-600'" x-text="remainingQty"></span>
                            </div>
                            <button
                                type="button"
                                @click="addManualAllocation()"
                                class="inline-flex items-center gap-1 px-3 py-1.5 text-sm font-medium text-white bg-primary-600 hover:bg-primary-500 rounded-lg shadow-sm transition-colors"
                            >
                                <x-heroicon-m-plus class="w-4 h-4" />
                                <span>倉庫を追加</span>
                            </button>
                        </div>
                    </div>

                    <div class="overflow-hidden rounded-lg border border-gray-300 dark:border-gray-600">
                        <table class="w-full text-sm text-left rtl:text-right text-gray-500 dark:text-gray-400">
                            <thead class="text-xs text-gray-700 uppercase bg-gray-50 dark:bg-gray-700 dark:text-gray-400 border-b border-gray-200 dark:border-gray-600">
                                <tr>
                                    <th class="px-4 py-3 border-r border-gray-200 dark:border-gray-600 last:border-r-0">横持ち出荷倉庫</th>
                                    <th class="px-4 py-3 border-r border-gray-200 dark:border-gray-600 last:border-r-0 w-40">数量</th>
                                    <th class="px-4 py-3 border-r border-gray-200 dark:border-gray-600 last:border-r-0 w-20">単位</th>
                                    <th class="px-4 py-3 text-center w-16">削除</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-200 dark:divide-gray-700 bg-white dark:bg-gray-900">
                                <template x-for="(allocation, index) in state" :key="index">
                                    <tr class="border-b dark:border-gray-700">
                                        <td class="px-4 py-2 border-r border-gray-200 dark:border-gray-700 last:border-r-0">
                                            <select
                                                x-model="allocation.from_warehouse_id"
                                                class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-base font-bold text-gray-900 dark:text-white py-1"
                                            >
                                                <option value="">倉庫を選択</option>
                                                @foreach($warehouses as $id => $name)
                                                    <option value="{{ $id }}">{{ $name }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td class="px-4 py-2 border-r border-gray-200 dark:border-gray-700 last:border-r-0">
                                            <input
                                                type="number"
                                                x-model="allocation.assign_qty"
                                                @input="validateQty(index)"
                                                min="0"
                                                class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 text-base font-bold text-gray-900 dark:text-white py-1"
                                            >
                                        </td>
                                        <td class="px-4 py-2 border-r border-gray-200 dark:border-gray-700 last:border-r-0 text-base font-bold text-gray-900 dark:text-white">
                                            {{ $qty_type_label }}
                                        </td>
                                        <td class="px-4 py-2 text-center">
                                            <button
                                                type="button"
                                                @click="removeAllocation(index)"
                                                class="text-red-500 hover:text-red-700 p-1"
                                            >
                                                <x-heroicon-o-trash class="w-5 h-5" />
                                            </button>
                                        </td>
                                    </tr>
                                </template>
                                <tr x-show="!state || state.length === 0">
                                    <td colspan="4" class="px-4 py-8 text-center text-gray-500 dark:text-gray-400 border-dashed">
                                        指示がありません。上の在庫リストから倉庫をクリックして追加してください。
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- 右カラム: 配送ルートマップ（フル高さ） -->
            <div class="col-span-5 flex flex-col">
                <div class="rounded-lg border border-gray-300 dark:border-gray-600 overflow-hidden flex flex-col flex-1">
                    <div class="px-3 py-2 bg-gray-50 dark:bg-gray-700 border-b border-gray-200 dark:border-gray-600 flex justify-between items-center shrink-0">
                        <span class="font-bold text-sm text-gray-700 dark:text-gray-300">配送ルートマップ</span>
                        <span x-show="totalDistance > 0" x-cloak class="text-sm text-gray-600 dark:text-gray-400">
                            総距離: <span class="font-bold text-blue-600" x-text="totalDistance.toFixed(1) + 'km'"></span>
                        </span>
                    </div>
                    <div x-ref="mapContainer" class="flex-1" style="min-height: 300px;"></div>
                    <!-- 凡例 -->
                    <div class="px-3 py-1.5 bg-gray-50 dark:bg-gray-700 border-t border-gray-200 dark:border-gray-600 flex flex-wrap gap-3 text-xs shrink-0">
                        <span class="flex items-center gap-1"><span class="inline-block w-3 h-3 rounded-full bg-black"></span>出発倉庫</span>
                        <span class="flex items-center gap-1"><span class="inline-block w-3 h-3 rounded-full bg-blue-500"></span>在庫あり倉庫</span>
                        <span class="flex items-center gap-1"><span class="inline-block w-3 h-3 rounded-full bg-red-500"></span>納品先</span>
                        <span x-show="totalDistance > 0" x-cloak class="flex items-center gap-1"><span class="inline-block w-3 h-3 rounded-full bg-amber-500"></span>選択倉庫</span>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-dynamic-component>
