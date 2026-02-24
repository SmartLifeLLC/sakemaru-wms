# 配送ルート可視化マップ 作業計画

## 前提

- 欠品対応-横持ち出荷指示モーダルは既に実装済み
- モーダル内に商品情報、在庫リスト、コース内情報、横持ち出荷指示リストが存在
- Warehouse/Partner モデルに `latitude`, `longitude` カラムが存在しデータも充足
- Leaflet.js はプロジェクト未導入（CDN経由で追加）
- 参考: `storage/specifications/stock-transfer-map-view/ref-image.png`

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P0 | バックエンド | viewDataに位置情報（出発倉庫・候補倉庫・納品先）を追加 | viewDataに`locations`配列が含まれること |
| P1 | フロントエンド | Leafletマップ＆ルート描画をBladeに実装 | マーカー表示・倉庫選択連動・ルート線描画・距離表示が動作 |
| P2 | レイアウト調整 | モーダル幅拡大、左右カラムレイアウト | 参考画像通りの2カラム配置 |
| P3 | 動作確認 | 構文チェック・フォーマット・既存機能の非破壊確認 | php -l OK, Pint OK, 既存テスト影響なし |

---

## P0: バックエンド（viewData に位置情報追加）

### 目的

マップに表示するための位置情報データをPHP側からAlpine.jsに渡す。

### 修正方針

WmsShortagesTable.php と WmsShortagesWaitingApprovalsTable.php の viewData に `locations` 配列を追加する。

#### locations 配列の構造

```php
$locations = [];

// 1. 出発倉庫（departure）: 欠品レコードの warehouse
$locations[] = [
    'id' => $record->warehouse_id,
    'name' => $record->warehouse->name,
    'lat' => (float) $record->warehouse->latitude,
    'lng' => (float) $record->warehouse->longitude,
    'type' => 'departure',
];

// 2. 候補倉庫（warehouse）: 在庫リストの各倉庫（出発倉庫を除く）
foreach ($stockData as $stock) {
    if ($stock['warehouse_id'] != $record->warehouse_id) {
        $wh = Warehouse::find($stock['warehouse_id']);
        if ($wh && $wh->latitude && $wh->longitude) {
            $locations[] = [
                'id' => $wh->id,
                'name' => $wh->name,
                'lat' => (float) $wh->latitude,
                'lng' => (float) $wh->longitude,
                'type' => 'warehouse',
                'stock_info' => $stock['cases'] . 'CS / ' . $stock['total_pieces'] . 'バラ',
            ];
        }
    }
}

// 3. 納品先（customer）: 同一配送コース内の得意先
if ($record->wave_id && $record->delivery_course_id) {
    // 同一wave+配送コースの欠品から得意先を取得
    $courseShortages = WmsShortage::where('wave_id', $record->wave_id)
        ->where('delivery_course_id', $record->delivery_course_id)
        ->with('trade.partner')
        ->get();

    $addedPartnerIds = [];
    foreach ($courseShortages as $s) {
        $partner = $s->trade?->partner;
        if ($partner && $partner->latitude && $partner->longitude && !in_array($partner->id, $addedPartnerIds)) {
            $locations[] = [
                'id' => $partner->id,
                'name' => $partner->name,
                'lat' => (float) $partner->latitude,
                'lng' => (float) $partner->longitude,
                'type' => 'customer',
            ];
            $addedPartnerIds[] = $partner->id;
        }
    }
}
```

viewData の return に追加:
```php
'locations' => $locations,
```

### 修正対象ファイル

- `app/Filament/Resources/WmsShortages/Tables/WmsShortagesTable.php`
- `app/Filament/Resources/WmsShortagesWaitingApprovals/Tables/WmsShortagesWaitingApprovalsTable.php`

### 完了条件

- viewData に `locations` 配列が含まれる
- departure, warehouse, customer の3種類のマーカーデータが含まれる
- `php -l` で構文エラーなし

---

## P1: フロントエンド（Leafletマップ＆ルート描画）

### 目的

Bladeテンプレートに Leaflet.js マップを埋め込み、マーカー表示・倉庫選択連動・ルート描画・距離表示を実装する。

