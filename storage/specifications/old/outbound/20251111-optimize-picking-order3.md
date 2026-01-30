経路選択のロジックの精度をよりあげたい。まず、floor-plan-editorでgridのサイズをdbに（wms_warehouse_layouts)保存できるようにする。倉庫とフロアを選択するとgrid sizeも呼び出せるように。
次に、ピッカーが移動 ができる領域をレイアウト上に塗れるようにする。経路検索時にはぴっかが移動できる場所にLocationのポジションを設定。あとは、移動ができる領域上で経路探索をおこなう。
設定している。grid sizeを活用できるように。
以下は実装の方針



やることは3つに分割する。

1)「歩ける領域」を塗って保存
2) すべてのロケの“訪問点”を歩行領域にスナップ（外れていれば補正）
3) 歩行領域上だけで最短経路探索（A*）

下に、DB拡張・UI実装・経路計算・サニタイズの順で落とす。

---

# 1. DB/スキーマ拡張（最小）

`wms_warehouse_layouts` に歩行領域を足す。ポリゴンで持つのが扱いやすい（穴も許容）。

```sql
alter table wms_warehouse_layouts
  add column walkable_areas json null comment '歩行可能領域（多角形配列。穴あり。単位はpx）',
  add column navmeta json null comment 'ナビ生成用メタ（cell_size, origin 等）';
```

フォーマット例：

```json
{
  "polygons": [
    { "outer": [[50,50],[1450,50],[1450,700],[50,700]],
      "holes":  [[[300,200],[500,200],[500,400],[300,400]]] }
  ],
  "cell_size": 25,     // px
  "origin": [0,0]      // 画像左上
}
```

※ `layout_hash` は (width,height,walls,fixed_areas,walkable_areas) を正規化して md5。距離キャッシュの無効化に使う。

---

# 2. UI：レイアウト上に「歩ける領域」を塗る

キャンバス編集でレイヤを1つ追加するだけ。

* 表示レイヤ：

    * 壁・固定物（既存JSON）
    * ロケーション（既存）
    * **Walkable layer（半透明グリーン）** ← 新規
* 操作モード：

    * ペイント/消しゴム（ブラシ）→ ビットマップとして内部保持
    * or ポリゴン描画（点追加→閉じる→穴の追加可）
* 保存時：

    * ブラシ派：ビットマップ→ポリゴン化（例：Marching Squares / Potrace）して `walkable_areas.polygons` に変換
    * ポリゴン派：そのまま保存

簡単な判定関数（Point-in-Polygon）は後続で使う（Winding/E/O どちらでもOK）。

---

# 3. サニタイズ（“歩ける”の定義を機械可にする）

現実的には**通路幅＜台車幅**は通れないとみなす。保存直後に**収縮（エロージョン）**をかける。

* 台車有効半径 `r`（px）を設定（例：15px）
* 歩行ポリゴンを**負のオフセット**（バッファ）で縮小 → 狭すぎる箇所は自然に消える

    * 実装：ラスタにしてモルフォロジー（erode），または幾何バッファ（CGAL/Clipper）

この「縮小後ポリゴン」を**ナビ用の歩行領域**とする（オリジナルは編集表示用に残す）。

---

# 4. ロケの訪問点を“必ず歩行領域上”に置く

ロケの front 点（棚前）を計算し、歩行領域内に入っているか確認。外れていたら**最短投影**で補正。

擬似コード：

```php
function frontPoint(array $loc): array {
    [$x1,$y1,$x2,$y2] = [$loc['x1_pos'],$loc['y1_pos'],$loc['x2_pos'],$loc['y2_pos']];
    $w = abs($x2-$x1); $h = abs($y2-$y1);
    $δ = 10;
    if ($w >= $h) return [intdiv($x1+$x2,2), min($y1,$y2) - $δ];
    else          return [min($x1,$x2) - $δ,  intdiv($y1+$y2,2)];
}

function snapToWalkable(array $p, Walkable $walk): array {
    if ($walk->contains($p)) return $p;
    // 1) 近傍格子へ丸め
    $q = $walk->nearestPointOnBoundary($p); // セグメント集合への最近点
    // 2) 1セルだけ内側に押し込む（法線方向に +ε）
    return $walk->nudgeInside($q, 2); // 2px
}
```

これで**全ロケの“訪問点”が必ず歩ける領域上**に落ちる。

---

# 5. 経路探索は“歩行領域のみ”を通す

MVPは**グリッドA***で十分（cell_size=25px 程度）。

## 5.1 ナビ格子の生成

