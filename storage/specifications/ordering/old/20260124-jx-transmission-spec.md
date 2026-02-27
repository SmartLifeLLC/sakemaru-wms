# WMS JX発注送信機能 仕様書

作成日: 2026-01-24
更新日: 2026-01-24

## 概要

発注確定データに対してJX-FINET経由で発注データを送信する機能の仕様を定義する。

---

## 0. 実装状況

### 完了済み

| 項目 | 状況 |
|------|------|
| wms_client_settings マイグレーション | ✅ 完了 |
| config/wms.php (s3_prefix設定) | ✅ 完了 |
| .env WMS_S3_PREFIX | ✅ 完了 |
| JX送信テスト機能（既存） | ✅ 完了 |
| JxClient サービス | ✅ 完了 |
| OrderTransmissionService（基本） | ✅ 完了 |

### 未実装

| # | 項目 | 優先度 | 依存 |
|---|------|--------|------|
| 1 | WmsClientSetting モデル | 高 | - |
| 2 | OrderFileGeneratorInterface | 高 | - |
| 3 | HanaOrderFileGenerator | 高 | #2 |
| 4 | OrderTransmissionService 改修（顧客別Generator対応） | 高 | #1, #3 |
| 5 | wms_order_jx_settings.contractor_id カラム追加 | 中 | - |
| 6 | UI: 発注送信データ作成ボタン | 中 | #4 |
| 7 | UI: 発注データ送信ボタン | 中 | #4 |
| 8 | S3バックアップ処理（wms/ prefix対応） | 中 | - |

---

## 1. 発注送信フロー

```
┌─────────────────────────────────────────────────────────────────────┐
│                    発注候補データ生成（日次バッチ）                    │
│                    wms:auto-order-calculate                         │
└─────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────┐
│                    発注確定（Filament UI）                           │
│                    → wms_order_incoming_schedules 作成              │
└─────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────┐
│                  「発注送信データ作成」ボタンクリック                  │
│                    → 発注ファイル生成                                │
│                    → wms_order_jx_documents 作成                    │
│                    → S3に保存（wms/ prefix）                        │
└─────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────┐
│                  「発注データ送信」ボタンクリック                      │
│                    → JX-FINET送信                                   │
│                    → wms_jx_transmission_logs 記録                  │
│                    → S3にバックアップ保存                            │
└─────────────────────────────────────────────────────────────────────┘
```

---

## 2. データ集約ルール

### 2.1 送信先別の集約

発注データは送信先（JX接続設定）別にファイルを生成する。

```
JX接続設定（4件）
├─ 1106: カナカン株式会社
│    └─ 発注先: 1106, 1021, 1029, 1068, 1126, 1127
├─ 1017: 三菱食品
│    └─ 発注先: 1017
├─ 1202: 北陸コカ・コーラボトリング株式会社
│    └─ 発注先: 1202
└─ 1330: K＆K国分中部株式会社
     └─ 発注先: 1330
```

### 2.2 倉庫の集約

**全倉庫のデータを1ファイルにまとめて送信**

- 送信ファイル内のBレコード（店舗/納品先）で倉庫を区別
- 1つのJX接続設定に対して1ファイル
- ファイル内に複数倉庫のデータが含まれる

```
1106_order.dat
├─ Aレコード（ヘッダ）
├─ Bレコード（倉庫91 + 発注先1106）
│    ├─ Dレコード（商品1）
│    └─ Dレコード（商品2）
├─ Bレコード（倉庫91 + 発注先1126）
│    └─ Dレコード（商品3）
├─ Bレコード（倉庫92 + 発注先1106）
│    └─ Dレコード（商品4）
└─ ...
```

---

## 3. テーブル設計

### 3.1 wms_client_settings（顧客別設定）✅ 作成済み

顧客（導入先）別に実装クラスを紐付けるテーブル。

```sql
CREATE TABLE wms_client_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id BIGINT UNSIGNED NOT NULL UNIQUE COMMENT 'クライアントID',
    order_file_generator_class VARCHAR(255) NULL COMMENT '発注ファイル生成クラス（FQCN）',
    created_at TIMESTAMP NULL,
    updated_at TIMESTAMP NULL
) COMMENT='顧客別設定';
```

#### 初期データ

```sql
INSERT INTO wms_client_settings (client_id, order_file_generator_class)
VALUES (
    (SELECT id FROM clients ORDER BY id LIMIT 1),
    'App\\Services\\AutoOrder\\Generators\\HanaOrderFileGenerator'
);
```

### 3.2 wms_order_jx_documents（JX発注ドキュメント）

生成された発注ファイルを管理するテーブル（既存）。

