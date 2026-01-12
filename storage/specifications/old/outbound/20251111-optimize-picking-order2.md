- ピッキング動線アルゴリズムの実装

壁・固定物（`wms_warehouse_layouts.walls/fixed_areas` のJSON）を通れない領域として扱い、`locations` の矩形の**前面(front)点**を訪問点にし、A*で距離→2-optで順序改善、という流れ。

---

# 0) いまのテーブルに最小追加

## A. 距離キャッシュ & ナビグラフ

```sql
-- ナビ用ノード（グリッド or 中心線の交点）
create table wms_picking_nav_nodes (
  id bigint unsigned primary key auto_increment,
  warehouse_id bigint unsigned not null,
  floor_id bigint unsigned null,
  x int not null,
  y int not null,
  kind enum('GRID','PORTAL','START') not null default 'GRID',
  unique key uk_navnode_xy (warehouse_id, floor_id, x, y)
) collate = utf8mb4_unicode_ci;

-- ノード間の通行可能エッジ
create table wms_picking_nav_edges (
  id bigint unsigned primary key auto_increment,
  warehouse_id bigint unsigned not null,
  floor_id bigint unsigned null,
  node_u bigint unsigned not null,
  node_v bigint unsigned not null,
  length int not null,
  is_blocked tinyint(1) not null default 0,
  key uk_edge (warehouse_id, floor_id, node_u, node_v),
  index idx_edge_uv (node_u, node_v)
) collate = utf8mb4_unicode_ci;

-- 距離キャッシュ
create table wms_layout_distance_cache (
  id bigint unsigned primary key auto_increment,
  warehouse_id bigint unsigned not null,
  floor_id bigint unsigned null,
  layout_hash char(32) not null, -- walls/fixed_areas/width/height からMD5
  from_key varchar(64) not null, -- 'LOC:{id}' or 'NODE:{id}'
  to_key   varchar(64) not null,
  meters int not null,
  path_json json null,
  unique key uk_dist (warehouse_id, floor_id, layout_hash, from_key, to_key)
) collate = utf8mb4_unicode_ci;
```

> 補足：`wms_warehouse_layouts` に `walls/fixed_areas` があるので**別テーブル不要**。レイアウト更新時は `layout_hash` が変わる→距離キャッシュは自動で無効化。

## B. locations に front 点（アプリ計算でOK）

DB追加は必須ではない。まずは**アプリ側で front_x/front_y を関数で算出**。将来固定したくなったら列追加すればいい。

---

# 1) アルゴリズム概要（実運用フロー）

1. **訪問点の抽出**
   ピック対象の `location_id` 集合を作る（ピックリスト確定済みならそれを使用）。
2. **front点にスナップ**
   各 location の front 点（後述）を訪問点とする。
3. **ナビグラフ生成**（初回 or レイアウト変更時のみ）

    * レイアウトJSON（壁/固定物）を**ラスタライズ**し、`cell_size`（例：25px）でグリッド化。
    * 通れるセル中心を `wms_picking_nav_nodes(kind='GRID')` に、4/8近傍で `wms_picking_nav_edges` を貼る（壁・固定に当たるセルは除外）。
    * スタート地点（出荷口）を `kind='START'` で追加。
4. **front点→最寄りナビノード**を**遮蔽判定付き**で接続（可視線が壁と交差しなければ直結、ダメなら最寄り通れるセルへ最短）

    * これを `kind='PORTAL'` ノードとして `wms_picking_nav_nodes` に追加し、1〜2本の短いエッジでグラフへ接続。
5. **距離行列**を A* で引き、`wms_layout_distance_cache` に保存。
6. **順序最適化**
   最近傍 or Nearest Insertion で初期順 → **2-opt**で改良。
   同一通路（同一直線に並ぶ front 点）は**S字**整列で先に並べ、2-optに壊され過ぎないようペナルティを入れるのが現場的。

---

# 2) front 点の決め方（MVP）

* まずは**矩形の通路側中心**が分からないので、簡易に「長辺の外側中心」を front とする（棚前にいる想定）。
* 実装：`width = |x2-x1|`, `height = |y2-y1|`。

    * `width >= height` → 長辺が横→**上側( y=min )**をfront： `( (x1+x2)/2, min(y1,y2) )` を少し通路側へ `-δ` シフト
    * それ以外 → **左側( x=min )**をfront： `( min(x1,x2), (y1+y2)/2 )` を `-δ` シフト