* `cell_size` で画面を格子化
* 各セル中心が「縮小後歩行ポリゴン」に入っていれば**通行可セル**
* 4 or 8 近傍でエッジ生成（斜めは √2 コスト）

## 5.2 スタート/訪問点を格子へスナップ

* `start`: 出荷口を walkable にスナップ
* `loc`: 上の **snapToWalkable(front(loc))**

## 5.3 A*

* ノード=セル、障害物=非歩行セル
* ヒューリスティック=マンハッタン or ユークリッド
* 距離キャッシュは `layout_hash` をキーに使う（既出）

---

# 6. ルート最適化はそのまま（距離行列→2-opt）

順序決定は前回案と同じでいい。違いは**距離関数が“歩行領域A*距離”**になるだけ。

```php
$dist = function($a,$b) use($resolver,$warehouseId,$floorId,$layoutHash) {
    // resolver('LOC:123') -> [x,y]（既に walkable 内）
    // A* on walkable grid only
    return getDistance($a,$b,$resolver,$warehouseId,$floorId,$layoutHash)['dist'];
};
```

---

# 7. 例外処理（必須）

* **孤立領域**：歩行ポリゴンが分断 → A* 失敗。
  → スナップ時に「start と同一成分に属しているか」を Union-Find/BFS で検査。属していなければ「このロケは到達不能」と即エラー。
* **狭すぎる通路**：縮小後に通れない → 編集画面で“赤ハッチ”で可視化し、再塗りを促す。
* **ロケが歩行領域に埋まっている**（棚側に押し出し過ぎ）
  → snap で必ず歩行側に寄せる。埋まり続ける場合は通路の塗り不備。

---

# 8. 実装タスク（順でやれ）

1. **Migration**：`walkable_areas`, `navmeta` 追加
2. **Editor**：Walkable レイヤ（塗り or ポリゴン）。保存：`walkable_areas.polygons`
3. **Sanitize**：縮小（erode/バッファ）して「ナビ用歩行ポリゴン」を生成
4. **GridBuilder**：cell_size で通行可セル生成（成分ラベルもつける）
5. **Snap**：全ロケ訪問点を walkable 内に補正。到達不能チェック
6. **A***：歩行セルのみで経路取得 + 距離キャッシュ（`layout_hash`）
7. **順序最適化**：Nearest Insertion → 2-opt
8. **可視化**：キャンバスに経路 polyline を重ね描画（曲点はセル中心列）

---

# 9. コード断片（歩行判定/スナップ/A*の核）

```php
final class Walkable
{
    public function __construct(private array $polygons) {} // [{outer:[[x,y]..], holes:[[[x,y]..],..]}..]

    public function contains(array $p): bool {
        foreach ($this->polygons as $poly) {
            if (pointInPoly($p, $poly['outer'])) {
                foreach ($poly['holes'] ?? [] as $hole)
                    if (pointInPoly($p, $hole)) return false;
                return true;
            }
        }
        return false;
    }

    public function nearestPointOnBoundary(array $p): array {
        $best = null;
        foreach ($this->polygons as $poly) {
            foreach ([$poly['outer'], ...($poly['holes'] ?? [])] as $ring) {
                for ($i=0,$n=count($ring); $i<$n; $i++) {
                    $a=$ring[$i]; $b=$ring[($i+1)%$n];
                    $q = nearestPointOnSegment($p,$a,$b);
                    $d = sqdist($p,$q);
                    if ($best===null || $d<$best[0]) $best = [$d,$q,$a,$b];
                }
            }
        }
        return $best[1];
    }

    public function nudgeInside(array $q, int $eps=2): array {
        // 近傍方向を内側にとる簡易版：境界法線を外積から推定、内側へ eps シフト
        return [$q[0]+($eps), $q[1]+($eps)]; // 実装は境界向きに応じて調整
    }
}
```

A* は既存のグリッド実装でOK。セル通行可否は `Walkable::contains(center_of_cell)`。

---

# 10. まとめ（設計原則）

* 真理値は「歩けるか否か」を**編集画面で明示**してDB保存。
* 経路探索は**歩ける領域のみ**をグラフにする（壁・固定物は関知しない＝“もう塗られていない”から）。
* 訪問点は**必ず**歩行領域へスナップ。
* 通路幅の物理制約は**縮小（erode）**で吸収。
* レイアウト変更＝`layout_hash` 変更でキャッシュ無効化。

この方針で実装すれば、「塗った通路しか通らない」動線最適化が素直に動く。
必要なら、編集UIの保存JSON例と、Laravelの GridBuilder/A* サービスを丸ごと出す。
