# 発注時刻初期設定シーダー 作業計画

## 前提

- `WmsContractorSetting` モデルに `auto_order_generation_time` と `transmission_time` フィールドが既に存在する
- `ContractorMailSettingSeeder` が同様のパターンで実装済み（全Contractorをループして設定を投入）
- `InitSystemSeeder` が初期化シーダーの集約ポイントとして機能している

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P1 | ContractorInitSeeder 作成 | 全発注先にデフォルト時刻を設定するシーダー作成 | シーダーが正常に実行でき、時刻が正しく設定される |
| P2 | InitSystemSeeder への登録 | InitSystemSeeder に呼び出しを追加 | InitSystemSeeder 経由で実行可能 |

---

## P1: ContractorInitSeeder 作成

### 目的

全発注先（`contractors` テーブル）に対して、`wms_contractor_settings` テーブルのデフォルト時刻を設定する。送信方式が JX-FINET の場合は異なる時刻を適用する。

### 修正方針

`ContractorMailSettingSeeder` のパターンに倣い、以下のロジックで実装:

1. `Contractor::all()` で全発注先を取得
2. 各発注先について `WmsContractorSetting` を検索
3. 既存レコードがある場合:
   - `transmission_type` が `JX_FINET` なら JX-FINET 用時刻で更新
   - それ以外なら通常時刻で更新
4. レコードがない場合:
   - 通常時刻で新規作成（`transmission_type` は `MANUAL_CSV` をデフォルト）
5. 処理件数（新規/更新）をログ出力

### デフォルト値

| 条件 | auto_order_generation_time | transmission_time |
|------|---------------------------|-------------------|
| 通常（JX-FINET以外） | 09:30 | 10:30 |
| JX-FINET | 11:00 | 12:00 |

### 修正対象ファイル

| ファイル | 操作 | 役割 |
|---------|------|------|
| `database/seeders/ContractorInitSeeder.php` | 新規作成 | 時刻初期設定シーダー |

### 完了条件

- `php artisan db:seed --class=ContractorInitSeeder` が正常終了する
- 通常の発注先に 09:30 / 10:30 が設定される
- JX-FINET の発注先に 11:00 / 12:00 が設定される
- 既存レコードの他フィールド（メール設定等）が破壊されない

---

## P2: InitSystemSeeder への登録

### 目的

`ContractorInitSeeder` を `InitSystemSeeder` に追加し、システム初期化時に自動実行されるようにする。

### 修正方針

`InitSystemSeeder::run()` 内の `ContractorMailSettingSeeder` 呼び出しの**前**に `ContractorInitSeeder` を追加する。理由: 時刻設定はメール設定より基本的な設定であり、先に実行すべき。

### 修正対象ファイル

| ファイル | 操作 | 役割 |
|---------|------|------|
| `database/seeders/InitSystemSeeder.php` | 既存変更 | 呼び出し追加 |

### 完了条件

- `InitSystemSeeder` に `ContractorInitSeeder` の呼び出しが追加されている
- コメントで役割が明記されている

---

## 制約（厳守）

1. `migrate:fresh` / `migrate:refresh` / `db:wipe` 等の破壊的コマンド禁止
2. FK（外部キー）使用禁止
3. 既存の `WmsContractorSetting` レコードの `transmission_type` や他のフィールドを上書きしない（時刻フィールドのみ更新）
4. `ContractorMailSettingSeeder` のコーディングパターンに合わせる

## 全体完了条件

- `ContractorInitSeeder` が単体で正常実行できる
- `InitSystemSeeder` 経由で正常実行できる
- 通常発注先 → 09:30 / 10:30、JX-FINET → 11:00 / 12:00 が正しく設定される
