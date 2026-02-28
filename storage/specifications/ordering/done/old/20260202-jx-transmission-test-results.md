# JX送信テスト結果レポート

作成日: 2026-02-02
テスト実施日: 2026-02-02

## テスト概要

4社の問屋向けJXファイル生成・送信機能のテストを実施した。
ローカル環境で「ファイル生成 → 確認 → 送信 → テストサーバ受信確認」の一連のフローを検証。

## 実装成果物

### 1. Artisanコマンド

```bash
php artisan wms:generate-jx-test-files
```

**オプション:**
- `--pattern=<empty|full|aggregated|all>` - テストパターン選択
- `--jx-setting=<ID>` - 特定のJX設定を指定
- `--warehouse=<ID>` - 倉庫ID指定
- `--max-items=<N>` - 最大商品数（デフォルト: 50）
- `--transmit` - 生成後にJX送信を実行
- `--dry-run` - ファイル保存なしでシミュレーション
- `--list` - JX設定一覧を表示

### 2. テストファイル生成サービス

`App\Services\JX\JxTestFileGenerator`
- `generateEmptyFile($jxSettingId)` - 空ファイル生成
- `generateFullOrderFile($jxSettingId, $warehouseId, $maxItems)` - 全商品ファイル生成
- `generateAggregatedFile($jxSettingId, $warehouseId, $maxItemsPerContractor)` - 集約テストファイル生成
- `transmitFile($jxSettingId, $content)` - JX送信実行

## テスト結果

### JX設定一覧

| ID | 名前 | 送信元 | 送信先 | エンドポイント | 結果 |
|----|------|--------|--------|----------------|------|
| 1 | カナカン株式会社 | 420701 | MA166F | https://wms.sakemaru.test/jx-server | OK |
| 2 | 三菱食品 | 420701 | MA0807 | https://wms.sakemaru.test/jx-server | OK |
| 3 | 北陸コカ・コーラボトリング株式会社 | 420701 | MB65D7 | https://wms.sakemaru.test/jx-server | OK |
| 4 | K＆K国分中部株式会社 | 420701 | EA0683 | https://wms.sakemaru.test/jx-server | OK |

### パターン1: 空ファイル送信テスト

| JX設定 | ファイル名 | サイズ | レコード数 | 送信結果 |
|--------|-----------|--------|-----------|----------|
| カナカン | jx_test_1_empty_*.dat | 130 bytes | 1 (Aレコードのみ) | 成功 |

**確認項目:**
- [x] 空ファイルが正しく生成されること
- [x] JX送信が正常に完了すること
- [x] テストサーバで受信できること

### パターン2: 全商品発注ファイル

| JX設定 | ファイル名 | サイズ | レコード数 | 発注数 | 送信結果 |
|--------|-----------|--------|-----------|--------|----------|
| カナカン | jx_test_1_full_*.dat | 2,434 bytes | 19 | 15 | 成功 |
| 三菱食品 | jx_test_2_full_*.dat | 1,666 bytes | 13 | 10 | 成功 |
| 北陸コカ・コーラ | jx_test_3_full_*.dat | 1,666 bytes | 13 | 10 | 成功 |
| 国分中部 | jx_test_4_full_*.dat | 1,666 bytes | 13 | 10 | 成功 |

**確認項目:**
- [x] 全商品が含まれていること
- [x] レコード形式（A/B/D）が正しいこと
- [x] Shift_JIS、128バイト固定長であること

### パターン3: 送信先集約テスト（カナカンケース）

| JX設定 | ファイル名 | サイズ | レコード数 | 発注数 | 集約発注先 |
|--------|-----------|--------|-----------|--------|-----------|
| カナカン | jx_test_1_aggregated_*.dat | 3,202 bytes | 25 | 18 | 1106, 1021, 1029, 1126, 1127, 1680 |

**確認項目:**
- [x] 1つのファイルに複数発注先のBレコードが含まれること
- [x] 各発注先の商品が正しくDレコードに出力されること
- [x] 取引先コード（Bレコード39-42桁）が各発注先のコードであること

## ファイルフォーマット検証

### Aレコード（ファイルヘッダー）

