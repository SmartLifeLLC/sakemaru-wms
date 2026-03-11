# ピッキングリスト生成 作業計画

## 前提

- Wave生成→在庫引当→ピッキングタスク→ピッキング実行の流れは実装済み
- 帳票PDFは TCPDF 座標描画方式（`PurchaseOrderPdfService` と同じパターン）
- リスト生成は読み取り専用（既存データを変更しない）
- 仕様書: `20260312-042241-picking-list-generation.md`
- ローカルDB: `sakemaru_hana_prod` / user: `root` / pw: なし
- DB直接アクセス: `mysql -u root sakemaru_hana_prod`

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P0 | DB分析・データ確認 | テーブル構造・データ分布・棚番規則の確認 | 分析レポート完成、設計判断に必要な数値が揃う |
| P1 | PickingListService 実装 | 3種類のリストデータを取得するサービス | 各メソッドが正しいデータ構造を返す |
| P2 | 1次リストPDF | Wave集約リストのTCPDF描画 | PDFダウンロードで正しい帳票が表示される |
| P3 | 2次リストPDF | 作業者別実行リストのTCPDF描画 | 棚番順ソート・納品先内訳が正しく表示される |
| P4 | 3次リストPDF | 納品先別仕分けリストのTCPDF描画 | 納品先別ページ分割・配送コース別グループ化 |
| P5 | Filament UIアクション統合 | Wave一覧・タスク一覧に印刷ボタン追加 | ボタンクリックでPDFダウンロード |
| P6 | 例外処理・パフォーマンス検証 | エッジケース対応・大量データ検証 | 欠品・未設定棚番の表示確認、10万行対応 |

---

## P0: DB分析・データ確認

### 目的

実装前に実データの構造・分布を把握し、SQL設計の妥当性を検証する。

### DB接続

```bash
mysql -u root sakemaru_hana_prod
```

### 調査手順

1. **テーブル件数確認**

```sql
-- mysql -u root sakemaru_hana_prod で実行
SELECT 'wms_waves' as tbl, COUNT(*) as cnt FROM wms_waves
UNION ALL SELECT 'wms_picking_tasks', COUNT(*) FROM wms_picking_tasks
UNION ALL SELECT 'wms_picking_item_results', COUNT(*) FROM wms_picking_item_results
UNION ALL SELECT 'wms_reservations', COUNT(*) FROM wms_reservations
UNION ALL SELECT 'earnings', COUNT(*) FROM earnings
UNION ALL SELECT 'trade_items', COUNT(*) FROM trade_items
UNION ALL SELECT 'items', COUNT(*) FROM items
UNION ALL SELECT 'locations', COUNT(*) FROM locations
UNION ALL SELECT 'real_stocks', COUNT(*) FROM real_stocks
UNION ALL SELECT 'delivery_courses', COUNT(*) FROM delivery_courses
UNION ALL SELECT 'partners', COUNT(*) FROM partners
UNION ALL SELECT 'wms_picking_areas', COUNT(*) FROM wms_picking_areas
UNION ALL SELECT 'wms_pickers', COUNT(*) FROM wms_pickers;
```

2. **Wave単位のデータ分布**

```sql
SELECT
  w.id, w.wave_no, w.status,
  COUNT(DISTINCT pt.id) as task_count,
  COUNT(DISTINCT pir.item_id) as sku_count,
  COUNT(pir.id) as line_count,
  SUM(pir.planned_qty) as total_qty
FROM wms_waves w
LEFT JOIN wms_picking_tasks pt ON w.id = pt.wave_id
LEFT JOIN wms_picking_item_results pir ON pt.id = pir.picking_task_id
GROUP BY w.id
ORDER BY w.id DESC LIMIT 20;
```

3. **capacity_case の NULL/0 率**

```sql
SELECT
  COUNT(*) as total,
  SUM(CASE WHEN capacity_case IS NULL OR capacity_case = 0 THEN 1 ELSE 0 END) as no_case,
  ROUND(SUM(CASE WHEN capacity_case IS NULL OR capacity_case = 0 THEN 1 ELSE 0 END) * 100.0 / COUNT(*), 1) as pct
FROM items
WHERE id IN (SELECT DISTINCT item_id FROM wms_picking_item_results);
```

4. **棚番コード構造確認**

```sql
SELECT code1, code2, code3, COUNT(*) as loc_count
FROM locations
WHERE wms_picking_area_id IS NOT NULL
GROUP BY code1, code2, code3
ORDER BY code1, code2, code3
LIMIT 30;
```

5. **納品先（buyer）分布**

