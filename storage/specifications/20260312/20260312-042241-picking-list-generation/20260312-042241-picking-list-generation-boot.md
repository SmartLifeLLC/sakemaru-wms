# Work Plan: picking-list-generation

- **ID**: picking-list-generation
- **作成日**: 2026-03-12
- **最終更新**: 2026-03-12
- **ステータス**: 完了
- **ディレクトリ**: `/Users/jungsinyu/Projects/sakemaru-wms/storage/specifications/20260312/20260312-042241-picking-list-generation/`

## セッション再開手順

コンテキストがクリアされた場合、以下を読んで作業を再開する:

1. このファイルを読む（20260312-042241-picking-list-generation-boot.md）
2. 20260312-042241-picking-list-generation-plan.md を読む（作業計画の全体像）
3. 下記「進捗」テーブルで現在のPhaseを確認
4. 「Phase完了記録」セクションで完了済みPhaseの実績を確認
5. 「作業中コンテキスト」セクションで途中データを確認
6. 未完了の最初のPhaseから plan.md の該当セクションを読んで作業再開

## 概要

3種類のピッキングリスト（1次:Wave集約、2次:作業者別実行、3次:納品先別仕分け）をPDFで生成する機能を開発。既存のWave→ピッキングタスク→ピッキング明細のデータを読み取り専用で集約し、TCPDFで帳票出力する。

## 重要な設計制約

- **FK禁止**: 新規テーブル作成時は外部キー制約を使用しない
- **migrate:fresh/refresh禁止**: 本番共有DB
- **QuantityType enum使用**: ケース→「ケース」、バラ→「バラ」の表記統一
- **Filament 4パターン**: `recordActions` + `Filament\Actions\Action` を使用
- **読み取り専用**: リスト生成は既存の在庫引当・ピッキングフローに影響を与えない
- **PDF**: TCPDFの座標描画方式（既存 `PurchaseOrderPdfService` と同様のパターン）
- **テーブルデザイン仕様**: 商品CDは「CD」表記

## 対象ファイル

### 新規作成
- `app/Services/PickingList/PickingListService.php` — データ取得サービス
- `app/Services/PickingList/PickingListPdfService.php` — TCPDF描画サービス
- `resources/views/picking-lists/primary.blade.php` — 1次リストPDFテンプレート（※TCPDF座標描画の場合はBladeテンプレート不要、PDFサービス内で完結）

### 既存変更
- `app/Filament/Resources/Waves/Pages/ListWaves.php` — 1次・3次リスト印刷アクション追加
- `app/Filament/Resources/WmsPickingTasks/Pages/ListWmsPickingTasks.php` — 2次リスト印刷アクション追加

### 参照のみ（変更禁止）
- `app/Services/AutoOrder/PurchaseOrderPdfService.php` — TCPDF実装パターン参考
- `app/Services/Print/PrintRequestService.php` — 既存印刷フロー参考
- `app/Services/WaveService.php` — Wave生成ロジック
- `app/Services/StockAllocationService.php` — 在庫引当ロジック
- `app/Services/Picking/PickRouteService.php` — 動線最適化ロジック
- `app/Models/Wave.php` — Waveモデル・リレーション
- `app/Models/WmsPickingTask.php` — タスクモデル・リレーション
- `app/Models/WmsPickingItemResult.php` — ピッキング明細モデル

## ローカルDB接続情報

- **DB名**: `sakemaru_hana_prod`
- **ユーザー**: `root`
- **パスワード**: なし
- **接続コマンド**: `mysql -u root sakemaru_hana_prod`

## テストデータ

