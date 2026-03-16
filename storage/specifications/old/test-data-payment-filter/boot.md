# Work Plan: test-data-payment-filter

- **ID**: test-data-payment-filter
- **作成日**: 2026-02-16
- **最終更新**: 2026-02-16
- **ステータス**: 完了
- **ディレクトリ**: storage/specifications/test-data-payment-filter/

## セッション再開手順

コンテキストがクリアされた場合、以下を読んで作業を再開する:

1. このファイルを読む（boot.md）
2. plan.md を読む（作業計画の全体像）
3. 下記「進捗」テーブルで現在のPhaseを確認
4. 「Phase完了記録」セクションで完了済みPhaseの実績を確認
5. 「作業中コンテキスト」セクションで途中データを確認
6. 未完了の最初のPhaseから plan.md の該当セクションを読んで作業再開

## 概要

テストデータ生成画面（TestDataGenerator）の伝票生成時に、得意先の支払い区分（全部/DEPOSIT/CASH）でフィルタリングできるSelectフィールドを追加する。

## 重要な設計制約

- データベース破壊コマンド（migrate:fresh, migrate:refresh等）の使用禁止
- FK（外部キー）の使用禁止
- 既存のバイヤー選択ロジック（can_register_earnings, is_allowed_case_quantity等）は維持する
- `buyer_details.payment_method` の値は `'DEPOSIT'` or `'CASH'`（文字列enum）
- Filament 4のコンポーネントパスに従う

## 対象ファイル

### 新規作成
なし

### 既存変更
- `app/Filament/Pages/TestDataGenerator.php` — UI側: Select フィールド追加（2箇所）
- `app/Console/Commands/TestData/GenerateTestEarningsCommand.php` — CLI側: `--payment-method` オプション追加
- `app/Console/Commands/TestData/GeneratePickerWaveCommand.php` — CLI側: `--payment-method` オプション追加

### 参照のみ（変更禁止）
- `app/Enums/Partners/EPaymentMethod.php` — DEPOSIT/CASH enum定義
- `app/Models/Sakemaru/Buyer.php` — current_detail() リレーション
- `app/Models/Sakemaru/BuyerDetail.php` — payment_method フィールド

## テストデータ

既存のテストデータ生成機能で動作確認可能。

---

## 進捗

| Phase | 状態 | 更新日 | 備考 |
|-------|------|--------|------|
| P1: CLIコマンドに支払い区分フィルターを追加 | 完了 | 2026-02-16 | 2コマンドに--payment-methodオプション追加 |
| P2: Filament UIに支払い区分フィルターを追加 | 完了 | 2026-02-16 | 2アクションに支払い区分Select追加 |
| P3: 動作確認 | 完了 | 2026-02-16 | ヘルプ表示OK、Pint OK、テスト既存失敗のみ |

---

## 作業中コンテキスト

> Phase作業中に蓄積される中間データ。セッション再開時に必ず確認。

### リレーション構造
- `partners.code` = buyer_code（API送信時に使用）
- `partners` → `buyers`（partner_id）→ `buyer_details`（buyer_id）
- `buyer_details.payment_method` = 'DEPOSIT' | 'CASH'
- 現在のバイヤー取得クエリは両コマンドで `buyer_details` を既にJOINしている

### Git ブランチ
- 作業ブランチ: (実施後に記入)
- ベースブランチ: release/v1.0

---

## Phase完了記録

### P1: CLIコマンドに支払い区分フィルターを追加
- 完了日: 2026-02-16
- 実績:
  - `GenerateTestEarningsCommand.php`: `--payment-method` オプション追加、`$paymentMethod` プロパティ追加、クエリに `->when()` フィルター追加
  - `GeneratePickerWaveCommand.php`: 同上の変更

### P2: Filament UIに支払い区分フィルターを追加
- 完了日: 2026-02-16
- 実績:
  - `TestDataGenerator.php`: `generateEarningsAction()` に支払い区分Select追加（count後に配置）
  - `TestDataGenerator.php`: `generatePickerWaveAction()` に支払い区分Select追加（warehouse_id後に配置）
  - 両アクションの action クロージャで `--payment-method` パラメータをコマンドに渡す処理を追加

### P3: 動作確認
- 完了日: 2026-02-16
- 実績:
  - `php artisan testdata:earnings --help`: `--payment-method` オプション表示確認OK
  - `php artisan testdata:picker-wave --help`: `--payment-method` オプション表示確認OK
  - Pint: 変更3ファイル全てPASS
  - テスト: 既存の17件失敗（今回の変更とは無関係）
