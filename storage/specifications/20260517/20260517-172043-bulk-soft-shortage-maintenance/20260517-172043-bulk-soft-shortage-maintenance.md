# 統合引当欠品処理（一括引当数メンテナンス）

- **作成日**: 2026-05-17
- **ステータス**: ドラフト
- **ディレクトリ**: `app/Filament/Resources/WmsPickingTasks/`
- **対象ページ**: `https://wms.sakemaru.test/admin/wms-picking-waitings`

## 背景・目的

### 現状の問題

ピッキング調整ページ（`/admin/wms-picking-waitings`）で引当欠品（`has_soft_shortage`）があるタスクの引当数を修正するには、**タスクごとに個別の明細編集ページに遷移**する必要がある。

**現在の作業動線（1タスクあたり）:**
1. ピッキング調整テーブルで「欠品あり」のタスクを確認
2. 「明細確認」ボタン → `/admin/wms-picking-item-edit?tableFilters[picking_task_id][value]=XXX` に遷移
3. 各明細の `planned_qty`（引当数）をインライン編集（1行ずつ）
4. ブラウザバックでピッキング調整に戻る
5. **欠品ありタスクの数だけ 2〜4 を繰り返す**
6. 全修正完了後、ピッカー割り当てを実行

欠品タスクが10件あれば10回ページ遷移が必要。1日の運用で数十件の欠品メンテが発生するため、業務効率が極めて悪い。

### 改善後の動線

1. ピッキング調整ページで「統合引当欠品処理」ボタンを1クリック
2. **モーダル内に全タスクの引当欠品明細を一覧表示**
3. 必要な明細の引当数をまとめて編集
4. 「確定」で一括保存
5. ピッカー割り当てへ進む

**ページ遷移ゼロ、1モーダルで全欠品の引当数を一括メンテナンス**できる。

## 現状の実装

### 引当欠品（Soft Shortage）の仕組み

- **定義**: `ordered_qty > planned_qty` のとき `has_soft_shortage = true`（DBの生成カラム）
- **意味**: 受注数に対して在庫引当が不足。ピッキング前に引当数を調整可能
- **対象ステータス**: `PENDING`、`PICKING_READY` のタスクのみ引当数を変更可能

### 数量の関係

| フィールド | 意味 | 編集可否 |
|-----------|------|---------|
| `ordered_qty` | 受注数量（基幹から） | 読取専用 |
| `planned_qty` | 引当数量（在庫から割当済） | **編集対象** |
| `picked_qty` | ピック数量（実ピック） | この機能では対象外 |
| `shortage_qty` | 欠品数（= ordered - planned） | 自動計算 |

### 関連ファイル（現在の個別編集）

- `WmsPickingItemEditResource.php` — 明細編集リソース（個別ページ版）
- `ListWmsPickingItemEdits.php` — 明細一覧ページ
  - `planned_qty` の `TextInputColumn` によるインライン編集
  - バリデーション: `planned_qty <= ordered_qty`（バラ換算で比較）
  - 変更時に `picked_qty` の上限キャップ、`shortage_qty` 再計算
  - `WmsAdminOperationLog` への操作ログ記録

## 変更内容

### 概要

`ListWmsPickingWaitings` のヘッダーに「統合引当欠品処理」ボタンを追加。クリックで開くモーダル内に、現在のフィルター条件に合致する全タスクの引当欠品明細をテーブル表示し、引当数の一括編集・一括保存を可能にする。

### 詳細設計

#### 1. ヘッダーアクションボタン追加

**ファイル**: `ListWmsPickingWaitings.php`

```php
Action::make('bulkSoftShortageMaintenance')
    ->label('統合引当欠品処理')
    ->icon('heroicon-o-wrench-screwdriver')
    ->color('warning')
    // ヘッダーアクション配列の先頭（ピッカー割り当ての左）に配置
```

**位置**: `getHeaderActions()` の `openVersion2` の次、`assignPickers` の前

#### 2. モーダル設計

**モーダル仕様**:
- **幅**: `7xl`（全幅に近い。明細情報が多いため）
- **CSSクラス**: `incoming-detail-modal`（既存のモーダルデザインに準拠）
- **ヘッダー**: 「統合引当欠品処理」
- **フッター**: 右寄せ。「確定」ボタン（danger色）+「変更せず閉じる」

**モーダル内コンテンツ**:

Alpine.js ベースの Blade コンポーネントで実装（`ViewField`）。Filament の `Repeater` では行数が多い場合のパフォーマンスが不足するため、Alpine.js + Blade で直接テーブルをレンダリングする。

