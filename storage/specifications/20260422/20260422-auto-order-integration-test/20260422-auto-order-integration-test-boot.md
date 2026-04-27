# Work Plan: auto-order-integration-test

- **ID**: auto-order-integration-test
- **作成日**: 2026-04-22
- **最終更新**: 2026-04-22
- **ステータス**: 完了
- **ディレクトリ**: /Users/jungsinyu/Projects/sakemaru-wms/storage/specifications/20260422/20260422-auto-order-integration-test/

## セッション再開手順

コンテキストがクリアされた場合、以下を読んで作業を再開する:

1. このファイルを読む（20260422-auto-order-integration-test-boot.md）
2. 20260422-auto-order-integration-test-plan.md を読む（作業計画の全体像）
3. 下記「進捗」テーブルで現在のPhaseを確認
4. 「Phase完了記録」セクションで完了済みPhaseの実績を確認
5. 「作業中コンテキスト」セクションで途中データを確認
6. 未完了の最初のPhaseから plan.md の該当セクションを読んで作業再開

## 概要

安全在庫ベース（`OrderCandidateCalculationService`）と実績ベース（`SalesBasedOrderCandidateService`）の2サービスを統合テストし、対象判定・数量計算・INTERNAL/EXTERNAL分岐・batch_code共有・origin_type・発注CD・重複チェックの各パターンを検証。結果を分析表としてまとめる。

## 重要な設計制約

1. **`migrate:fresh` / `migrate:refresh` 禁止** — 本番データ保護
2. **マスタテーブルのtruncate禁止** — `item_contractors`, `items`, `contractors` 等
3. **候補テーブルのtruncateはOK** — `wms_stock_transfer_candidates`, `wms_order_candidates`, `wms_order_calculation_logs`, `wms_auto_order_job_controls`
4. **テスト後のデータ復元** — `item_contractors` を一時変更した場合は必ず元に戻す
5. **計算ロジックの変更禁止** — テスト対象サービスのコードは変更しない
6. **エージェント構成** — 設計・監視: Opus / 実行: Sonnet

## 対象ファイル

### 新規作成
- `app/Console/Commands/AutoOrder/TestAutoOrderCommand.php` — テスト実行Artisanコマンド
- `storage/specifications/20260422/20260422-auto-order-integration-test/test-results.md` — テスト結果レポート

### 参照のみ（変更禁止）
- `app/Services/AutoOrder/OrderCandidateCalculationService.php` — 安全在庫ベース
- `app/Services/AutoOrder/SalesBasedOrderCandidateService.php` — 実績ベース
- `app/Jobs/ProcessOrderCandidateGenerationJob.php` — 安全在庫ジョブ
- `app/Jobs/ProcessSalesBasedOrderCandidateJob.php` — 実績ジョブ
- `app/Enums/AutoOrder/OriginType.php` — origin_type enum
- `app/Enums/AutoOrder/CandidateStatus.php` — ステータス enum

## テストデータ

- 本番データをそのまま利用（マスタ変更不要）
- 対象倉庫: 自動発注有効な全32倉庫（IDs: 1-11,21,22,63,71-75,80,89-98,100,101）
- automator ユーザ: ID 9900000003 (`automator@sakemaru.ai`)

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P1: テストコマンド雛形作成 | 完了 | 2026-04-22 | `wms:test-auto-order` コマンド作成完了 |
| P2: マスタデータ分析・テストケース自動分類 | 完了 | 2026-04-22 | A1:6733 A3:2306 A5:341 A6:8300 INTERNAL:2 |
| P3: 安全在庫ベーステスト実行・検証 | 完了 | 2026-04-22 | 16/16 PASS。発注981件, 移動261件 |
| P4: 実績ベーステスト実行・検証 | 完了 | 2026-04-22 | 8/8 PASS。発注678件, 移動44件 |
| P5: batch_code共有テスト | 完了 | 2026-04-22 | 4/4 PASS。D1-D3全一致、G2重複なし |
| P6: origin_type・発注CD・重複チェック検証 | 完了 | 2026-04-22 | 1/1 PASS。origin_type分布正常 |
| P7: 最終分析レポート作成 | 完了 | 2026-04-22 | 29/29 PASS。test-results.md出力 |

---

## 作業中コンテキスト

> Phase作業中に蓄積される中間データ。セッション再開時に必ず確認。

### マスタデータ分析結果（P2完了後に記入）
- 安全在庫対象商品数: (実施後に記入)
- 実績ベース対象商品数: (実施後に記入)
- ギャップ商品数（is_auto_order=true, safety_stock=0）: (実施後に記入)
- INTERNAL発注先数: (実施後に記入)
- EXTERNAL発注先数: (実施後に記入)

### テスト実行結果サマリ（P3-P6完了後に記入）
- 安全在庫: 生成候補数 / 期待数: (実施後に記入)
- 実績ベース: 生成候補数 / 期待数: (実施後に記入)
- batch_code共有テスト: PASS/FAIL: (実施後に記入)
- origin_type検証: PASS/FAIL: (実施後に記入)
- 発注CD検証: PASS/FAIL: (実施後に記入)
- 重複チェック: PASS/FAIL: (実施後に記入)

### Git ブランチ
- 作業ブランチ: release/v1.0
- ベースブランチ: main

---

## Phase完了記録

### P1: テストコマンド雛形作成
- 完了日: -
- 成果物: `app/Console/Commands/AutoOrder/TestAutoOrderCommand.php`
- 実績:
  - (完了後に記入)

### P2: マスタデータ分析・テストケース自動分類
- 完了日: -
- 実績:
  - (完了後に記入)

### P3: 安全在庫ベーステスト実行・検証
- 完了日: -
- 実績:
  - (完了後に記入)

### P4: 実績ベーステスト実行・検証
- 完了日: -
- 実績:
  - (完了後に記入)

### P5: batch_code共有テスト
- 完了日: -
- 実績:
  - (完了後に記入)

### P6: origin_type・発注CD・重複チェック検証
- 完了日: -
- 実績:
  - (完了後に記入)

### P7: 最終分析レポート作成
- 完了日: -
- 成果物: `storage/specifications/20260422/20260422-auto-order-integration-test/test-results.md`
- 実績:
  - (完了後に記入)
