# 出荷(出庫)機能 詳細仕様

> Android P20/P21/P22 → Web SPA `/handy/outgoing`

---

## 1. 現在のWeb実装の問題点

現在の `outgoing-app.js`（約410行）は Android の半分以下の機能:

| 機能 | Android | 現Web | 対応要否 |
|------|---------|-------|---------|
| タスク一覧（2カラムグリッド） | ✅ | ✅（リスト形式） | **要改善** |
| タスク開始 (POST /start) | ✅ | ✅ | OK |
| ステータスベースナビゲーション | ✅ | ❌（常にpicking） | **要追加** |
| 商品画像表示 | ✅ | ✅（1枚） | **要改善（複数対応）** |
| 得意先名表示 | ✅ | ❌ | **要追加** |
| ロケーション表示 | ✅ | ❌ | **要追加** |
| 伝票番号表示 | ✅ | ❌ | **要追加** |
| ケース/バラ 分離入力 | ✅ | ❌（1つの入力のみ） | **要追加** |
| 出庫履歴画面 (P22) | ✅ | ❌ | **要追加** |
| 履歴からのキャンセル | ✅ | ❌ | **要追加** |
| 確定処理（確認ダイアログ） | ✅ | ✅（簡易） | **要改善** |
| 進捗表示（N/M） | ✅ | ✅ | OK |
| Pull-to-refresh | ✅ | ✅ | OK |
| タスク完了ステータス色分け | ✅ | ❌ | **要追加** |

---

## 2. P20: コース選択画面 (task-list)

### 概要
ピッキングタスク（配送コース単位）の一覧を表示。

### Android UIの再現ポイント

#### タスクカード（2カラムグリッド）
- 2列のカードグリッドレイアウト
- 各カードに表示:
  - 🚚 アイコン + コース名
  - ステータスバッジ（完了/作業中）
  - エリア名
  - 出荷指示数 / 検品済数

#### ステータスによるカード色分け
```
未着手（PENDING全件）:
  背景: #FFFDE7 (amber-50相当)
  ボーダー: #F9A825 (amber)
  タイトル: #E67E22 (orange)

作業中（PICKING/COMPLETED/SHORTAGEあり）:
  背景: #E8F5E9 (green-50相当)
  ボーダー: #4CAF50 (green)
  タイトル: #2E7D32 (green-dark)

完了（全件COMPLETED/SHORTAGE）:
  背景: #F5F5F5 (gray)
  ボーダー: #BDBDBD (gray)
  タイトル: #757575 (gray)
  バッジ: "完了"
```

#### タスク選択時のナビゲーション
```javascript
async selectTask(task) {
    // 1. タスク開始API呼び出し
    await api.post(`/picking/tasks/${task.wave.wms_picking_task_id}/start`);

    // 2. ステータスベースのナビゲーション
    const pendingItems = task.picking_list.filter(i => i.status === 'PENDING');
    const pickingItems = task.picking_list.filter(i => i.status === 'PICKING');

    if (pendingItems.length > 0) {
        // PENDINGあり → データ入力画面
        this.currentScreen = 'picking';
    } else if (pickingItems.length > 0) {
        // PENDINGなし、PIKINGあり → 履歴画面（編集可能）
        this.currentScreen = 'history';
    } else {
        // 全完了 → 履歴画面（読み取り専用）
        this.currentScreen = 'history';
    }
}
```

#### APIコール
```javascript
GET /api/picking/tasks?warehouse_id={warehouseId}&picker_id={pickerId}
```

---

## 3. P21: データ入力画面 (picking)

### 概要
PENDINGアイテムを1件ずつ表示し、数量を入力して登録する。

### Android UIの再現ポイント

#### ヘッダー情報
- 「出庫」タイトル + コース名バッジ（緑） + 進捗バッジ（N/M、オレンジ） + 倉庫名
- 戻るボタン + ホームボタン

#### 商品情報（左ペイン）
- **商品名**（太字、最大2行）
- **JAN / 容量 / 入数** の1行サマリー
- **得意先名**
- **ロケーション**（ピッキングエリア名）
- **伝票番号**
- **画像ボタン**（タップで画像ダイアログ表示）

#### 数量入力（右ペイン）
- **ケース入力**（`planned_qty_type === 'CASE'` の場合アクティブ）
  - ラベル: `ケース（受注数：{planned_qty}）`
- **バラ入力**（`planned_qty_type === 'PIECE'` の場合アクティブ）
  - ラベル: `バラ（受注数：{planned_qty}）`
- **デフォルト値**: `planned_qty`（受注数をプリフィル）
- **登録ボタン**（amber背景）
- **履歴ボタン**（amber枠線）

