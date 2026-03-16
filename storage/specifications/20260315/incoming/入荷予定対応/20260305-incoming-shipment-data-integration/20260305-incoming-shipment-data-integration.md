# 入荷予定データ受信・照合機能

- **作成日**: 2026-03-05
- **ステータス**: ドラフト
- **ディレクトリ**: `storage/specifications/incoming/入荷予定対応/20260305-incoming-shipment-data-integration/`

## 背景・目的

現状、入荷予定（`wms_order_incoming_schedules`）は以下の方法で生成される:
1. 自動発注候補の確定時（`OrderExecutionService.confirmCandidate()`）
2. 手動入庫予定追加（Filament UI）
3. 倉庫間移動候補の確定時

入庫実績の登録は、Handy端末またはWeb UI上で担当者が手動で数量入力・確定する運用。

**課題**: 仕入先（発注先）から届く**出荷実績データ**（CSVまたはJX受信）を取り込み、入荷予定と自動照合して実績数量を反映する仕組みがない。現状は全て手作業で入力しており、効率が悪い。

**目的**: 仕入先からの出荷実績データを受信し、**伝票番号（slip_number）を照合キー**として入荷予定とマッチングし、`received_quantity` を自動更新する機能を構築する。

## 全体アーキテクチャ

```
Phase 1: 伝票番号の基盤整備
  入荷予定に slip_number を必須化 → 発注確定時に自動採番
  JXファイル送信時にDB保存済み伝票番号を使用

Phase 2: 受信データの取り込み・照合
  JX/CSV受信データを3層テーブルに全項目保存
  伝票番号ベースで入荷予定と自動照合
  欠品判定（数量0 or 入荷予定に存在しない商品）
  担当者確認画面で最終確定
```

---

## 現状の実装

### 入荷予定テーブル（`wms_order_incoming_schedules`）

| カラム | 用途 |
|---|---|
| `warehouse_id` | 入荷先倉庫 |
| `item_id` | 商品 |
| `contractor_id` | 発注先 |
| `expected_quantity` | 予定数量 |
| `received_quantity` | 入荷実績数量（この値を更新対象） |
| `status` | PENDING → PARTIAL → CONFIRMED → TRANSMITTED |
| `expected_arrival_date` | 入荷予定日 |
| `order_source` | AUTO / MANUAL / TRANSFER |

### ステータスフロー

```
PENDING（未入庫）
  ↓ 一部入庫
PARTIAL（一部入庫）
  ↓ 全数入庫
CONFIRMED（入庫完了）
  ↓ 会計連携
TRANSMITTED（連携済み）
```

### 発注データ送信設定（`wms_contractor_settings`）

発注データの**送信**設定は既に存在:
- `transmission_type`: JX_FINET / FTP / MANUAL_CSV / INTERNAL
- `transmission_contractor_id`: 集約先
- `wms_order_jx_setting_id` / `wms_order_ftp_setting_id`: 送信設定

**入荷データの受信設定は未実装。**

---

## JX納品伝票データ仕様

### データ構造

```
[1レコード] FINET通信ヘッダー（128バイト）  ← "1" で始まる
[Aレコード] ファイルヘッダー（128バイト）    ← 発注先情報
[Bレコード] 伝票ヘッダー（128バイト）        ← 伝票番号・倉庫・納品日
  [Dレコード] 伝票明細（128バイト）×n        ← 商品・数量（最大6件/B）
  [Dレコード] ...
[Bレコード] 伝票ヘッダー
  [Dレコード] ...
  ...
[8レコード] FINET通信フッター（128バイト）   ← "8" で始まる
```

- 全レコード **128バイト固定長**、**Shift_JIS** エンコーディング
- FINET通信ヘッダー/フッターの付与・除去は `JxDataWrapper` クラスが担当
- 旧システムのサンプルデータにはFINETラッパー（1/8行）が無い（A/B/Dのみ）

