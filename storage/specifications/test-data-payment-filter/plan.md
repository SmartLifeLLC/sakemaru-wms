# テストデータ生成 支払い区分フィルター追加 作業計画

## 前提

- TestDataGenerator画面には伝票生成アクションが2つある:
  - `generateEarningsAction()` — 売上データ生成
  - `generatePickerWaveAction()` — ピッカー別Wave生成
- 両方とも内部でCLIコマンドを呼び出す:
  - `testdata:earnings`
  - `testdata:picker-wave`
- バイヤー取得クエリは既に `buyer_details` テーブルをJOINしている
- `buyer_details.payment_method` は `'DEPOSIT'`（売掛） or `'CASH'`（現金）

---

## Phase 一覧

| # | Phase | 概要 | 完了条件 |
|---|-------|------|---------|
| P1 | CLIコマンドに支払い区分フィルターを追加 | 2つのコマンドに `--payment-method` オプションを追加 | コマンドヘルプに表示され、フィルタが動作する |
| P2 | Filament UIに支払い区分フィルターを追加 | 2つのアクションにSelectフィールドを追加 | UI上で選択でき、コマンドに渡される |
| P3 | 動作確認 | コマンドが正常に実行されることを確認 | エラーなく実行完了 |

---

## P1: CLIコマンドに支払い区分フィルターを追加

### 目的

`testdata:earnings` と `testdata:picker-wave` コマンドに `--payment-method` オプションを追加し、指定された支払い区分の得意先のみで伝票を生成する。

### 修正対象ファイル

1. `app/Console/Commands/TestData/GenerateTestEarningsCommand.php`
2. `app/Console/Commands/TestData/GeneratePickerWaveCommand.php`

### 修正方針

#### GenerateTestEarningsCommand.php

1. `$signature` に `{--payment-method= : Payment method filter (DEPOSIT, CASH, or empty for all)}` を追加
2. `handle()` でオプション値を取得し `initializeData()` に影響させる
3. `initializeData()` のバイヤー取得クエリ（L119-131）に条件追加:

```php
// 既存のクエリ
$this->eligibleBuyers = DB::connection('sakemaru')
    ->table('buyer_details as bd')
    ->join('buyers as b', 'bd.buyer_id', '=', 'b.id')
    ->join('partners as p', 'b.partner_id', '=', 'p.id')
    ->where('bd.is_active', 1)
    ->where('bd.can_register_earnings', 1)
    ->where('bd.is_allowed_case_quantity', 1)
    ->where('p.is_active', 1)
    ->where('p.is_supplier', 0)
    ->whereNull('p.end_of_trade_date')
    // ▼ 追加: payment_method フィルター
    ->when($this->paymentMethod, fn ($q) => $q->where('bd.payment_method', $this->paymentMethod))
    ->select(['p.code as buyer_code'])
    ->distinct()
    ->get();
```

4. コンソール出力にフィルター情報を追加

#### GeneratePickerWaveCommand.php

1. `$signature` に `{--payment-method= : Payment method filter (DEPOSIT, CASH, or empty for all)}` を追加
2. `initializeData()` のバイヤー取得クエリ（L200-213）に同様の条件追加:

```php
$buyers = DB::connection('sakemaru')->table('buyers')
    ->leftJoin('buyer_details', 'buyers.id', '=', 'buyer_details.buyer_id')
    ->leftJoin('partners', 'buyers.partner_id', '=', 'partners.id')
    ->where('partners.is_active', 1)
    ->where('partners.is_supplier', 0)
    ->where('buyer_details.is_active', 1)
    ->whereNull('partners.end_of_trade_date')
    ->where('buyer_details.can_register_earnings', 1)
    ->where('buyer_details.is_allowed_duplicated_item', true)
    ->where('buyer_details.is_allowed_case_quantity', true)
    // ▼ 追加: payment_method フィルター
    ->when($this->paymentMethod, fn ($q) => $q->where('buyer_details.payment_method', $this->paymentMethod))
    ->select('partners.code', 'partners.id')
    ->inRandomOrder()
    ->limit(1000)
    ->get();
```

### 完了条件

- `php artisan testdata:earnings --help` で `--payment-method` オプションが表示される
- `php artisan testdata:picker-wave --help` で `--payment-method` オプションが表示される
- オプション未指定時は全バイヤー（従来の動作と同じ）
- `--payment-method=DEPOSIT` で売掛バイヤーのみ
- `--payment-method=CASH` で現金バイヤーのみ

---

## P2: Filament UIに支払い区分フィルターを追加

### 目的

TestDataGenerator画面の2つのアクション（売上データ生成、ピッカー別Wave生成）のフォームに支払い区分のSelectフィールドを追加する。

### 修正対象ファイル

`app/Filament/Pages/TestDataGenerator.php`

### 修正方針

#### generateEarningsAction()（L609-751）

`schema` 配列の `count` フィールドの後に Selectフィールドを追加:

```php
Select::make('payment_method')
    ->label('支払い区分')
    ->options([
        '' => '全部',
        'DEPOSIT' => '売掛',
        'CASH' => '現金',
    ])
    ->default(''),
```

`action` クロージャ内で `--payment-method` パラメータをコマンドに渡す:

```php
if (!empty($data['payment_method'])) {
    $params['--payment-method'] = $data['payment_method'];
}
```

#### generatePickerWaveAction()（L421-607）

`form` 配列の `warehouse_id` の後に同じSelectフィールドを追加。
`action` クロージャ内で同様にパラメータを渡す。

### 完了条件

- UI上に「支払い区分」Selectが表示される
- 「全部」「売掛」「現金」の3つの選択肢がある
- デフォルトは「全部」（フィルターなし）
- 選択した値がCLIコマンドに正しく渡される

---

## P3: 動作確認

### 目的

変更が正しく動作することを確認する。

### 確認手順

1. `php artisan testdata:earnings --help` でオプション表示確認
2. `php artisan testdata:picker-wave --help` でオプション表示確認
3. Pint（コードフォーマッター）の実行: `./vendor/bin/pint`
4. テストの実行: `composer test`

### 完了条件

- ヘルプ表示にオプションが含まれる
- Pintエラーなし
- テストがパスする

---

## 制約（厳守）

1. データベース破壊コマンドの使用禁止
2. 既存のバイヤーフィルター条件（can_register_earnings, is_allowed_case_quantity等）は削除・変更しない
3. Filament 4のインポートパスに従う（`use Filament\Forms\Components\Select`）
4. オプション未指定時の動作は従来と完全に同じにする（後方互換性）

## 全体完了条件

- 2つのCLIコマンドに `--payment-method` オプションが追加されている
- Filament UIの2つのアクションに「支払い区分」Selectが追加されている
- 「全部」選択時は従来と同じ動作
- 「DEPOSIT」「CASH」選択時はそれぞれの支払い区分のバイヤーのみで伝票生成
- コードフォーマット済み、テストパス