```sql
-- 主要カラム
id BIGINT UNSIGNED PRIMARY KEY,
wms_order_jx_setting_id BIGINT UNSIGNED NOT NULL,
batch_code VARCHAR(50) NOT NULL COMMENT 'バッチコード',
file_path VARCHAR(255) NOT NULL COMMENT 'S3ファイルパス',
file_size INT UNSIGNED NULL COMMENT 'ファイルサイズ',
record_count INT UNSIGNED NULL COMMENT 'レコード数',
order_count INT UNSIGNED NULL COMMENT '発注件数',
status ENUM('PENDING','SENT','FAILED') DEFAULT 'PENDING',
sent_at TIMESTAMP NULL,
created_at TIMESTAMP NULL,
updated_at TIMESTAMP NULL
```

---

## 4. サービス実装

### 4.1 OrderTransmissionService

発注データ送信を管理するサービス。

```php
namespace App\Services\AutoOrder;

class OrderTransmissionService
{
    /**
     * 発注送信データ作成（ファイル生成）
     */
    public function generateOrderFiles(string $batchCode): Collection
    {
        // 1. 送信対象の発注先を取得（wms_client_settings参照）
        // 2. 送信先別に発注データを集約
        // 3. 固定長ファイルを生成
        // 4. S3に保存（wms/ prefix）
        // 5. wms_order_jx_documents に記録
    }

    /**
     * 発注データ送信（JX-FINET）
     */
    public function transmitOrders(string $batchCode): TransmissionResult
    {
        // 1. wms_order_jx_documents から送信対象を取得
        // 2. JxClient で送信
        // 3. 送信結果を記録
        // 4. バックアップをS3に保存
    }
}
```

### 4.2 OrderFileGeneratorStrategy

導入先別にファイル生成ロジックを切り替えるストラテジーパターン。

```php
interface OrderFileGeneratorStrategy
{
    public function generate(Collection $orderData): string;
    public function getFileExtension(): string;
    public function getEncoding(): string;
}

// 今回の顧客用
class HanaOrderFileGenerator implements OrderFileGeneratorStrategy
{
    public function generate(Collection $orderData): string
    {
        // A/B/Dレコード形式の固定長ファイルを生成
    }
}
```

---

## 5. S3保存構造

### 5.1 prefix設定

WMSが使用するS3ファイルは `wms/` prefixを使用する。

```
s3://bucket-name/
└─ wms/
    ├─ jx-orders/           # 発注ファイル
    │   └─ 2026-01-24/
    │       ├─ 1106_order_20260124_120000.dat
    │       ├─ 1017_order_20260124_120000.dat
    │       ├─ 1202_order_20260124_120000.dat
    │       └─ 1330_order_20260124_120000.dat
    │
    ├─ jx-backup/           # 送信済みバックアップ
    │   └─ 2026-01-24/
    │       └─ ...
    │
    └─ jx-test-files/       # テスト送信用ファイル
        └─ ...
```

### 5.2 設定 ✅ 完了

```php
// config/wms.php
's3_prefix' => env('WMS_S3_PREFIX', 'wms/'),

// .env
WMS_S3_PREFIX=wms/
```

使用方法:
```php
$prefix = config('wms.s3_prefix'); // "wms/"
$path = $prefix . 'jx-orders/' . date('Y-m-d') . '/1106_order.dat';
Storage::disk('s3')->put($path, $content);
```

---

## 6. UI実装

### 6.1 発注候補一覧ページ

発注確定後、以下のボタンを表示する。

```
┌─────────────────────────────────────────────────────────────────────┐
│  発注候補一覧                                       [日付選択▼]     │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  [発注確定] [発注送信データ作成] [発注データ送信]                    │
│                                                                     │
│  ┌─────────────────────────────────────────────────────────────┐   │
│  │ 商品      │ 倉庫 │ 発注先 │ 数量 │ ステータス │             │   │
│  ├───────────┼──────┼────────┼──────┼────────────┼─────────────│   │
│  │ 商品A     │ 本社 │ カナカン│ 100  │ EXECUTED  │             │   │
│  │ 商品B     │ 金沢 │ 三菱食品│ 50   │ EXECUTED  │             │   │
│  └─────────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────────┘
```

#### ボタンの有効条件

| ボタン | 条件 |
|--------|------|
| 発注送信データ作成 | EXECUTED状態の発注がある & 未生成のファイルがある |
| 発注データ送信 | PENDING状態のwms_order_jx_documentsがある |

### 6.2 JX送信状況確認

`/admin/wms-jx-transmission-logs` で送信履歴を確認可能。

---

## 7. 実装タスク

### 7.1 完了済み

| # | タスク | ファイル |
|---|--------|----------|
| ✅ | wms_client_settings マイグレーション | `database/migrations/2026_01_24_215239_create_wms_client_settings_table.php` |
| ✅ | config/wms.php 作成（s3_prefix） | `config/wms.php` |
| ✅ | .env WMS_S3_PREFIX追加 | `.env`, `.env.example` |

