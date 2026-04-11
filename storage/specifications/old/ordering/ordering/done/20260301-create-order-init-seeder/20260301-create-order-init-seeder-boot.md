# Work Plan: create-order-init-seeder

- **ID**: create-order-init-seeder
- **作成日**: 2026-03-01
- **最終更新**: 2026-03-01
- **ステータス**: 完了
- **ディレクトリ**: storage/specifications/ordering/20260301-create-order-init-seeder/

## セッション再開手順

コンテキストがクリアされた場合、以下を読んで作業を再開する:

1. このファイルを読む（20260301-create-order-init-seeder-boot.md）
2. 20260301-create-order-init-seeder-plan.md を読む（作業計画の全体像）
3. 下記「進捗」テーブルで現在のPhaseを確認
4. 「Phase完了記録」セクションで完了済みPhaseの実績を確認
5. 「作業中コンテキスト」セクションで途中データを確認
6. 未完了の最初のPhaseから plan.md の該当セクションを読んで作業再開

## 概要

全発注先（Contractor）に対してデフォルトの自動発注時刻・送信時刻を設定する `ContractorInitSeeder` を作成し、`InitSystemSeeder` に登録する。

## 重要な設計制約

- `php artisan migrate:fresh` / `migrate:refresh` / `db:wipe` 等の破壊的コマンド禁止
- 外部キー（FK）使用禁止
- 既存の `WmsContractorSetting` レコードがある場合は時刻のみ更新（他のフィールドを壊さない）
- `ContractorMailSettingSeeder` のパターンに従う

## 対象ファイル

### 新規作成
- `database/seeders/ContractorInitSeeder.php` — 発注時刻初期設定シーダー

### 既存変更
- `database/seeders/InitSystemSeeder.php` — ContractorInitSeeder の呼び出しを追加

### 参照のみ（変更禁止）
- `app/Models/WmsContractorSetting.php` — 発注先設定モデル
- `app/Models/Sakemaru/Contractor.php` — 発注先モデル
- `app/Enums/AutoOrder/TransmissionType.php` — 送信方式enum
- `database/seeders/ContractorMailSettingSeeder.php` — 参考パターン

## テストデータ

```bash
php artisan db:seed --class=ContractorInitSeeder
```

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P1: ContractorInitSeeder 作成 | 完了 | 2026-03-01 | 1052件更新、新規0件 |
| P2: InitSystemSeeder への登録 | 完了 | 2026-03-01 | ContractorMailSettingSeeder の前に追加 |

---

## 作業中コンテキスト

> Phase作業中に蓄積される中間データ。セッション再開時に必ず確認。

### デフォルト時刻設定（仕様）
- 通常の発注先:
  - auto_order_generation_time = 09:30
  - transmission_time = 10:30
- JX-FINET の発注先:
  - auto_order_generation_time = 11:00
  - transmission_time = 12:00

### Git ブランチ
- 作業ブランチ: feature/ordering-update
- ベースブランチ: main

---

## Phase完了記録

> 各Phase完了時にここに実績を追記する。

### P1: ContractorInitSeeder 作成
- 完了日: 2026-03-01
- 成果物: database/seeders/ContractorInitSeeder.php
- 実績:
  - ContractorMailSettingSeeder パターンに準拠して実装
  - 通常発注先 → 09:30 / 10:30 設定済み
  - JX-FINET（9件）→ 11:00 / 12:00 設定済み
  - 合計1052件更新、新規0件（既存レコードの時刻フィールドのみ更新）

### P2: InitSystemSeeder への登録
- 完了日: 2026-03-01
- 成果物: database/seeders/InitSystemSeeder.php
- 実績:
  - ContractorMailSettingSeeder の前に ContractorInitSeeder 呼び出しを追加