```sql
SELECT
  COUNT(DISTINCT e.buyer_id) as buyer_count,
  COUNT(DISTINCT e.delivery_course_id) as course_count
FROM earnings e
JOIN wms_picking_item_results pir ON pir.earning_id = e.id;
```

6. **walking_order の分布確認**

```sql
SELECT
  COUNT(*) as total,
  SUM(CASE WHEN walking_order IS NULL THEN 1 ELSE 0 END) as null_count,
  MIN(walking_order) as min_order,
  MAX(walking_order) as max_order
FROM wms_picking_item_results;
```

### 完了条件

- 上記6つのクエリ結果が boot.md の「作業中コンテキスト」に記録されている
- 1次・2次・3次リストのSQL設計が実データと整合することを確認

---

## P1: PickingListService 実装

### 目的

3種類のピッキングリストのデータを取得する読み取り専用サービスを作成する。

### 修正方針

`app/Services/PickingList/PickingListService.php` を新規作成。DB接続は `sakemaru`。

### 実装内容

```php
namespace App\Services\PickingList;

class PickingListService
{
    /**
     * 1次ピッキングリスト（Wave集約）
     * @return array{header: array, items: Collection, summary: array}
     */
    public function generatePrimaryList(int $waveId): array;

    /**
     * 2次ピッキングリスト（タスク別実行リスト）
     * @return array{header: array, items: Collection<{location, item, total_qty, destinations: Collection}>}
     */
    public function generateSecondaryList(int $pickingTaskId): array;

    /**
     * 3次ピッキングリスト（納品先別仕分け）
     * @return array{header: array, courses: Collection<{course, destinations: Collection<{destination, items: Collection}>}>}
     */
    public function generateTertiaryList(int $waveId): array;
}
```

#### generatePrimaryList のデータ構造

```php
return [
    'header' => [
        'wave_no' => string,
        'shipping_date' => string,
        'warehouse_name' => string,
        'course_name' => string,
    ],
    'items' => [
        [
            'item_code' => string,
            'item_name' => string,
            'total_qty' => int,
            'case_qty' => int,
            'piece_qty' => int,
            'picking_zone' => string,
            'destination_count' => int,
            'shortage_qty' => int,
        ],
        // ...
    ],
    'summary' => [
        'sku_count' => int,
        'total_qty' => int,
        'total_case' => int,
        'total_piece' => int,
    ],
];
```

#### generateSecondaryList のデータ構造

```php
return [
    'header' => [
        'wave_no' => string,
        'course_name' => string,
        'area_name' => string,
        'picker_name' => string,
        'shipping_date' => string,
    ],
    'items' => [
        [
            'location_code' => string,   // code1-code2-code3
            'item_code' => string,
            'item_name' => string,
            'total_pick_qty' => int,
            'qty_type' => string,        // QuantityType label
            'destinations' => [
                ['name' => string, 'qty' => int, 'qty_type' => string],
                // ...
            ],
        ],
        // ...
    ],
    'summary' => [
        'item_count' => int,
        'location_count' => int,
        'total_qty' => int,
    ],
];
```

#### generateTertiaryList のデータ構造

```php
return [
    'header' => [
        'wave_no' => string,
        'shipping_date' => string,
    ],
    'courses' => [
        [
            'course_code' => string,
            'course_name' => string,
            'destinations' => [
                [
                    'destination_name' => string,
                    'items' => [
                        [
                            'item_code' => string,
                            'item_name' => string,
                            'planned_qty' => int,
                            'picked_qty' => int,
                            'shortage_qty' => int,
                            'qty_type' => string,
                        ],
                    ],
                    'summary' => ['item_count' => int, 'total_qty' => int],
                ],
            ],
        ],
    ],
];
```

### SQL設計

仕様書の Phase 1〜3 のSQLをそのまま使用。P0の分析結果に基づき必要に応じて調整。

### 修正対象ファイル

| ファイル | 役割 |
|---------|------|
| `app/Services/PickingList/PickingListService.php` | 新規作成 |

### 完了条件

- 3メソッドが実装されている
- 既存Waveデータに対して各メソッドを呼び出し、期待する構造のデータが返る
- `sakemaru` DB接続で読み取り専用（INSERT/UPDATE/DELETE なし）

---

## P2: 1次リストPDF（Wave集約リスト）

### 目的

1次ピッキングリストをTCPDFで描画し、PDFバイナリを返す。

### 修正方針

`app/Services/PickingList/PickingListPdfService.php` を新規作成。
`PurchaseOrderPdfService` と同じ座標描画パターン（HTML禁止）。

### レイアウト仕様