#### 登録処理フロー
```javascript
async registerCurrentItem() {
    const currentItem = this.currentItem;
    const pickedQty = this.pickedQty;

    // 1. 数量更新API
    await api.post(`/picking/tasks/${currentItem.wms_picking_item_result_id}/update`, {
        picked_qty: pickedQty,
        picked_qty_type: currentItem.planned_qty_type
    });

    // 2. タスクをサーバーからリフレッシュ（カウンター更新のため）
    const response = await api.get(`/picking/tasks?warehouse_id=${this.selectedWarehouse.id}&picker_id=${this.picker.id}`);
    const refreshedTask = response.result.data.find(t =>
        t.wave.wms_picking_task_id === this.currentTask.wave.wms_picking_task_id
    );

    if (refreshedTask) {
        this.currentTask = refreshedTask;
    }

    // 3. 次のPENDINGアイテムへ移動
    const pendingItems = this.currentTask.picking_list.filter(i => i.status === 'PENDING');
    if (pendingItems.length > 0) {
        // 次のPENDINGアイテムを表示
        this.currentItemIndex = this.currentTask.picking_list.indexOf(pendingItems[0]);
        this.pickedQty = Number(pendingItems[0].planned_qty);
    } else {
        // 全PENDING完了 → complete画面
        this.currentScreen = 'complete';
    }
}
```

#### 画像ダイアログ
- 複数画像対応（ページャー/スワイプ）
- 画像がない場合は「画像が登録されていません」メッセージ
- ページインジケーター（ドット）

---

## 4. P22: 出庫履歴画面 (history)

### 概要
登録済み（PICKING/COMPLETED/SHORTAGE）アイテムの一覧表示。削除（キャンセル）と確定が可能。

### Android UIの再現ポイント

#### 履歴リスト
- 各アイテムに表示:
  - 商品名
  - JAN / 容量
  - 伝票番号
  - 登録数量 / 予定数量
  - ステータスバッジ
    - PICKING: 青
    - COMPLETED: 緑
    - SHORTAGE: 赤
- **削除ボタン**（PIKINGステータスのみ）

#### 削除（キャンセル）処理
```javascript
async cancelItem(item) {
    if (!confirm('この商品の登録を取り消しますか？')) return;

    await api.post(`/picking/tasks/${item.wms_picking_item_result_id}/cancel`);
    // PENDINGに戻る → リフレッシュ
    await this.refreshCurrentTask();
}
```

#### 確定ボタン
- 全アイテムが登録済みの場合に表示
- `POST /api/picking/tasks/{id}/complete` を呼び出し
- 完了後、タスク一覧に戻る

#### キャンセルボタン
- 確定せずデータ入力画面に戻る

---

## 5. complete: 完了確認画面

### 概要
全PENDINGアイテムの登録が完了した時に表示。

### UI要素
- ✅ チェックアイコン
- 「すべての商品が登録されました。」メッセージ
- 「確定を押下してください。」サブメッセージ
- **確定ボタン**: `POST /api/picking/tasks/{id}/complete`
- **キャンセルボタン**: 履歴画面へ（アイテム編集用）

### 欠品ありの場合
- ⚠️ 警告アイコン
- 「ピッキング完了（欠品あり）」メッセージ
- 完了数 / 欠品数の表示

---

## 6. result: 結果画面

### 概要
タスク完了後の結果表示。

### UI要素
- ✅ チェックアイコン
- 「出荷完了」メッセージ
- 「タスク一覧へ戻る」ボタン

---

## 7. ビジネスロジック

### ステータス判定

```javascript
// タスクレベル
const pendingCount = task.picking_list.filter(i => i.status === 'PENDING').length;
const pickingCount = task.picking_list.filter(i => i.status === 'PICKING').length;
const completedCount = task.picking_list.filter(i =>
    i.status === 'COMPLETED' || i.status === 'SHORTAGE'
).length;
const registeredCount = task.picking_list.filter(i => i.status !== 'PENDING').length;
const totalCount = task.picking_list.length;

const isFullyProcessed = pendingCount === 0 && pickingCount === 0;
const hasUnregisteredItems = pendingCount > 0;
const hasPickingItems = pickingCount > 0;
```

### 数量関連

```javascript
// 欠品計算（サーバー側で自動）
shortage_qty = Math.max(0, planned_qty - picked_qty);

// 数量タイプラベル
function getQtyTypeLabel(type) {
    return { 'CASE': 'ケース', 'PIECE': 'バラ', 'CARTON': 'ボール' }[type] || type;
}

// デフォルトピッキング数量 = 予定数量
pickedQty = Number(currentItem.planned_qty);
```

### アクティブ入力フィールドの判定

```javascript
// ケース/バラの分離表示
const isCaseActive = currentItem.planned_qty_type === 'CASE';
const isPieceActive = currentItem.planned_qty_type === 'PIECE';

// ケース受注数（CASE時のみ表示）
const caseOrderQty = isCaseActive ? currentItem.planned_qty : 0;
// バラ受注数（PIECE時のみ表示）
const pieceOrderQty = isPieceActive ? currentItem.planned_qty : 0;
```