### 修正方針

`proxy-shipment-allocations.blade.php` に以下を追加:

#### 1. Leaflet CDN 読み込み（`<style>`の前に追加）

```html
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
```

#### 2. Alpine.js x-data に追加するプロパティ・メソッド

```javascript
locations: {{ json_encode($locations ?? []) }},
map: null,
markers: {},
routeLine: null,
totalDistance: 0,

// Haversine距離計算（km）
calcDistance(lat1, lng1, lat2, lng2) {
    const R = 6371;
    const dLat = (lat2 - lat1) * Math.PI / 180;
    const dLng = (lng2 - lng1) * Math.PI / 180;
    const a = Math.sin(dLat/2)**2 + Math.cos(lat1*Math.PI/180) * Math.cos(lat2*Math.PI/180) * Math.sin(dLng/2)**2;
    return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1-a));
},

// マップ初期化
initMap() {
    if (!this.locations.length) return;

    this.map = L.map(this.$refs.mapContainer).setView([36.5, 136.5], 10);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
        attribution: '© OpenStreetMap'
    }).addTo(this.map);

    // マーカー追加
    const bounds = [];
    this.locations.forEach(loc => {
        const color = loc.type === 'departure' ? 'black' : loc.type === 'warehouse' ? 'blue' : 'red';
        const marker = L.circleMarker([loc.lat, loc.lng], {
            radius: 8, fillColor: color, color: '#fff', weight: 2, fillOpacity: 0.8
        }).addTo(this.map);

        let popup = `<b>${loc.name}</b>`;
        if (loc.stock_info) popup += `<br>在庫: ${loc.stock_info}`;
        marker.bindPopup(popup);

        this.markers[loc.type + '_' + loc.id] = marker;
        bounds.push([loc.lat, loc.lng]);
    });

    if (bounds.length > 0) {
        this.map.fitBounds(bounds, { padding: [30, 30] });
    }
},

// ルート更新（倉庫選択時）
updateRoute() {
    if (this.routeLine) {
        this.map.removeLayer(this.routeLine);
        this.routeLine = null;
    }
    this.totalDistance = 0;

    if (!this.state || this.state.length === 0) return;

    const departure = this.locations.find(l => l.type === 'departure');
    if (!departure) return;

    // 選択された倉庫
    const selectedIds = this.state.map(a => parseInt(a.from_warehouse_id)).filter(Boolean);
    const selectedWarehouses = selectedIds.map(id => this.locations.find(l => l.type === 'warehouse' && l.id === id)).filter(Boolean);

    if (selectedWarehouses.length === 0) return;

    // 納品先
    const customers = this.locations.filter(l => l.type === 'customer');

    // ルート: 出発倉庫 → 選択倉庫 → 納品先
    const points = [departure, ...selectedWarehouses, ...customers];
    const latlngs = points.map(p => [p.lat, p.lng]);

    this.routeLine = L.polyline(latlngs, { color: '#2563eb', weight: 3, opacity: 0.7 }).addTo(this.map);

    // 距離計算
    for (let i = 0; i < points.length - 1; i++) {
        this.totalDistance += this.calcDistance(points[i].lat, points[i].lng, points[i+1].lat, points[i+1].lng);
    }

    // 選択倉庫マーカーを強調
    Object.entries(this.markers).forEach(([key, marker]) => {
        if (key.startsWith('warehouse_')) {
            const id = parseInt(key.split('_')[1]);
            marker.setStyle({
                radius: selectedIds.includes(id) ? 12 : 8,
                fillColor: selectedIds.includes(id) ? '#f59e0b' : 'blue',
            });
        }
    });
},
```

#### 3. マップHTML（在庫リストの上または右に配置）