### FINET通信ヘッダー（1レコード）— 128バイト

| 位置 | 桁数 | 型 | 項目名 | 実データ例 | 備考 |
|------|------|-----|--------|-----------|------|
| 1 | 1 | X | レコード区分 | `1` | 固定 |
| 2-8 | 7 | 9 | データシリアルNo. | `0000001` | |
| 9-10 | 2 | X | データ種別 | `90` | 送信=`91`(発注) / 受信=`90`(納品) |
| 11-16 | 6 | 9 | データ作成日 | `260304` | YYMMDD |
| 17-22 | 6 | 9 | データ作成時刻 | `085528` | HHMMSS |
| 23-24 | 2 | X | ファイルNo. | `00` | |
| 25-30 | 6 | 9 | データ処理日 | `260304` | YYMMDD |
| 31-42 | 12 | X | 利用者企業CD（受注） | `18015360` | 受信元の企業コード |
| 43-48 | 6 | X | 送信元センターCD | `MA0807` | FINETセンター |
| 49-50 | 2 | X | 予備 | | |
| 51-56 | 6 | X | 最終送信先CD | `420701` | 当社のFINETステーションコード |
| 57-58 | 2 | X | 最終送信先ステーション | | |
| 59-64 | 6 | X | 直接送信先企業CD | `420701` | |
| 65-66 | 2 | X | 直接送信先ステーション | | |
| 67-78 | 12 | X | 提供企業CD | `23060166` | |
| 79-90 | 12 | X | 提供企業事業所CD | `23060166` | |
| 91-105 | 15 | X | 提供企業名 | `ﾐﾂﾋﾞｼｼﾖｸﾋﾝ` | 半角カナ |
| 106-115 | 10 | X | 提供企業事業所名 | `ﾁﾕｳﾌﾞ` | 半角カナ |
| 116-121 | 6 | 9 | 送信データ件数 | `002757` | A+B+Dの合計 |
| 122-124 | 3 | 9 | レコードサイズ | `128` | 固定 |
| 125-128 | 4 | X | 余白 | | |

### A レコード（ファイルヘッダー）— 128バイト

| 位置 | 桁数 | 型 | 項目名 | 実データ例 | 備考 |
|------|------|-----|--------|-----------|------|
| 1 | 1 | X | レコード区分 | `A` | 固定 |
| 2-3 | 2 | 9 | データ種別 | `02` | 02=納品伝票 |
| 4-5 | 2 | 9 | 送受信区分 | `20` | 20=受信データ |
| 6-11 | 6 | 9 | データ作成日 | `260303` | YYMMDD |
| 12-17 | 6 | 9 | データ作成時刻 | `160057` | HHMMSS |
| 18-33 | 16 | 9 | 予備 | `0000000000000000` | |
| 34-37 | 4 | 9 | レコード件数(B+D) | `2755` | |
| 38-43 | 6 | 9 | 帳票枚数(B数) | `000394` | 伝票枚数 |
| 44 | 1 | X | 区切り | ` ` | |
| 45-59 | 15 | X | 社名 | `ﾐﾂﾋﾞｼｼｮｸﾋﾝ` | 半角カナ |
| 60-128 | 69 | X | 余白 | | |

### B レコード（伝票ヘッダー）— 128バイト ★照合キー