* 後で aisle 情報を持てたら、**通路側に正しくスナップ**する。

---

# 3) ぶっちゃけ必要なコード（Laravelスケッチ）

## A*（グリッド）実装の芯

```php
final class AStarGrid
{
    public function __construct(
        private int $cellSize, // 25px 等
        private array $blockedRects // [[x1,y1,x2,y2], ...] from walls+fixed_areas(+棚)
    ) {}

    public function shortest(array $start, array $goal): array {
        // start/goal はピクセル座標 [x,y]。内部でセル座標に変換→A*。
        // 戻り: ['dist'=>int, 'path'=>[[x,y]...]] （px単位）
    }

    public function isBlockedCell(int $cx, int $cy): bool {
        // セル矩形が blockedRects と交差するなら true
    }
}
```

## front 点計算

```php
function computeFrontPoint(array $loc): array {
    [$x1,$y1,$x2,$y2] = [$loc['x1_pos'],$loc['y1_pos'],$loc['x2_pos'],$loc['y2_pos']];
    $w = abs($x2-$x1); $h = abs($y2-$y1);
    $delta = 10; // px, 通路側に少し出す
    if ($w >= $h) {
        $x = intdiv($x1+$x2,2);
        $y = min($y1,$y2) - $delta;
    } else {
        $x = min($x1,$x2) - $delta;
        $y = intdiv($y1+$y2,2);
    }
    return [$x,$y];
}
```

## 距離キャッシュ I/F

```php
function layoutHash(array $layout): string {
    // width,height,walls,fixed_areas を正規化JSON→md5
}
function getDistance(string $fromKey, string $toKey, callable $resolver, int $warehouseId, ?int $floorId, string $layoutHash): array {
    $hit = WmsDistanceCache::where(compact('warehouseId','floorId','layoutHash','fromKey','toKey'))->first();
    if ($hit) return ['dist'=>$hit->meters, 'path'=>json_decode($hit->path_json,true)];
    // resolverは ['point'=>[x,y]] or ['nodeId'=>...] を返すクロージャ
    [$fromPoint, $toPoint] = [$resolver($fromKey), $resolver($toKey)];
    $res = app(AStarGrid::class)->shortest($fromPoint, $toPoint);
    WmsDistanceCache::create([
        'warehouse_id'=>$warehouseId,'floor_id'=>$floorId,'layout_hash'=>$layoutHash,
        'from_key'=>$fromKey,'to_key'=>$toKey,'meters'=>$res['dist'],'path_json'=>json_encode($res['path']),
    ]);
    return $res;
}
```

## 初期順序＋2-opt

```php
function nearestInsertion(array $keys, callable $dist): array {
    $route = [$keys[0]];
    $unused = array_slice($keys,1);
    while ($unused) {
        // 最も近い点を、総距離増分が最小になる隙間に挿入
        $best = null;
        foreach ($unused as $k) {
            $bestGap = null;
            for ($i=0; $i<count($route); $i++) {
                $a = $route[$i]; $b = $route[($i+1)%count($route)];
                $delta = $dist($a,$k)+$dist($k,$b)-$dist($a,$b);
                if ($bestGap === null || $delta < $bestGap[0]) $bestGap = [$delta,$i];
            }
            if ($best === null || $bestGap[0] < $best[0]) $best = [$bestGap[0], $k, $bestGap[1]];
        }
        array_splice($route, $best[2]+1, 0, [$best[1]]);
        $unused = array_values(array_diff($unused, [$best[1]]));
    }
    return $route;
}

function twoOpt(array $seq, callable $dist): array {
    $n = count($seq); $improved=true;
    while ($improved) {
        $improved=false;
        for ($i=1; $i<$n-2; $i++) for ($k=$i+1; $k<$n-1; $k++) {
            $a=$seq[$i-1]; $b=$seq[$i]; $c=$seq[$k]; $d=$seq[$k+1];
            $delta = -$dist($a,$b)-$dist($c,$d)+$dist($a,$c)+$dist($b,$d);
            if ($delta < -1) { // 1px以上短縮のみ
                $seq = array_merge(array_slice($seq,0,$i), array_reverse(array_slice($seq,$i,$k-$i+1)), array_slice($seq,$k+1));
                $improved=true;
            }
        }
    }
    return $seq;
}
```