- **用紙**: A4横（297mm × 210mm）
- **マージン**: 上10mm、下15mm、左右10mm
- **ヘッダー**: Wave番号、出荷日、倉庫名、配送コース名、印刷日時
- **テーブル列**: No / 商品CD / 商品名 / 総数量 / ケース / バラ / ゾーン / 店数 / 欠品
- **フッター**: 合計行（SKU数、総数量、ケース合計、バラ合計）
- **改ページ**: 行が溢れたら自動改ページ、ヘッダー再描画

### 列幅設計（合計 277mm = 297 - 10 - 10）

| 列 | 幅(mm) | 備考 |
|---|--------|------|
| No | 12 | 右寄せ |
| 商品CD | 30 | 左寄せ |
| 商品名 | 100 | 左寄せ、長い場合は切り詰め |
| 総数量 | 25 | 右寄せ |
| ケース | 20 | 右寄せ |
| バラ | 20 | 右寄せ |
| ゾーン | 40 | 左寄せ |
| 店数 | 15 | 右寄せ |
| 欠品 | 15 | 右寄せ、0の場合は空白 |

### 修正対象ファイル

| ファイル | 役割 |
|---------|------|
| `app/Services/PickingList/PickingListPdfService.php` | 新規作成 |

### 完了条件

- `renderPrimaryPdf($data)` が有効なPDFバイナリを返す
- レイアウトが仕様書の帳票レイアウト案と一致
- 日本語フォントが正しく表示される
- 改ページ時にヘッダーが再描画される

---

## P3: 2次リストPDF（作業者別実行リスト）

### 目的

2次ピッキングリストをTCPDFで描画。棚番順ソート＋納品先内訳の親子構造。

### レイアウト仕様

- **用紙**: A4縦（210mm × 297mm）
- **ヘッダー**: Wave番号、エリア名、ピッカー名、配送コース名、出荷日
- **親行**: 棚番 / 商品CD / 商品名 / 数量 / □チェック欄
- **子行**: インデント付きで「├ 納品先名 | 数量 (区分)」
- **フッター**: 合計（品目数、棚数、総数量）
- **チェック欄**: ピッカーが手書きでチェックする空白□

### 列幅設計（合計 190mm = 210 - 10 - 10）

| 列 | 幅(mm) | 備考 |
|---|--------|------|
| 棚番 | 30 | 左寄せ |
| 商品CD | 28 | 左寄せ |
| 商品名 | 72 | 左寄せ |
| 数量 | 25 | 右寄せ |
| チェック | 15 | 空白□ |
| 区分 | 20 | 左寄せ |

子行は棚番列をスキップし、商品名列にインデント付き「├ 納品先名」、数量列に内訳数量。

### 修正対象ファイル

| ファイル | 役割 |
|---------|------|
| `app/Services/PickingList/PickingListPdfService.php` | 既存に追加 |

### 完了条件

- `renderSecondaryPdf($data)` が有効なPDFバイナリを返す
- 棚番順（walking_order → code1-code2-code3）でソートされている
- 親行の下に子行（納品先内訳）が正しくインデント表示される
- ピッカー名が空の場合でも正常に表示される

---

## P4: 3次リストPDF（納品先別仕分けリスト）

### 目的

3次ピッキングリストをTCPDFで描画。配送コース別→納品先別にページ分割。

### レイアウト仕様

- **用紙**: A4縦（210mm × 297mm）
- **ページ分割**: 納品先ごとに改ページ
- **ヘッダー**: 配送コース名、納品先名、出荷日
- **テーブル列**: 商品CD / 商品名 / 予定数 / 済数 / 欠品 / 区分
- **フッター**: 合計（品目数、総数量）、検品者サイン欄

### 列幅設計（合計 190mm）

| 列 | 幅(mm) | 備考 |
|---|--------|------|
| 商品CD | 30 | 左寄せ |
| 商品名 | 75 | 左寄せ |
| 予定数 | 22 | 右寄せ |
| 済数 | 22 | 右寄せ |
| 欠品 | 22 | 右寄せ、0なら空白 |
| 区分 | 19 | 左寄せ |

### 修正対象ファイル

| ファイル | 役割 |
|---------|------|
| `app/Services/PickingList/PickingListPdfService.php` | 既存に追加 |

### 完了条件

- `renderTertiaryPdf($data)` が有効なPDFバイナリを返す
- 納品先ごとにページが分割される
- 配送コース順→納品先名順でソートされている
- 検品者サイン欄が描画されている

---

## P5: Filament UIアクション統合

### 目的

Wave一覧・ピッキングタスク一覧画面に印刷ボタンを追加し、PDFダウンロードできるようにする。

### 修正方針

Filament 4 の `recordActions` を使用。`Filament\Actions\Action` をインポート。

### Wave一覧画面（ListWaves.php）