| 位置 | 桁数 | 型 | 項目名 | 実データ例 | 備考 |
|------|------|-----|--------|-----------|------|
| 1 | 1 | X | レコード区分 | `B` | 固定 |
| 2-3 | 2 | 9 | データ種別 | `02` | 02=納品伝票 |
| **4-14** | **11** | **X** | **伝票番号** | **`20260303001`** | **照合キー。発注時送信番号と同一** |
| 15-18 | 4 | X | 社・店コード | `0001` | 入荷先倉庫コード（0埋め4桁） |
| 19-21 | 3 | X | 分類コード | `999` | |
| 22-23 | 2 | X | 伝票区分 | `02` | 01=発注, 02=納品 |
| 24-29 | 6 | 9 | 発注日 | `260303` | YYMMDD |
| 30-35 | 6 | 9 | 納品日 | `260305` | YYMMDD |
| 36-38 | 3 | X | 便 | | |
| 39-42 | 4 | X | 取引先コード | `1330` | 発注先コード |
| 43-57 | 15 | X | 店名 | `ﾎﾝﾃﾝ` | 半角カナ |
| 58-67 | 10 | X | 納品場所 | `ﾎﾝﾃﾝ` | 半角カナ |
| 68-92 | 25 | X | G（備考） | | |
| 93-94 | 2 | X | 直送区分 | `1 ` | |
| 95-128 | 34 | X | FILLER | | |

### D レコード（伝票明細）— 128バイト

| 位置 | 桁数 | 型 | 項目名 | 実データ例 | 備考 |
|------|------|-----|--------|-----------|------|
| 1 | 1 | X | レコード区分 | `D` | 固定 |
| 2-3 | 2 | 9 | データ種別 | `02` | |
| 4-5 | 2 | 9 | 伝票行番号 | `01` | 01〜06 |
| 6-37 | 32 | N | 品名 | `ハイネケン・瓶３３０ｍｌ` | 全角32文字 |
| 38-50 | 13 | X | JANコード | `4901411147017` | |
| 51-56 | 6 | X | 自社コード | `142064` | 発注先側の商品コード |
| 57-62 | 6 | 9 | 入数 | `000030` | 1ケースあたり |
| 63-69 | 7 | 9 | ケース数 | `0000001` | 出荷ケース数 |
| 70-76 | 7 | 9 | 数量（バラ） | `0000000` | 出荷バラ数 |
| 77-86 | 10 | 9 | 原単価 | `0000550400` | 整数8桁+小数2桁 |
| 87-92 | 6 | 9 | 売単価/単品総数 | | バラ換算総数 |
| 93-107 | 15 | X | G（備考） | | |
| 108-116 | 9 | 9 | 原価金額 | | |
| 117-128 | 12 | X | 余白 | | |

### FINET通信フッター（8レコード）— 128バイト

`"8"` + 127スペース。`JxDataWrapper::generateFooter()` と対になる。

### 発注データとの対応関係

| 項目 | 発注時（送信） | 納品時（受信） | 照合 |
|------|---------------|---------------|------|
| **伝票番号** | WMS生成（DB保存） | **同一番号が返る** | **主キー照合** |
| 伝票区分 | `01`（発注） | `02`（納品） | 区別用 |
| 社・店コード | 入荷先倉庫コード | 同一 | 補助照合 |
| 取引先コード | 発注先コード | 同一 | 補助照合 |
| 自社コード | items.code | 同一 | 商品照合 |
| ケース数/バラ数 | 発注数量 | **実出荷数量** | 差分=欠品 |

### サンプルデータ

| 発注先 | コード | サンプル | 特徴 |
|--------|--------|---------|------|
| コカコーラ | 1017 | `incoming-samples/jx-data/1017-cocacola-samples/` | FINETラッパーなし（旧） |
| カナカン | 1021 | `incoming-samples/jx-data/1021-kanakan-samples/` | 複数Aレコード（バッチ分割） |
| 国分 | 1202 | `incoming-samples/jx-data/1202-kokubu-samples/` | |
| 三菱食品 | 1330 | `incoming-samples/jx-data/1330-mitsubishi-samples/` | |
| 三菱食品（新） | 1330 | `incoming-samples/jx-data/real_data.txt` | **FINETラッパーあり** |

---

## 欠品判定

**品名先頭の「×」マークでは欠品判定しない。** 伝票番号ベースの照合結果で判定する。

1. **伝票番号照合**: `slip_number` で入荷予定とマッチ
2. **欠品判定基準**:
   - 発注した商品が納品データに**存在しない** → 欠品
   - 納品データに存在するが**出荷数量が0**（ケース0 かつ バラ0） → 欠品
   - 出荷数量 < 発注数量 → 一部欠品（PARTIAL）
