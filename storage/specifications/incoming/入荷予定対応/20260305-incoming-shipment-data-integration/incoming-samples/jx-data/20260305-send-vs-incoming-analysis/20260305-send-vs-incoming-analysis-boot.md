# Work Plan: send-vs-incoming-analysis

- **ID**: send-vs-incoming-analysis
- **作成日**: 2026-03-05
- **最終更新**: 2026-03-05
- **ステータス**: 進行中
- **ディレクトリ**: `storage/specifications/incoming/入荷予定対応/20260305-incoming-shipment-data-integration/incoming-samples/jx-data/20260305-send-vs-incoming-analysis/`

## セッション再開手順

コンテキストがクリアされた場合、以下を読んで作業を再開する:

1. このファイルを読む（20260305-send-vs-incoming-analysis-boot.md）
2. 20260305-send-vs-incoming-analysis-plan.md を読む（作業計画の全体像）
3. 下記「進捗」テーブルで現在のPhaseを確認
4. 「Phase完了記録」セクションで完了済みPhaseの実績を確認
5. 「作業中コンテキスト」セクションで途中データを確認
6. 未完了の最初のPhaseから plan.md の該当セクションを読んで作業再開

## 概要

伝票番号フォーマットを**11桁数字のみ**（`YYYYMMDDNNN`）に統一し、DB・JX送信・JX受信間の変換を不要にする。加えて仕様書のDレコード位置定義を実データに合わせて更新する。

## 重要な設計制約

- **伝票番号は11桁数字のみ**: ハイフン・記号禁止。DB = JX = 同一フォーマット
- **FK禁止**: 外部キーは使用しない
- **migrate:fresh/refresh 禁止**: 本番共有DBのため破壊的マイグレーション禁止
- **IncomingReceiveService は変更しない**: slip_number の文字列一致で照合する仕組みは維持
- **JX Bレコード伝票番号フィールドは11バイト固定**: 連番は3桁（最大999件/日）

## 対象ファイル

### 既存変更
- `app/Models/WmsOrderIncomingSchedule.php` — `generateSlipNumber()` を11桁数字のみに変更
- `app/Services/AutoOrder/Generators/HanaOrderJXFileGenerator.php` — `generateBRecord()` の伝票番号処理を簡素化
- `app/Services/AutoOrder/IncomingParsers/JxIncomingParser.php` — `formatSlipNumber()` を簡素化（ハイフン挿入を削除）
- `storage/.../jx-incoming-data-specification.md` — Dレコード位置定義の修正

### 参照のみ（変更禁止）
- `app/Services/AutoOrder/IncomingReceiveService.php` — 照合ロジック
- `app/Services/JX/JxDataWrapper.php` — FINETラッパー処理

## テストデータ

- 送信ファイル: `incoming-samples/jx-data/send-samples/1330_order_20260303124418.txt`
- 受信ファイル（旧）: `incoming-samples/jx-data/1330-mitsubishi-samples/syukka_20250801180156.txt`
- 受信ファイル（新）: `incoming-samples/jx-data/real_data.txt`
- テストコマンド: `php artisan test --filter=JxIncomingParser`

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P1: generateSlipNumber() を11桁数字に変更 | 未着手 | - | WmsOrderIncomingSchedule |
| P2: HanaOrderJXFileGenerator 送信側の修正 | 未着手 | - | generateBRecord() 簡素化 |
| P3: JxIncomingParser パーサーの修正 | 未着手 | - | formatSlipNumber() 簡素化 |
| P4: 仕様書の更新 | 未着手 | - | jx-incoming-data-specification.md |
| P5: テスト・検証 | 未着手 | - | 送受信往復の整合性確認 |

---

## 作業中コンテキスト

> Phase作業中に蓄積される中間データ。セッション再開時に必ず確認。

### 伝票番号フォーマット（統一後）
- DB格納: `YYYYMMDDNNN`（例: `20260303001`、11桁数字のみ）
- JX送信: `YYYYMMDDNNN`（同一。変換不要）
- JX受信: `YYYYMMDDNNN`（同一。変換不要）
- 連番: 3桁（001〜999）、日付ごとにリセット

### 修正の核心
- `generateSlipNumber()`: ハイフン除去、連番を5桁→3桁に変更
- `generateBRecord()`: `str_replace('-', '')` を `substr(,0,11)` に変更
- `formatSlipNumber()`: ハイフン挿入を削除、そのまま返す

### Git ブランチ
- 作業ブランチ: (実施後に記入)
- ベースブランチ: release/v1.0

---

## Phase完了記録

> 各Phase完了時にここに実績を追記する。

### P1: generateSlipNumber() を11桁数字に変更
- 完了日: -
- 実績:
  - (完了後に記入)

### P2: HanaOrderJXFileGenerator 送信側の修正
- 完了日: -
- 実績:
  - (完了後に記入)

### P3: JxIncomingParser パーサーの修正
- 完了日: -
- 実績:
  - (完了後に記入)

### P4: 仕様書の更新
- 完了日: -
- 実績:
  - (完了後に記入)

### P5: テスト・検証
- 完了日: -
- 実績:
  - (完了後に記入)