```
┌─────────────────────────────────────────────────────────────────────┐
│ 統合引当欠品処理                                            [×]    │
├─────────────────────────────────────────────────────────────────────┤
│ サマリー: 対象タスク X件 / 引当欠品明細 Y件                         │
│ ※ 引当数を変更したい明細の数量を編集してください                      │
│                                                                     │
│ ┌─────────────────────────────────────────────────────────────┐     │
│ │ タスクID │ 配送コース │ 得意先   │ 商品CD │ 商品名 │ 受注 │     │
│ │         │           │          │        │        │  数  │     │
│ │         │ 引当数[入力] │ 引当区分 │ 欠品数 │ 在庫数 │      │     │
│ ├─────────┼───────────┼──────────┼────────┼────────┼──────┤     │
│ │ 1196    │ [A01]品川  │ ○○酒店  │ 12345  │ 大吟醸 │  10  │     │
│ │         │  [  8  ]  │ ケース   │   2    │  120   │      │     │
│ │ 1196    │ [A01]品川  │ △△商店  │ 67890  │ 純米酒 │   5  │     │
│ │         │  [  3  ]  │ バラ     │   2    │   45   │      │     │
│ │ 1198    │ [B02]渋谷  │ □□ストア │ 12345  │ 大吟醸 │   3  │     │
│ │         │  [  1  ]  │ ケース   │   2    │  120   │      │     │
│ └─────────────────────────────────────────────────────────────┘     │
│                                                                     │
│ 変更件数: 0件                                                       │
├─────────────────────────────────────────────────────────────────────┤
│                              [変更せず閉じる] [確定]                 │
└─────────────────────────────────────────────────────────────────────┘
```

#### 3. テーブル表示カラム

| # | カラム | ソース | 編集 | 説明 |
|---|--------|--------|------|------|
| 1 | タスクID | `picking_task_id` | - | タスク識別 |
| 2 | 配送コース | `pickingTask.deliveryCourse` | - | `[code]name` 形式 |
| 3 | 得意先名 | `earning.buyer.partner.name` | - | 得意先名（移動の場合は倉庫名） |
| 4 | 伝票番号 | `trade.serial_id` | - | 識別用 |
| 5 | 商品CD | `item.code` | - | - |
| 6 | 商品名 | `item.name` | - | `grow()` 相当 |
| 7 | 入り数 | `item.capacity_case` | - | ケース→バラ換算用 |
| 8 | 受注数 | `ordered_qty` | - | 基幹からの受注数量 |
| 9 | 受注区分 | `ordered_qty_type` | - | ケース/バラ |
| 10 | **引当数** | `planned_qty` | **入力** | **テキスト入力。メイン編集対象** |
| 11 | **引当区分** | `planned_qty_type` | **選択** | **ケース/バラ切替** |
| 12 | 欠品数 | 自動計算 | - | `ordered - planned`（バラ換算差分） |
| 13 | 現在庫 | サブクエリ | - | 当該商品の当該倉庫在庫（バラ） |

#### 4. データ取得ロジック

```php
// 現在のページフィルター条件を取得（倉庫・出荷日等）
// → 対象タスクの引当欠品明細を一括取得

WmsPickingItemResult::query()
    ->whereHas('pickingTask', function ($q) use ($filters) {
        $q->whereIn('status', [WmsPickingTask::STATUS_PENDING, WmsPickingTask::STATUS_PICKING_READY])
          ->where('shipment_date', $systemDate);
        
        if ($warehouseId) {
            $q->where('warehouse_id', $warehouseId);
        }
    })
    ->where('has_soft_shortage', true)
    ->with([
        'pickingTask.deliveryCourse',
        'item',
        'trade',
        'earning.buyer.partner',
    ])
    ->orderBy('picking_task_id')
    ->orderBy('walking_order')
    ->get();
```

**在庫数の取得**: 商品ID×倉庫IDごとに `real_stocks` の `current_quantity` を集計。パフォーマンスのため、対象商品IDリストでバッチ取得。

#### 5. 保存ロジック

モーダルの「確定」ボタン押下時:

```php
// Alpine.js から送信されるデータ構造
$changes = [
    ['id' => 123, 'planned_qty' => 10, 'planned_qty_type' => 'CASE'],
    ['id' => 456, 'planned_qty' => 5,  'planned_qty_type' => 'PIECE'],
    // ...
];
```

**処理フロー**:
1. トランザクション開始（`sakemaru` コネクション）
2. 変更のある明細のみループ:
   a. `planned_qty` が `ordered_qty` を超えないかバリデーション（バラ換算）
   b. `WmsPickingItemResult` を更新:
      - `planned_qty` / `planned_qty_type` を新値に
      - `picked_qty` が新 `planned_qty` を超える場合はキャップ
      - `shortage_qty` を再計算
      - `has_soft_shortage` は生成カラムなので自動更新
   c. `WmsAdminOperationLog` に記録（操作タイプ: `ADJUST_PICKING_QTY`）
3. トランザクションコミット
4. 成功通知:「X件の引当数を変更しました」

#### 6. Blade コンポーネント

**新規ファイル**: `resources/views/filament/forms/components/bulk-soft-shortage-table.blade.php`