3. 全ての受信明細データは**全項目を保存**（照合結果に関わらず）

---

## DB変更

### Phase 1: `wms_order_incoming_schedules` に `slip_number` 追加

```sql
ALTER TABLE wms_order_incoming_schedules
    ADD COLUMN slip_number VARCHAR(20) NULL AFTER order_source,
    ADD UNIQUE INDEX idx_slip_number (slip_number);
```

フォーマット: `{YYYYMMDD}-{連番5桁}` 例: `20260305-00001`

### Phase 2: 受信データ3層テーブル

#### `wms_incoming_received_files`（ファイル単位 / Aレコード相当）

```sql
CREATE TABLE wms_incoming_received_files (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    contractor_id BIGINT NOT NULL,
    receive_batch_code VARCHAR(20) NOT NULL,
    source_type ENUM('CSV','JX','FTP') NOT NULL,
    data_type VARCHAR(4) NULL,
    data_created_date DATE NULL,
    data_created_time TIME NULL,
    record_count INT NULL,
    slip_count INT NULL,
    sender_name VARCHAR(30) NULL,
    status ENUM('RECEIVED','MATCHING','MATCHED','APPLIED','ERROR') DEFAULT 'RECEIVED',
    total_slips INT DEFAULT 0,
    matched_slips INT DEFAULT 0,
    shortage_slips INT DEFAULT 0,
    raw_header JSON NULL,
    error_message TEXT NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    INDEX idx_batch(receive_batch_code),
    INDEX idx_contractor(contractor_id),
    INDEX idx_status(status)
);
```

#### `wms_incoming_received_slips`（伝票単位 / Bレコード相当）

```sql
CREATE TABLE wms_incoming_received_slips (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    received_file_id BIGINT NOT NULL,
    slip_number VARCHAR(20) NOT NULL,
    warehouse_code VARCHAR(10) NULL,
    category_code VARCHAR(5) NULL,
    slip_type VARCHAR(4) NULL,
    order_date DATE NULL,
    delivery_date DATE NULL,
    delivery_run VARCHAR(5) NULL,
    contractor_code VARCHAR(10) NULL,
    shop_name VARCHAR(30) NULL,
    delivery_place VARCHAR(20) NULL,
    remark VARCHAR(50) NULL,
    direct_delivery_type VARCHAR(4) NULL,
    incoming_schedule_id BIGINT NULL,
    match_status ENUM('UNMATCHED','MATCHED','PARTIAL_SHORTAGE','FULL_SHORTAGE','NO_SCHEDULE') DEFAULT 'UNMATCHED',
    raw_data BLOB NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    INDEX idx_file(received_file_id),
    INDEX idx_slip_number(slip_number),
    INDEX idx_match(match_status),
    INDEX idx_incoming(incoming_schedule_id)
);
```

#### `wms_incoming_received_details`（明細単位 / Dレコード相当）

```sql
CREATE TABLE wms_incoming_received_details (
    id BIGINT PRIMARY KEY AUTO_INCREMENT,
    received_slip_id BIGINT NOT NULL,
    line_number INT NOT NULL,
    product_name VARCHAR(64) NULL,
    jan_code VARCHAR(13) NULL,
    item_code VARCHAR(10) NULL,
    case_capacity INT DEFAULT 0,
    case_quantity INT DEFAULT 0,
    piece_quantity INT DEFAULT 0,
    unit_price DECIMAL(10,2) DEFAULT 0,
    total_pieces INT DEFAULT 0,
    remark VARCHAR(30) NULL,
    cost_amount DECIMAL(12,2) DEFAULT 0,
    item_id BIGINT NULL,
    is_shortage BOOLEAN DEFAULT FALSE,
    raw_data BLOB NULL,
    created_at TIMESTAMP,
    updated_at TIMESTAMP,
    INDEX idx_slip(received_slip_id),
    INDEX idx_jan(jan_code),
    INDEX idx_item_code(item_code),
    INDEX idx_item(item_id)
);
```