```html
<div class="mb-4 rounded-lg border border-gray-300 dark:border-gray-600 overflow-hidden">
    <div class="px-3 py-2 bg-gray-50 dark:bg-gray-700 border-b border-gray-200 dark:border-gray-600 flex justify-between items-center">
        <span class="font-bold text-sm text-gray-700 dark:text-gray-300">配送ルートマップ</span>
        <span x-show="totalDistance > 0" class="text-sm text-gray-600 dark:text-gray-400">
            総距離: <span class="font-bold text-blue-600" x-text="totalDistance.toFixed(1) + 'km'"></span>
        </span>
    </div>
    <div x-ref="mapContainer" style="height: 300px; width: 100%;"></div>
    <!-- 凡例 -->
    <div class="px-3 py-1.5 bg-gray-50 dark:bg-gray-700 border-t border-gray-200 dark:border-gray-600 flex gap-4 text-xs">
        <span class="flex items-center gap-1"><span class="inline-block w-3 h-3 rounded-full bg-black"></span>出発倉庫</span>
        <span class="flex items-center gap-1"><span class="inline-block w-3 h-3 rounded-full bg-blue-500"></span>候補倉庫</span>
        <span class="flex items-center gap-1"><span class="inline-block w-3 h-3 rounded-full bg-red-500"></span>納品先</span>
    </div>
</div>
```

#### 4. watch / init

- `x-init="$nextTick(() => initMap())"` でマップ初期化
- `$watch('state', () => updateRoute(), { deep: true })` で倉庫選択連動

### 修正対象ファイル

- `resources/views/filament/forms/components/proxy-shipment-allocations.blade.php`

### 完了条件

- Leaflet マップが表示される
- 出発倉庫（黒）、候補倉庫（青）、納品先（赤）のマーカーが表示される
- 横持ち出荷指示で倉庫を選択するとルート線が描画される
- 選択倉庫のマーカーが強調される（黄色・大きく）
- 総移動距離がkm単位で表示される
- 凡例が表示される

---

## P2: モーダル幅拡大＆レイアウト調整

### 目的

参考画像のように、モーダルを幅広にして左カラム（フォーム）と右カラム（マップ）の2カラムレイアウトにする。

### 修正方針

#### PHP側

```php
->modalWidth('7xl') // または 'screen'
```

を WmsShortagesTable.php と WmsShortagesWaitingApprovalsTable.php の createProxyShipment / editProxyShipment アクションに追加。

#### Blade側

モーダル内を `grid grid-cols-5 gap-4` の2カラムに:
- 左カラム（`col-span-3`）: 商品情報テーブル＋在庫リスト＋横持ち出荷指示
- 右カラム（`col-span-2`）: 配送ルートマップ（高さいっぱいに）

### 修正対象ファイル

- `app/Filament/Resources/WmsShortages/Tables/WmsShortagesTable.php`
- `app/Filament/Resources/WmsShortagesWaitingApprovals/Tables/WmsShortagesWaitingApprovalsTable.php`
- `resources/views/filament/forms/components/proxy-shipment-allocations.blade.php`

### 完了条件

- モーダルが画面幅の大部分を使うサイズに拡大
- 左にフォーム、右にマップの2カラムレイアウト
- マップの高さがフォーム領域と揃っている

---

## P3: 動作確認・テスト

### 目的

全変更のクオリティチェック。

### 確認手順

1. `php -l` で全変更ファイルの構文チェック
2. `./vendor/bin/pint` でコードフォーマット
3. 既存機能の非破壊確認（横持ち出荷指示の追加・削除・保存が正常に動作するか）
4. 位置情報がない倉庫・得意先がある場合のフォールバック確認

### 完了条件

- 全ファイル構文エラーなし
- Pint フォーマット適用済み
- 既存テストに影響なし

---

## 制約（厳守）

1. `migrate:fresh`, `migrate:refresh`, `migrate:reset`, `db:wipe` 絶対禁止
2. 外部キー（FK）の作成禁止
3. Leaflet は CDN 経由のみ（npm install 不可）
4. 既存の横持ち出荷指示機能（追加・編集・削除・保存）を壊さない
5. 位置情報が null の場合はマーカーをスキップする（エラーにしない）

## 全体完了条件

1. マップに出発倉庫・候補倉庫・納品先が正しくマーカー表示される
2. 横持ち倉庫選択時にルート線が描画・更新される
3. 総移動距離がリアルタイムで更新される
4. 既存の横持ち出荷指示機能が正常に動作する
5. 全ファイル構文OK、Pintフォーマット済み
