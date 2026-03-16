# JX送信テスト実装プロンプト

作成日: 2026-02-02
When complete:
- ローカル環境で「ファイル生成 → 確認 → 送信 → テストサーバ受信確認」の一連のフローを検証できる。
- 送信用のファイルが生成され、TESTサーバより受信もできた。
- 
## 目的

4社の問屋向けJXファイル生成・送信機能のテストを実施する。
ローカル環境で「ファイル生成 → 確認 → 送信 → テストサーバ受信確認」の一連のフローを検証する。

---

## 背景情報

### JX設定（wms_order_jx_settings）

| ID | 名前 | sender | receiver | 送信先URL |
|----|------|--------|----------|-----------|
| 1 | カナカン株式会社 | 420701 | MA166F | https://wms.sakemaru.test/jx-server |
| 2 | 三菱食品 | 420701 | MA0807 | https://wms.sakemaru.test/jx-server |
| 3 | 北陸コカ・コーラボトリング株式会社 | 420701 | MB65D7 | https://wms.sakemaru.test/jx-server |
| 4 | K＆K国分中部株式会社 | 420701 | EA0683 | https://wms.sakemaru.test/jx-server |

### 発注先と送信先の関係（wms_contractor_settings）

```
カナカン (JX設定ID: 1)
├─ [1106] カナカン(株)食品 福井営業所 ← 代表（JX設定あり）
├─ [1021] カナカン(株)酒類 福井営業所 → 1106に集約
├─ [1029] カナカン(株)フローズン → 1106に集約
├─ [1068] カナカン(株)酒類 金沢営業所 → 1106に集約
├─ [1126] カナカン(株)日配 福井営業所 → 1106に集約
├─ [1127] カナカン(株)菓子 福井営業所 → 1106に集約
└─ [1680] カナカン金沢支店 酒類石川 → 1106に集約

三菱食品 (JX設定ID: 2)
└─ [1330] 三菱食品株式会社

北陸コカ・コーラ (JX設定ID: 3)
└─ [1017] 北陸コカ・コーラボトリング（株）

国分中部 (JX設定ID: 4)
└─ [1202] 国分中部㈱第二支社 福井支店
```

### 既存実装

- **JxClient** (`app/Services/JX/JxClient.php`): JX-FINET送信クライアント
- **JxServerController** (`app/Http/Controllers/JxServerController.php`): テスト用受信サーバー
- **HanaOrderFileGenerator** (`app/Services/AutoOrder/Generators/HanaOrderFileGenerator.php`): 発注ファイル生成

### 関連テーブル

| テーブル | 用途 |
|---------|------|
| `wms_order_jx_settings` | JX接続設定（4社分） |
| `wms_contractor_settings` | 発注先設定（transmission_contractor_id で送信先集約） |
| `wms_order_jx_documents` | 生成した発注ファイル管理 |
| `wms_jx_transmission_logs` | 送信履歴ログ |
| `wms_contractor_suppliers` | 発注先-仕入先マッピング（参考情報、JX送信には不使用） |

### 注意点

- JXファイルは**発注先コード（contractor code）**で生成される（仕入先コードではない）
- `item_contractors`と`wms_contractor_suppliers`の不整合はJX送信に影響しない

---

## テスト要件

### テストパターン

#### パターン1: 空のJXファイル送信
- **目的**: 発注データがない場合のファイル送信テスト
- **条件**: .datファイルのサイズが0バイト
- **確認項目**:
  - 空ファイルが正しく生成されること
  - JX送信が正常に完了すること
  - テストサーバで受信できること

#### パターン2: 全商品発注ファイル
- **目的**: 各発注先が持つ全ての発注可能商品の発注ファイル生成
- **条件**: 各JX設定に紐づく発注先の全商品を含むファイル
- **対象**:
  - カナカン: 1106, 1021, 1029, 1068, 1126, 1127, 1680 の全商品
  - 三菱食品: 1330 の全商品
  - 北陸コカ・コーラ: 1017 の全商品
  - 国分中部: 1202 の全商品
- **確認項目**:
  - 全商品が含まれていること
  - レコード形式（A/B/D）が正しいこと
  - Shift_JIS、128バイト固定長であること

#### パターン3: 送信先集約テスト（カナカンケース）
- **目的**: `transmission_contractor_id`による送信先集約の動作確認
- **条件**: 複数の発注先（1021, 1029等）のデータが1106に集約されること
- **確認項目**:
  - 1つのファイルに複数発注先のBレコードが含まれること
  - 各発注先の商品が正しくDレコードに出力されること
  - 取引先コード（Bレコード39-42桁）が各発注先のコードであること

---

## 実装タスク

### 1. テストファイル生成機能

JX送信テスト用のファイル生成コマンドまたはUI機能を実装する。