```php
use Filament\Actions\Action;

// recordActions に追加
Action::make('printPrimaryList')
    ->label('1次リスト')
    ->icon('heroicon-o-document-text')
    ->color('info')
    ->action(function ($record) {
        $service = new PickingListService();
        $data = $service->generatePrimaryList($record->id);
        $pdfService = new PickingListPdfService();
        $pdf = $pdfService->renderPrimaryPdf($data);
        return response()->streamDownload(
            fn () => print($pdf),
            "picking-list-1st-{$record->wave_no}.pdf",
            ['Content-Type' => 'application/pdf']
        );
    }),

Action::make('printTertiaryList')
    ->label('3次リスト')
    ->icon('heroicon-o-clipboard-document-list')
    ->color('info')
    ->action(function ($record) {
        // 同様
    }),
```

### ピッキングタスク一覧画面（ListWmsPickingTasks.php）

```php
Action::make('printSecondaryList')
    ->label('2次リスト')
    ->icon('heroicon-o-printer')
    ->color('info')
    ->action(function ($record) {
        $service = new PickingListService();
        $data = $service->generateSecondaryList($record->id);
        $pdfService = new PickingListPdfService();
        $pdf = $pdfService->renderSecondaryPdf($data);
        return response()->streamDownload(
            fn () => print($pdf),
            "picking-list-2nd-{$record->id}.pdf",
            ['Content-Type' => 'application/pdf']
        );
    }),
```

### 修正対象ファイル

| ファイル | 役割 |
|---------|------|
| `app/Filament/Resources/Waves/Pages/ListWaves.php` | recordActions に1次・3次リストボタン追加 |
| `app/Filament/Resources/WmsPickingTasks/Pages/ListWmsPickingTasks.php` | recordActions に2次リストボタン追加 |

### 完了条件

- Wave一覧の各行に「1次リスト」「3次リスト」ボタンが表示される
- ピッキングタスク一覧の各行に「2次リスト」ボタンが表示される
- ボタンクリックでPDFがダウンロードされる
- エラー時にFilament通知が表示される

---

## P6: 例外処理・パフォーマンス検証

### 目的

エッジケースの動作確認と大量データでのパフォーマンス検証。

### テスト項目

1. **欠品ありWave**: `shortage_qty > 0` の明細が含まれるWaveで1次リスト生成
2. **棚番未設定**: `location_id IS NULL` の明細が含まれるタスクで2次リスト生成 → 「未設定」表示
3. **capacity_case = 0/NULL**: ケース換算が0/総数のみになることを確認
4. **ピッカー未割当**: `picker_id IS NULL` のタスクで2次リスト生成 → ピッカー欄空白
5. **倉庫移動（STOCK_TRANSFER）**: 売上と倉庫移動が混在するWaveで各リスト生成
6. **大量データ**: 1000行以上のWaveでPDF生成の所要時間を計測（目標: 5秒以内）
7. **空Wave**: ピッキング明細が0件のWaveでエラーにならないことを確認

### パフォーマンス計測

```php
$start = microtime(true);
$data = $service->generatePrimaryList($waveId);
$elapsed = microtime(true) - $start;
// 1秒以内を目標
```

### 修正方針

- エッジケースで例外が発生する場合はサービス内で適切にハンドリング
- 大量データでOOMが発生する場合はチャンク処理を導入

### 完了条件

- 上記7つのテストケースが全て正常動作する
- 1000行以上のWaveで5秒以内にPDF生成完了
- メモリ使用量が256MB以下

---

## 制約（厳守）

1. **FK禁止**: 新規テーブル作成時は外部キー制約を使用しない
2. **migrate:fresh/refresh禁止**: 本番共有DBのため破壊的マイグレーション禁止
3. **読み取り専用**: PickingListService はデータの読み取りのみ。INSERT/UPDATE/DELETE 禁止
4. **QuantityType enum使用**: ケース→「ケース」、バラ→「バラ」
5. **TCPDF座標描画**: HTML描画禁止（既存パターンに合わせる）
6. **Filament 4インポート**: `use Filament\Actions\Action`（`Tables\Actions\Action`は使わない）
7. **商品CDは「CD」表記**: テーブルデザイン仕様に従う
8. **既存フロー不干渉**: 在庫引当・ピッキング実行・印刷依頼の既存処理を変更しない

## 全体完了条件

- 3種類のピッキングリストがPDFでダウンロード可能
- Wave一覧・ピッキングタスク一覧に印刷ボタンが表示される
- 日本語が正しく表示される
- 改ページ・ヘッダー再描画が正常動作
- 欠品・棚番未設定・ピッカー未割当のエッジケースが正常処理される
- 大量データ（1000行以上）で5秒以内にPDF生成完了