```
位置   内容            サンプル値
1      レコード区分    A
2-3    データ種別      01
4-11   処理日付        20260202
12-17  処理時刻        025547
18-25  送信元          00420701
26-33  送信先          00MA166F
34-39  レコード件数    000025
40-45  帳票枚数        000006
46-60  社名            ﾘｶｰﾜｰﾙﾄﾞ ﾊﾅ
61-128 FILLER          (空白)
```

### Bレコード（伝票ヘッダー）

```
位置   内容            サンプル値
1      レコード区分    B
2-3    データ種別      01
4-14   伝票番号        20260202001
15-18  社・店コード    0001
19-21  分類コード      999
22-23  伝票区分        01
24-29  発注日          260202
30-35  納品日          260204
36-38  便              (空白)
39-42  取引先コード    1106
43-57  店名            ﾎﾝﾃﾝ
58-67  納品場所        ﾎﾝﾃﾝ
68-92  備考            (空白)
93-94  直送区分        00
95-128 FILLER          (空白)
```

### Dレコード（伝票明細）

```
位置    内容            サンプル値
1       レコード区分    D
2-3     データ種別      01
4-5     行番号          01
6-69    品名            カゴメ 100CAN オレンジジュース160ml
70-82   JANコード       4901306083604
83-88   自社コード      213053
89-94   仕入入数        000030
95-101  ケース数        0000000
102-108 バラ数量        0000003
109-118 原単価          0000294000
119-128 FILLER          (空白)
```

## 送信ログ確認

```sql
SELECT id, jx_setting_id, operation_type, status, message_id
FROM wms_jx_transmission_logs
ORDER BY created_at DESC LIMIT 10;
```

| ID | JX設定ID | 操作タイプ | ステータス | メッセージID |
|----|---------|-----------|-----------|--------------|
| 8 | 1 | PutDocument | success | put_697f93a3bf6de_* |
| 7 | 1 | PutDocument | success | put_697f93a3b143c_* |
| 6 | 1 | PutDocument | success | put_697f93a3934ed_* |
| 5 | 4 | PutDocument | success | put_697f929248100_* |
| 4 | 3 | PutDocument | success | put_697f92921ad76_* |
| 3 | 2 | PutDocument | success | put_697f9291e846c_* |
| 2 | 1 | PutDocument | success | put_697f9291b553b_* |
| 1 | 1 | PutDocument | success | put_697f9265496c0_* |

## テストサーバ受信確認

受信ファイル一覧（storage/app/private/jx-server/documents/2026-02-02/）:

```
-rw-r--r--  386 01_put_697f9265496c0_*.txt  (empty test)
-rw-r--r-- 1922 01_put_697f9291b553b_*.txt  (full test 1)
-rw-r--r-- 1922 01_put_697f9291e846c_*.txt  (full test 2)
-rw-r--r-- 1922 01_put_697f92921ad76_*.txt  (full test 3)
-rw-r--r-- 1922 01_put_697f929248100_*.txt  (full test 4)
-rw-r--r--  386 01_put_697f93a3934ed_*.txt  (empty)
-rw-r--r-- 2690 01_put_697f93a3b143c_*.txt  (full)
-rw-r--r-- 3458 01_put_697f93a3bf6de_*.txt  (aggregated)
```

## 結論

全てのテストパターンで以下が確認できた:

1. **ファイル生成**: 正しいフォーマット（Shift_JIS、128バイト固定長）で生成
2. **送信**: JX-FINETプロトコルでテストサーバへ正常送信
3. **受信**: テストサーバで正しく受信・保存

## 本番移行前チェックリスト

- [ ] endpoint_urlの変更（本番URLへ）
- [ ] SSL証明書の設定確認
- [ ] Basic認証情報の確認
- [ ] 送信先（receiver_station_code）の確認
- [ ] 本番環境での疎通テスト

## 使用方法

```bash
# JX設定一覧を確認
php artisan wms:generate-jx-test-files --list

# 特定JX設定で空ファイルテスト
php artisan wms:generate-jx-test-files --pattern=empty --jx-setting=1 --transmit

# 全JX設定で全商品ファイル送信
php artisan wms:generate-jx-test-files --pattern=full --transmit

# カナカンの集約テスト
php artisan wms:generate-jx-test-files --pattern=aggregated --jx-setting=1 --transmit

# ドライランで確認（送信なし）
php artisan wms:generate-jx-test-files --pattern=all --dry-run
```