```bash
php artisan wms:generate-test-data    # WMSテストデータ生成
php artisan wms:generate-waves        # Wave生成テスト
```

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P0: DB分析・データ確認 | 完了 | 2026-03-12 | |
| P1: PickingListService 実装 | 完了 | 2026-03-12 | |
| P2: PickingListPdfService 実装（1次リスト） | 完了 | 2026-03-12 | |
| P3: PickingListPdfService 実装（2次リスト） | 完了 | 2026-03-12 | |
| P4: PickingListPdfService 実装（3次リスト） | 完了 | 2026-03-12 | |
| P5: Filament UIアクション統合 | 完了 | 2026-03-12 | |
| P6: 例外処理・パフォーマンス検証 | 完了 | 2026-03-12 | |

---

## 作業中コンテキスト

> Phase作業中に蓄積される中間データ。セッション再開時に必ず確認。

### DB分析結果（P0完了）
- Wave単位の平均SKU数: 約22（最大64）
- Wave単位の最大行数: 79行（Wave ID=16）
- 棚番ソート規則: walking_order はほぼNULL（896/898件NULL）→ code1-code2-code3 で文字列ソート
- 配送コース数: 14コース（85納品先）
- capacity_case の NULL/0 率: 0%（ピッキング対象商品322件すべて設定済み）
- location_id NULL率: 58/898件（6.5%）→「未設定」表示が必要
- テーブル件数: waves=29, tasks=71, item_results=898, reservations=902
- 棚番構造: code1(AA,AB,AC,C,J,L,P等) - code2(0-20) - code3(001-308)
- フォント: kozminproregular（PurchaseOrderPdfServiceの実績に合わせる）

### PDF設計メモ（P2以降で記入）
- 用紙サイズ: 1次=A4横、2次・3次=A4縦
- フォント: TCPDF kozgopromedium（日本語）
- 座標描画パターン: PurchaseOrderPdfService 参考

### Git ブランチ
- 作業ブランチ: (作成後に記入)
- ベースブランチ: release/v1.0

---

## Phase完了記録

> 各Phase完了時にここに実績を追記する。

### P0: DB分析・データ確認
- 完了日: 2026-03-12
- 実績:
  - 6つのDBクエリ実行完了
  - walking_orderがほぼNULL → code1-code2-code3ソートをデフォルトに
  - capacity_caseは全件設定済み → ケース/バラ換算は問題なし
  - location_id NULL 6.5% → 「未設定」表示必要
  - フォント: kozminproregular（PurchaseOrderPdfService実績に合わせる）

### P1: PickingListService 実装
- 完了日: 2026-03-12
- 成果物: `app/Services/PickingList/PickingListService.php`
- 実績:
  - 3メソッド実装完了（generatePrimaryList, generateSecondaryList, generateTertiaryList）
  - 全て読み取り専用（INSERT/UPDATE/DELETE なし）
  - 実データで動作確認済み

### P2: PickingListPdfService 実装（1次リスト）
- 完了日: 2026-03-12
- 成果物: `app/Services/PickingList/PickingListPdfService.php`
- 実績:
  - A4横、TCPDF座標描画、kozminproregularフォント
  - 改ページ＋ヘッダー再描画対応

### P3: PickingListPdfService 実装（2次リスト）
- 完了日: 2026-03-12
- 実績:
  - A4縦、棚番順ソート、親子構造（納品先内訳）
  - チェック欄□、ピッカー未割当時は空白表示

### P4: PickingListPdfService 実装（3次リスト）
- 完了日: 2026-03-12
- 実績:
  - A4縦、納品先ごとにページ分割、検品者サイン欄

### P5: Filament UIアクション統合
- 完了日: 2026-03-12
- 成果物: `WavesTable.php`, `WmsPickingTasksTable.php`
- 実績:
  - Wave一覧に「1次リスト」「3次リスト」ボタン追加
  - ピッキングタスク一覧に「2次リスト」ボタン追加
  - エラー時Filament通知表示

### P6: 例外処理・パフォーマンス検証
- 完了日: 2026-03-12
- 実績:
  - 棚番未設定→「未設定」表示 OK
  - 空Wave→空配列返却 OK（エラーなし）
  - パフォーマンス: 56行で36ms、メモリ73.5MB