### Phase 2: `wms_contractor_settings` に受信設定カラム追加

```
receive_enabled          BOOLEAN DEFAULT FALSE
receive_type             ENUM('JX_FINET','FTP','MANUAL_CSV') NULL
receive_format_class     VARCHAR(255) NULL
receive_jx_setting_id    BIGINT NULL
receive_ftp_setting_id   BIGINT NULL
receive_time             TIME NULL
is_receive_sun〜sat      BOOLEAN DEFAULT FALSE x7
```

---

## 受信データ処理フロー

```
1. データ受信（JX-FINET or CSVアップロード）
   ↓
2. FINETラッパー除去（JxDataWrapper::hasHeader()/hasFooter()で判定）
   ↓
3. レコードパース（128バイト固定長、Shift_JIS）
   ↓
4. 全データ保存
   - Aレコード → wms_incoming_received_files
   - Bレコード → wms_incoming_received_slips（全項目）
   - Dレコード → wms_incoming_received_details（全項目）
   ↓
5. 照合（伝票番号ベース）
   - slips.slip_number → incoming_schedules.slip_number でマッチ
   - details.item_code / jan_code → items.code で商品特定
   - 照合速度: slip_number, item_code にインデックス必須
   ↓
6. 欠品判定（照合結果ベース、×マークでは判定しない）
   - 発注商品が納品データに存在しない → 欠品
   - 納品データに存在するが出荷数量0 → 欠品
   - 出荷数量 < 発注数量 → 一部欠品
   ↓
7. 担当者確認画面で結果レビュー
   ↓
8. 入荷予定更新（received_quantity 反映）
```

---

## 影響範囲

| 既存機能 | 影響 |
|---|---|
| 入荷予定一覧（`WmsOrderIncomingSchedules`） | slip_numberカラム追加、受信データ反映表示 |
| 入庫確定サービス（`IncomingConfirmationService`） | 受信データ経由の確定フローを追加 |
| 発注先設定画面（`WmsContractorSettings`） | 受信設定セクション追加 |
| JXファイル生成（`HanaOrderJXFileGenerator`） | DB保存済み伝票番号を使用 |
| Handy入庫作業 | 影響なし（既存フロー維持） |
| 会計連携（`IncomingTransmissionService`） | 影響なし |

## 制約

1. **FK禁止**: リレーションはアプリケーション層で管理
2. **migrate:fresh/refresh 禁止**: 新規マイグレーションのみ
3. **受信データは自動適用しない**: 必ず担当者の確認画面を経由
4. **既存の入庫フロー（Handy/Web手動）は維持**
5. **Filament 4パターン準拠**
6. **全受信データの全項目を保存**: 照合結果に関わらず

## 参照実装

| ファイル | 役割 |
|---|---|
| `app/Services/JX/JxDataWrapper.php` | FINETヘッダー/フッターの付与・判定 |
| `app/Services/AutoOrder/Generators/HanaOrderJXFileGenerator.php` | 発注データ（送信側）のA/B/Dレコード生成 |
| `app/Models/WmsOrderJxSetting.php` | JX接続設定 |
| `app/Services/AutoOrder/OrderTransmissionService.php` | 送信側の実装パターン参考 |

## 確認事項

1. **受信対象の発注先**: 初期段階でJX対応する発注先はどれか？（カナカン系 1021/1106等）
2. **顧客別フォーマット**: 同じ国分でも華様・谷口様で異なるファイルの具体的な差異は？
3. **受信データの保持期間**: データは一定期間後に削除するか？
4. **自動適用モード**: 将来的に照合結果を自動適用するモードは必要か？
5. **FTP受信の要件**: 初期はJX+手動CSVのみで十分か？