### 7.2 未実装タスク

| # | タスク | 優先度 | 詳細 |
|---|--------|--------|------|
| 1 | WmsClientSetting モデル | 高 | `app/Models/WmsClientSetting.php` |
| 2 | OrderFileGeneratorInterface | 高 | `app/Contracts/OrderFileGeneratorInterface.php` |
| 3 | HanaOrderFileGenerator | 高 | `app/Services/AutoOrder/Generators/HanaOrderFileGenerator.php` |
| 4 | OrderTransmissionService 改修 | 高 | Generator切り替え対応、送信先集約対応 |
| 5 | wms_order_jx_settings.contractor_id追加 | 中 | JX設定と発注先の紐付け |
| 6 | UI: 発注送信データ作成ボタン | 中 | ListWmsOrderCandidates に追加 |
| 7 | UI: 発注データ送信ボタン | 中 | ListWmsOrderCandidates に追加 |

### 7.3 実装詳細

#### #1 WmsClientSetting モデル

```php
namespace App\Models;

class WmsClientSetting extends WmsModel
{
    protected $fillable = ['client_id', 'order_file_generator_class'];

    public function getGeneratorInstance(): ?OrderFileGeneratorInterface
    {
        if (!$this->order_file_generator_class) {
            return null;
        }
        return app($this->order_file_generator_class);
    }
}
```

#### #2 OrderFileGeneratorInterface

```php
namespace App\Contracts;

use Illuminate\Support\Collection;

interface OrderFileGeneratorInterface
{
    /**
     * 発注データから送信用ファイルを生成
     * @param Collection $orderCandidates 発注候補（送信先別にグループ化済み）
     * @return array<int, array{contractor_id: int, content: string, filename: string}>
     */
    public function generate(Collection $orderCandidates): array;

    /**
     * JX送信対象の発注先IDを取得
     */
    public function getJxTransmissionContractorIds(): array;
}
```

#### #3 HanaOrderFileGenerator

```php
namespace App\Services\AutoOrder\Generators;

class HanaOrderFileGenerator implements OrderFileGeneratorInterface
{
    // JX送信対象発注先ID（このクラス内で管理）
    private const JX_CONTRACTORS = [1106, 1017, 1202, 1330];

    // 送信先集約マッピング
    private const TRANSMISSION_MAPPING = [
        1021 => 1106,  // カナカン酒類福井 → カナカン食品
        1029 => 1106,  // カナカンフローズン → カナカン食品
        1068 => 1106,  // カナカン酒類金沢 → カナカン食品
        1126 => 1106,  // カナカン日配 → カナカン食品
        1127 => 1106,  // カナカン菓子 → カナカン食品
    ];

    private const ENCODING = 'SJIS';
    private const LINE_ENDING = "\r\n";

    public function generate(Collection $orderCandidates): array { ... }
    public function getJxTransmissionContractorIds(): array { return self::JX_CONTRACTORS; }
}
```

---

## 8. 発注ファイルフォーマット

### 8.1 レコード構造

| レコード種別 | 説明 |
|-------------|------|
| A | ファイルヘッダ（1ファイルに1件） |
| B | 店舗/納品先（発注先×倉庫単位） |
| D | 商品明細（Bレコード配下） |

### 8.2 サンプルファイル

```
storage/specifications/ordering/
├─ 1017_sample.txt  # 三菱食品
├─ 1106_sample.txt  # カナカン
├─ 1202_sample.txt  # 北陸コカ・コーラ
├─ 1330_sample.txt  # 国分中部
└─ order_file_spec_explanation.md  # フォーマット説明
```

### 8.3 フォーマット詳細

`order_file_spec_explanation.md` を参照。

固定長ファイルのルール:
- 各項目は「開始位置・桁数」が厳密
- 数値項目は左ゼロ埋め
- 文字項目は右スペース埋め
- 文字コード: Shift_JIS
- 改行コード: CRLF

---

## 9. JXテスト送信機能

### 9.1 既存機能

`/admin/wms-order-jx-settings` でJX接続設定を管理。
各設定に対して以下のアクションが利用可能:

| アクション | 説明 |
|-----------|------|
| テスト送信 | テストファイルをJX-FINETに送信 |
| 受信実行 | JX-FINETからドキュメントを受信（S3保存） |
| テスト受信 | ドキュメント受信テスト（ローカル保存） |

### 9.2 テストファイル

テスト送信用ファイルはS3の `wms/jx-test-files/` に保存。
設定画面からアップロード可能。

---

## 10. 関連仕様書

- [発注計算フロー仕様書](../20260124-order-calculation-flow.md)
- [発注計算ロジック仕様書](../20260124-order-calculation-logic.md)
- [発注ファイルフォーマット説明](order_file_spec_explanation.md)
- [移行データ仕様書](※移行システム側)
