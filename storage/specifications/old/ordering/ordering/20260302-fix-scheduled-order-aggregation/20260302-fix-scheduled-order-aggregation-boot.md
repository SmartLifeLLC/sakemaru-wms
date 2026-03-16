# Work Plan: fix-scheduled-order-aggregation

- **ID**: fix-scheduled-order-aggregation
- **作成日**: 2026-03-02
- **最終更新**: 2026-03-02
- **ステータス**: 完了
- **ディレクトリ**: `storage/specifications/ordering/20260302-fix-scheduled-order-aggregation/`

## セッション再開手順

コンテキストがクリアされた場合、以下を読んで作業を再開する:

1. このファイルを読む（20260302-fix-scheduled-order-aggregation-boot.md）
2. 20260302-fix-scheduled-order-aggregation-plan.md を読む（作業計画の全体像）
3. 下記「進捗」テーブルで現在のPhaseを確認
4. 「Phase完了記録」セクションで完了済みPhaseの実績を確認
5. 「作業中コンテキスト」セクションで途中データを確認
6. 未完了の最初のPhaseから plan.md の該当セクションを読んで作業再開

## 概要

スケジュール自動発注で、集約先（`transmission_contractor_id`）が設定された子仕入先の発注候補が生成されない問題を修正する。
親仕入先のスケジュールトリガー時に、子仕入先の候補も同時生成されるようにする。

## 重要な設計制約

- FK禁止（CLAUDE.md記載）
- `migrate:fresh` / `migrate:refresh` / `db:wipe` 禁止
- DB変更なし（既存の `transmission_contractor_id` カラムをそのまま利用）
- ファイル送信フェーズ（HanaOrderJXFileGenerator, OrderTransmissionService）は変更禁止（既に正しく動作）
- 移動候補・発注候補の承認は同時適用でOK（確認済み）

## 対象ファイル

### 新規作成
なし

### 既存変更
- `app/Models/WmsContractorSetting.php` — 展開メソッド2つ追加
- `app/Services/AutoOrder/OrderCandidateCalculationService.php` — `targetContractorId` → `targetContractorIds` 配列化
- `app/Jobs/ProcessOrderCandidateGenerationJob.php` — 排他チェックに子仕入先含める
- `app/Console/Commands/AutoOrder/AutoOrderScheduledCommand.php` — 未処理候補チェックに子仕入先含める

### 参照のみ（変更禁止）
- `app/Services/AutoOrder/Generators/HanaOrderJXFileGenerator.php` — 集約ロジック確認
- `app/Services/AutoOrder/OrderTransmissionService.php` — 送信フロー確認
- `app/Services/JX/JxTestFileGenerator.php` — 正しいパターンの参考実装
- `app/Filament/Resources/WmsAutoOrderJobControls/Pages/ListWmsAutoOrderJobControls.php` — 手動生成UIの確認
- `routes/console.php` — スケジュール定義

## テストデータ

- 集約設定: contractor_id 1021, 1029, 1068, 1126, 1127, 1680 → transmission_contractor_id = 1106
- `wms_contractor_warehouse_settings`: 現在0件（確定レベル未設定、全候補STATUS1）

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P1: モデル展開メソッド追加 | 完了 | 2026-03-02 | tinker確認OK |
| P2: 候補生成フロー修正 | 完了 | 2026-03-02 | 3ファイル修正、構文OK、grep確認OK |
| P3: 動作検証 | 完了 | 2026-03-02 | 全条件OK、Pint/構文チェックOK |

---

## 作業中コンテキスト

> Phase作業中に蓄積される中間データ。セッション再開時に必ず確認。

### 集約設定データ（調査済み）
- 親仕入先: contractor_id=1106（カナカン食品）
- 子仕入先: 1021, 1029, 1068, 1126, 1127, 1680（全て transmission_contractor_id=1106）
- HanaOrderJXFileGenerator の TRANSMISSION_MAPPING にも同じマッピングがハードコード

### 確認済み事項
- 移動候補・発注候補の承認は同時適用でOK
- 倉庫別承認段階設定は仕組み実装済み（データ未登録）

### Git ブランチ
- 作業ブランチ: feature/ordering-update
- ベースブランチ: main

---

## Phase完了記録

> 各Phase完了時にここに実績を追記する。

### P1: モデル展開メソッド追加
- 完了日: 2026-03-02
- 実績:
  - `getChildContractorIds(1106)` → [1021,1029,1126,1127,1068,1680] 確認
  - `getContractorIdsWithChildren(1106)` → 7件（親+子6件）確認
  - `getContractorIdsWithChildren(1001)` → [1001]（子なし）確認

### P2: 候補生成フロー修正
- 完了日: 2026-03-02
- 実績:
  - `OrderCandidateCalculationService`: targetContractorId→targetContractorIds(配列)、排他チェック+候補フィルタを`whereIn`に
  - `AutoOrderScheduledCommand`: 未処理候補チェックに子仕入先含める
  - 構文チェックALL OK、targetContractorId単数参照の残存なし

### P3: 動作検証
- 完了日: 2026-03-02
- 実績:
  - 旧`targetContractorId`（単数）参照の残存なし確認
  - 展開メソッド: 1106→7件(親+子6), 1001→1件(子なし), 1021→1件(子自身)
  - item_contractors: 子仕入先にis_auto_order=1,safety_stock>0データあり確認(1021:8600件等)
  - Pintフォーマット: PASS
  - 構文チェック: ALL OK（4ファイル）
  - 排他チェック: CalcService.calculate()内で統合済み、Job側追加修正不要
