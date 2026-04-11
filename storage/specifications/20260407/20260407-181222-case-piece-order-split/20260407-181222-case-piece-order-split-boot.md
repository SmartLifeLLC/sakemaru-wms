# Work Plan: case-piece-order-split

- **ID**: case-piece-order-split
- **作成日**: 2026-04-07
- **最終更新**: 2026-04-07 (全Phase完了)
- **ステータス**: 完了
- **ディレクトリ**: `storage/specifications/20260407/20260407-181222-case-piece-order-split/`

## セッション再開手順

コンテキストがクリアされた場合、以下を読んで作業を再開する:

1. このファイルを読む（20260407-181222-case-piece-order-split-boot.md）
2. 20260407-181222-case-piece-order-split-plan.md を読む（作業計画の全体像）
3. 下記「進捗」テーブルで現在のPhaseを確認
4. 「Phase完了記録」セクションで完了済みPhaseの実績を確認
5. 「作業中コンテキスト」セクションで途中データを確認
6. 未完了の最初のPhaseから plan.md の該当セクションを読んで作業再開

## 概要

発注候補の `quantity_type` を PIECE 固定から CASE/PIECE 対応に変更。自動発注はケースのみ、手動発注はケース/バラ別行生成。JXファイル・CSV・入荷予定・入荷APIも対応。

## 重要な設計制約

- **FK禁止**: 全リレーションはアプリケーションレベルで管理
- **migrate:fresh/refresh/reset/db:wipe 禁止**: 本番共有DB。テスト実行時も含む（RefreshDatabase トレイト禁止）
- **既存データ互換**: 既存の PIECE レコードは変更しない（新規生成分からケース対応）
- **仕入入数**: ケース/バラ問わず常に `capacity_case` を使用（変更しない）
- **移動候補は対象外**: 内部移動の quantity_type は変更しない

## 対象ファイル

### 既存変更
- `app/Services/AutoOrder/OrderCandidateCalculationService.php` — quantity_type→CASE、order_quantity→ケース数、case_priceプリロード
- `app/Filament/Resources/WmsOrderCandidates/Pages/ListWmsOrderCandidates.php` — 手動発注で2レコード生成
- `resources/views/filament/components/order-candidate-create-items.blade.php` — ケース/バラ同時入力対応
- `app/Services/AutoOrder/OrderExecutionService.php` — price_type設定追加
- `app/Services/AutoOrder/Generators/HanaOrderJXFileGenerator.php` — Dレコードをquantity_typeベースに変更
- `app/Filament/Resources/WmsOrderCandidates/Tables/WmsOrderCandidatesTable.php` — quantity_type表示追加
- `app/Filament/Resources/WmsOrderIncomingSchedules/Tables/WmsOrderIncomingSchedulesTable.php` — quantity_type表示調整
- `app/Http/Controllers/Api/IncomingController.php` — capacity_case返却追加
- `app/Services/AutoOrder/IncomingConfirmationService.php` — ケース数→バラ数変換

### 参照のみ（変更禁止）
- `app/Services/AutoOrder/OrderDataFileService.php` — CSV生成（既存ロジックで対応）
- `app/Services/AutoOrder/OrderTransmissionService.php` — JX送信（変更不要）
- `app/Services/AutoOrder/PurchasePriceService.php` — 単価取得（case_price取得済み）
- `app/Models/Sakemaru/ItemPartnerPrice.php` — ケース単価メソッド
- `app/Models/Sakemaru/Item.php` — capacity_case
- `app/Enums/QuantityType.php` — Enum定義

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P1: 自動発注ケース対応 | 完了 | 2026-04-07 | CalculationService変更 |
| P2: JXファイル生成対応 | 完了 | 2026-04-07 | HanaOrderJXFileGenerator変更、Generator2は変更不要 |
| P3: 手動発注ケース/バラ別行 | 完了 | 2026-04-07 | UI排他制御削除、2レコード生成、重複チェックにquantity_type追加 |
| P4: 確定・入荷予定対応 | 完了 | 2026-04-07 | price_type設定追加（demand_breakdown有無の両パス） |
| P5: テーブル表示対応 | 完了 | 2026-04-07 | 発注数にsuffix、入荷予定のケース/バラ表示をquantity_type対応 |
| P6: 入荷API対応 | 完了 | 2026-04-07 | capacity_case返却+deliver queue piece_quantity変換 |
| P7: 結合テスト | 完了 | 2026-04-07 | 全ファイル構文OK、テスト176通過（5件既存失敗） |

---

## 作業中コンテキスト

> Phase作業中に蓄積される中間データ。セッション再開時に必ず確認。

### 過去データ分析結果（仕様書作成時に確認済み）
- JX過去データ 44,868行: ケースのみ57.7%, バラのみ42.3%, 混合0%
- 同一届先×同一商品でケース+バラ別行: 2件（kokubuホッピー）
- バラ行の仕入入数: 過去データでは1だが、当システムでは capacity_case を維持

### quantity_type 固定箇所（変更対象）
- `OrderCandidateCalculationService.php` 行671: 内部移動 → PIECE固定
- `OrderCandidateCalculationService.php` 行926: 外部発注 → PIECE固定
- `ListWmsOrderCandidates.php` 行286: 手動発注 → PIECE固定

### Git ブランチ
- 作業ブランチ: (作業開始時に記入)
- ベースブランチ: release/v1.0

---

## Phase完了記録

> 各Phase完了時にここに実績を追記する。

### P1: 自動発注ケース対応
- 完了日: -
- 実績:
  - (完了後に記入)

### P2: JXファイル生成対応
- 完了日: -
- 実績:
  - (完了後に記入)

### P3: 手動発注ケース/バラ別行
- 完了日: -
- 実績:
  - (完了後に記入)

### P4: 確定・入荷予定対応
- 完了日: -
- 実績:
  - (完了後に記入)

### P5: テーブル表示対応
- 完了日: -
- 実績:
  - (完了後に記入)

### P6: 入荷API対応
- 完了日: 2026-04-07
- 実績:
  - IncomingController: formatScheduleDetail/formatWorkItemにcapacity_case追加済み
  - IncomingConfirmationService: QuantityTypeインポート追加、createDeliverQueueのitems JSONにcapacity_caseとpiece_quantity（ケース→バラ変換済み）追加

### P7: 結合テスト
- 完了日: 2026-04-07
- 実績:
  - 全8ファイル構文チェックOK（PHP + Blade）
  - テスト結果: 176 passed, 5 failed（既存テスト: ExampleTest×2, JxServerTest, ExcelTest, AutoOrderTest）
  - 今回の変更に関連するテスト失敗なし
  - ルート確認: incoming API全8ルート正常