**生成パターン**:
```php
// パターン1: 空ファイル
public function generateEmptyFile(int $jxSettingId): string

// パターン2: 全商品ファイル
public function generateFullOrderFile(int $jxSettingId): string

// パターン3: 集約テスト用ファイル
public function generateAggregatedFile(int $jxSettingId): string
```

**データ取得ロジック**:
```php
// 発注先の全商品を取得
$items = ItemContractor::where('contractor_id', $contractorId)
    ->with('item')
    ->get();

// カナカンの場合は集約対象の発注先も含める
if ($jxSettingId === 1) {
    $contractorIds = [1106, 1021, 1029, 1068, 1126, 1127, 1680];
    $items = ItemContractor::whereIn('contractor_id', $contractorIds)
        ->with('item')
        ->get();
}
```

### 2. テストサーバ確認機能

**確認項目**:
- `storage/app/jx-server/received/` にリクエストXMLが保存されること
- `storage/app/jx-server/documents/` にデータが保存されること
- `storage/app/jx-server/pending/` に未配信データがあること

**確認コマンド**:
```bash
# 受信データ確認
ls -la storage/app/jx-server/received/$(date +%Y-%m-%d)/

# 保存されたドキュメント確認
ls -la storage/app/jx-server/documents/$(date +%Y-%m-%d)/

# データ内容確認（Shift_JIS→UTF-8変換）
iconv -f SJIS -t UTF-8 storage/app/jx-server/documents/$(date +%Y-%m-%d)/*.txt
```

### 3. 送信ログ確認

**wms_jx_transmission_logs テーブル**:
```sql
SELECT
    id,
    wms_order_jx_setting_id,
    operation_type,
    message_id,
    is_success,
    document_type,
    data_size,
    file_path,
    created_at
FROM wms_jx_transmission_logs
ORDER BY created_at DESC
LIMIT 10;
```

---

## テスト実施手順

### Step 1: 環境準備

```bash
# 1. ローカルサーバ起動
composer dev

# 2. JXサーバのURL確認（現在はテストURL）
# wms_order_jx_settings.endpoint_url = https://wms.sakemaru.test/jx-server
```

### Step 2: テストファイル生成

```bash
# Artisanコマンドまたはtinkerで実行
php artisan wms:generate-jx-test-files --pattern=empty --jx-setting=1
php artisan wms:generate-jx-test-files --pattern=full --jx-setting=1
php artisan wms:generate-jx-test-files --pattern=aggregated --jx-setting=1
```

### Step 3: ファイル確認

```bash
# 生成されたファイルの確認
ls -la storage/app/jx-test/

# ファイル内容確認
hexdump -C storage/app/jx-test/1106_order_*.dat | head -50
```

### Step 4: JX送信実行

```bash
# tinkerで送信テスト
php artisan tinker

>>> $setting = \App\Models\WmsOrderJxSetting::find(1);
>>> $client = new \App\Services\JX\JxClient($setting);
>>> $fileContent = file_get_contents('storage/app/jx-test/1106_order_xxx.dat');
>>> $result = $client->putDocumentWithWrapper($fileContent, '01');
>>> $result->success;
```

### Step 5: 受信確認

```bash
# テストサーバに保存されたデータ確認
ls -la storage/app/jx-server/documents/$(date +%Y-%m-%d)/

# 内容確認
cat storage/app/jx-server/documents/$(date +%Y-%m-%d)/*.txt | iconv -f SJIS -t UTF-8
```

### Step 6: ログ確認

```bash
php artisan tinker

>>> \App\Models\WmsJxTransmissionLog::latest()->take(5)->get(['id', 'operation_type', 'is_success', 'message_id', 'created_at']);
```

---

## 期待する成果物

### 1. コマンド/UI
- テストファイル生成コマンド `wms:generate-jx-test-files`
- または管理画面からのテストファイル生成・送信機能

### 2. テスト結果レポート
各パターンのテスト結果を記録:
- 生成ファイル名とサイズ
- 送信結果（成功/失敗）
- 受信確認結果
- 問題点・改善点

### 3. 本番送信前チェックリスト
- [ ] endpoint_urlの変更手順
- [ ] SSL証明書の設定確認
- [ ] Basic認証情報の確認
- [ ] 送信先（receiver_station_code）の確認

---

## 補足：本番移行時の設定変更

```sql
-- テスト完了後、本番URLに変更
UPDATE wms_order_jx_settings
SET endpoint_url = 'https://本番URL/jx-server'
WHERE id IN (1, 2, 3, 4);
```

---

## 関連ドキュメント

- [JX送信仕様書](20260124-jx-transmission-spec.md)
- [発注ファイルフォーマット説明](order_file_spec_explanation.md)
- [サンプルファイル](1106_sample.txt, 1017_sample.txt, 1202_sample.txt, 1330_sample.txt)