Alpine.js コンポーネントで以下を管理:
- `items[]` — 全欠品明細の配列（サーバーから初期値注入）
- `changes{}` — 変更があった明細のみ追跡（`id → {planned_qty, planned_qty_type}`）
- `changeCount` — 変更件数（リアクティブ計算）
- 入力時のバリデーション（受注数上限チェック、バラ換算）
- 変更行のハイライト表示

**スタイル**:
- テーブルは `theme.css` の既存 `incoming-detail-modal` クラスを活用
- コンパクト行（`text-xs`）
- 変更行は背景色で強調（`bg-amber-50`）
- 欠品数が0になった行は `bg-green-50` で解消を視覚化

### DB変更

**なし** — 既存テーブル・カラムのみ使用。

### モデル変更

**なし** — 既存の `WmsPickingItemResult` モデルをそのまま使用。

### サービス変更

**新規メソッド追加は不要** — 保存ロジックは `ListWmsPickingWaitings` のアクション内に直接記述。既存の `WmsPickingItemEditResource` と同等のバリデーション・更新ロジックを適用。

共通化が必要な場合は後日リファクタリング。初回は動作確認を優先し、アクション内にインラインで実装する。

### UI変更

| 対象 | 変更内容 |
|------|---------|
| `ListWmsPickingWaitings.php` | ヘッダーに `bulkSoftShortageMaintenance` アクション追加 |
| 新規 Blade | `bulk-soft-shortage-table.blade.php`（モーダル内テーブル） |
| `theme.css` | 必要に応じて `@source inline()` に新クラス追加 |

### 影響範囲

| 既存機能 | 影響 |
|---------|------|
| ピッキング調整ページ（V1） | ヘッダーにボタン1つ追加。既存機能に変更なし |
| ピッキング調整ページ（V2） | 変更なし（V2にも同ボタンを追加するかは確認事項） |
| 明細編集ページ | 変更なし。個別編集は引き続き利用可能 |
| ピッカー割り当て | 変更なし |
| 欠品対応（WmsShortages） | 変更なし。引当欠品の数量調整であり、ピッキング後の欠品対応とは別機能 |

## 制約

1. **FK禁止**: データ整合性はアプリケーション層で管理
2. **migrate:fresh / refresh 禁止**: DBマイグレーション不要のため該当しない
3. **planned_qty の上限**: `ordered_qty` を超える引当は不可（バラ換算で比較）
4. **対象ステータス制限**: `PENDING` または `PICKING_READY` のタスクの明細のみ表示・編集可能
5. **楽観ロック**: 同時編集によるデータ不整合を防ぐため、更新時に `updated_at` をチェック（既存の `WmsPickingItemEditResource` と同等）
6. **操作ログ必須**: 全変更を `WmsAdminOperationLog` に記録

## 対象ファイル

### 新規作成

| ファイル | 説明 |
|---------|------|
| `resources/views/filament/forms/components/bulk-soft-shortage-table.blade.php` | モーダル内テーブル（Alpine.js） |

### 既存変更

| ファイル | 変更内容 |
|---------|---------|
| `app/Filament/Resources/WmsPickingTasks/Pages/ListWmsPickingWaitings.php` | `getHeaderActions()` にアクション追加 |
| `resources/css/filament/admin/theme.css` | 必要に応じて `@source inline()` 更新 |

### 参照のみ

| ファイル | 参照理由 |
|---------|---------|
| `app/Filament/Resources/WmsPickingTasks/WmsPickingItemEditResource.php` | バリデーション・更新ロジックの参考 |
| `app/Models/WmsPickingItemResult.php` | モデル定義の確認 |
| `app/Models/WmsPickingTask.php` | ステータス定数の参照 |
| `storage/specifications/filament4spec.md` | Filament 4 のアクション・モーダル仕様 |
| `~/.claude/design-knowledge/modal-design.md` | モーダルデザイン仕様 |

## 確認事項

1. **V2ページへの同機能追加**: `ListWmsPickingWaitingsV2` にも同じボタンを追加するか？ V2は配送コース集約版のためデータの粒度が異なるが、同じ欠品明細を対象にするなら追加可能
機能追加は不要。全ての欠品商品を一括で表示するモーダル（同じ波動ないで、出荷まえのもの）。
2. **在庫数カラムの要否**: 現在庫数の表示が必要か？ 表示する場合はサブクエリで取得するためパフォーマンスへの影響あり（対象商品数が多い場合）
不要。
3. **引当区分（ケース/バラ）の変更**: 引当数だけでなく引当区分も変更可能にするか？ 現在の個別編集ページでは変更可能だが、一括処理では引当数のみに絞るほうがシンプル
これは不要。伝票の修正が必要になるため。
4. **倉庫フィルター**: モーダル表示時にユーザーの選択倉庫で自動フィルターするか、全倉庫の欠品を表示するか
selected_warehouse_idを利用
5. **ページネーション**: 欠品明細が100件以上ある場合のページネーション要否。Alpine.js テーブルではスクロールで全件表示が自然だが、件数上限の検討が必要
スクロールで全件にする。