## ルート生成サービス（骨子）

```php
final class PickRouteService
{
    public function buildRoute(int $warehouseId, ?int $floorId, array $locationIds, array $layoutJson, array $startPointPx): array
    {
        $layoutHash = layoutHash($layoutJson);
        // 1) resolver: LOC:{id} or NODE:START を座標に解決
        $resolver = function(string $key) use ($locationIds, $startPointPx) {
            if ($key === 'NODE:START') return $startPointPx;
            if (str_starts_with($key, 'LOC:')) {
                $locId = (int) substr($key, 4);
                $loc = Location::findOrFail($locId);
                return computeFrontPoint($loc->toArray());
            }
            throw new \RuntimeException("Unknown key $key");
        };

        // 2) キー列
        $keys = array_map(fn($id)=>"LOC:$id", $locationIds);
        array_unshift($keys, 'NODE:START');

        // 3) 距離関数（キャッシュ内包）
        $dist = function(string $a, string $b) use ($warehouseId, $floorId, $layoutHash, $resolver) {
            return getDistance($a,$b,$resolver,$warehouseId,$floorId,$layoutHash)['dist'];
        };

        // 4) 初期ルート→2-opt
        $route = nearestInsertion($keys, $dist);
        $route = twoOpt($route, $dist);

        // 5) 出力（STARTを除く訪問順）
        return array_values(array_filter($route, fn($k)=>$k!=='NODE:START'));
    }
}
```

---

# 4) 実データの扱い

* レイアウト行（あなたの例）
  `width=1500,height=750`、`colors/text_styles` は描画専用。**経路計算では** `walls` と `fixed_areas` のみを使う。
  いずれ `walls/fixed_areas` を `[{x1,y1,x2,y2}, ...]` に統一しておくと、そのままラスタ衝突判定に使える。

* `real_stocks` → ピックリストに使うロケ抽出
  例：アイテム集合→在庫>0 の `location_id` を引く。**ロケが複数ある場合**は「主保管優先 + 近い順」で割当（ここは別途ロジック）。

---

# 5) インデックス推奨

```sql
alter table locations
  add index idx_locations_wh_floor (warehouse_id, floor_id),
  add index idx_locations_codes (warehouse_id, floor_id, code1(50), code2(50), code3(50));

alter table real_stocks
  add index idx_real_stocks_loc (warehouse_id, floor_id, location_id),
  add index idx_real_stocks_item (warehouse_id, item_id);
```

> 備考：`real_stocks` の unique が2本とも同じ列構成。どちらか片方で十分。

---

# 6) まず動かす手順（MVP）

1. **JSON→障害物配列**の正規化（`[{x1,y1,x2,y2}, ...]`）。
2. **グリッドA***（25px or 50px）を用意し、**walls/fixed_areas（＋棚本体=locations矩形）**をブロック扱い。

    * 棚を完全ブロックにする代わりに、**front点から1セルだけ空けて**通路側に接続する。
3. **START座標**を決める（出荷口/梱包台の中央）。
4. ピック対象の `location_id` を列挙→上記サービスに渡す。
5. 返ってきた `["LOC:123","LOC:456",...]` をそのまま訪問順に採用。

    * ハンディ表示用に `wms_layout_distance_cache.path_json` を辿って曲がり角ガイドも出せる。

---

# 7) 次段階（性能・精度アップ）

* **中心線モデル**（通路のスケルトン）に置換 → ノード数激減、A*がさらに速い。
* **S字整列**（同一通路内で昇順/降順交互）を先に適用→2-optの探索空間を縮小。
* **ペナルティ**（Uターン・狭路・斜行）をエッジコストに付与。
* **ゾーンTSP→ゾーン内2-opt** で 300ストップ級でも即応。

---

必要なら、このまま**Laravelの実装ファイル**（Service + コマンド + 依存モデル）を丸ごと出す。`walls/fixed_areas` のJSON実例（配列形）を1つ貼ってくれれば、A*の衝突判定まで具体コードに落とす。
