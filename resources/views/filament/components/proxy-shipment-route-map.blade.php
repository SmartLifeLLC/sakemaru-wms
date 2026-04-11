<div
    x-data="{
        locations: {{ json_encode($locations ?? []) }},
        selectedWarehouseIds: {{ json_encode($selected_warehouse_ids ?? []) }},
        map: null,
        markers: {},
        routeLine: null,
        totalDistance: 0,

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

            this.map = L.map(this.$refs.mapContainer).setView([36.5, 136.5], 10);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap'
            }).addTo(this.map);

            const bounds = [];
            this.locations.forEach(loc => {
                let color, opacity;
                const isSelected = loc.type === 'warehouse' && this.selectedWarehouseIds.includes(loc.id);

                if (loc.type === 'departure') { color = '#000000'; opacity = 0.9; }
                else if (loc.type === 'customer') { color = '#ef4444'; opacity = 0.8; }
                else if (isSelected) { color = '#f59e0b'; opacity = 0.9; }
                else if (loc.stock_info) { color = '#3b82f6'; opacity = 0.8; }
                else { color = '#93c5fd'; opacity = 0.5; }

                const radius = isSelected ? 12 : 8;

                const marker = L.circleMarker([loc.lat, loc.lng], {
                    radius: radius, fillColor: color, color: '#fff', weight: 2, fillOpacity: opacity
                }).addTo(this.map);

                let popup = '<b>' + loc.name + '</b>';
                if (loc.type === 'warehouse' && isSelected) popup += '<br><span style=\'color:#f59e0b;font-weight:bold\'>横持ち出荷倉庫</span>';
                else if (loc.type === 'warehouse' && loc.stock_info) popup += '<br>在庫: ' + loc.stock_info;
                else if (loc.type === 'warehouse') popup += '<br><span style=\'color:#999\'>在庫なし</span>';
                if (loc.type === 'departure') popup += '<br><span style=\'color:#666\'>出発倉庫</span>';
                if (loc.type === 'customer') popup += '<br><span style=\'color:#ef4444\'>納品先</span>';
                marker.bindPopup(popup);

                this.markers[loc.type + '_' + loc.id] = marker;
                bounds.push([loc.lat, loc.lng]);
            });

            if (bounds.length > 0) {
                this.map.fitBounds(bounds, { padding: [30, 30], maxZoom: 10 });
            }

            this.drawRoute();
        },

        drawRoute() {
            if (!this.map) return;

            const departure = this.locations.find(l => l.type === 'departure');
            if (!departure) return;

            const selectedWarehouses = this.selectedWarehouseIds
                .map(id => this.locations.find(l => l.type === 'warehouse' && l.id === id))
                .filter(Boolean);

            if (selectedWarehouses.length === 0) return;

            const customers = this.locations.filter(l => l.type === 'customer');
            const points = [departure, ...selectedWarehouses, ...customers];
            const latlngs = points.map(p => [p.lat, p.lng]);

            this.routeLine = L.polyline(latlngs, { color: '#2563eb', weight: 3, opacity: 0.7, dashArray: '8, 4' }).addTo(this.map);

            for (let i = 0; i < points.length - 1; i++) {
                this.totalDistance += this.calcDistance(points[i].lat, points[i].lng, points[i+1].lat, points[i+1].lng);
            }
        }
    }"
    x-init="$nextTick(async () => { await initMap(); if (map) { setTimeout(() => map.invalidateSize(), 200); setTimeout(() => map.invalidateSize(), 600); } })"
>
    <div class="rounded-lg border border-gray-300 dark:border-gray-600 overflow-hidden flex flex-col" style="height: 400px;">
        <div class="px-3 py-2 bg-gray-50 dark:bg-gray-700 border-b border-gray-200 dark:border-gray-600 flex justify-between items-center shrink-0">
            <span class="font-bold text-sm text-gray-700 dark:text-gray-300">配送ルートマップ</span>
            <span x-show="totalDistance > 0" x-cloak class="text-sm text-gray-600 dark:text-gray-400">
                総距離: <span class="font-bold text-blue-600" x-text="totalDistance.toFixed(1) + 'km'"></span>
            </span>
        </div>
        <div x-ref="mapContainer" class="flex-1" style="min-height: 300px;"></div>
        <div class="px-3 py-1.5 bg-gray-50 dark:bg-gray-700 border-t border-gray-200 dark:border-gray-600 flex flex-wrap gap-3 text-xs shrink-0">
            <span class="flex items-center gap-1"><span class="inline-block w-3 h-3 rounded-full bg-black"></span>出発倉庫</span>
            <span class="flex items-center gap-1"><span class="inline-block w-3 h-3 rounded-full bg-amber-500"></span>横持ち出荷倉庫</span>
            <span class="flex items-center gap-1"><span class="inline-block w-3 h-3 rounded-full bg-blue-500"></span>在庫あり倉庫</span>
            <span class="flex items-center gap-1"><span class="inline-block w-3 h-3 rounded-full bg-red-500"></span>納品先</span>
        </div>
    </div>
</div>
