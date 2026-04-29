# 発注候補生成 統合テスト結果

- **実行日時**: 2026-04-22 02:20:51
- **実行者ID**: 9900000003
- **対象倉庫数**: 32

## サマリ

| 結果 | 件数 |
|------|------|
| PASS | 29 |
| FAIL | 0 |
| SKIP | 0 |
| 合計 | 29 |

## 全テスト結果

| # | ケース | 結果 | 実測値 | 期待値 | 詳細 |
|---|--------|------|--------|--------|------|
| A1 | 安全在庫ON・在庫不足→候補生成 | PASS | 1242 | >0 | 発注:981, 移動:261 |
| A2 | 在庫十分→スキップ（calculated_shortage_qty<=0なし） | PASS | 0 | 0 | calculated_shortage_qty<=0のレコード数 |
| A7-order | 販売終了品→発注候補なし | PASS | 0 | 0 |  |
| A7-transfer | 販売終了品→移動候補なし | PASS | 0 | 0 |  |
| A8 | 販売開始前→候補なし | PASS | 0 | 0 |  |
| A1-excl | 安全在庫ベース→is_auto_order=falseなし | PASS | 0 | 0 |  |
| B1-B3 | 数量計算（不足数・切り上げ・仕入単位） | PASS | 10 | 10 OK |  |
| C1 | INTERNAL候補→transmission_type=INTERNALのみ | PASS | 0 | 0 |  |
| C2 | EXTERNAL候補→INTERNAL発注先なし | PASS | 0 | 0 |  |
| E1 | origin_type=MANUAL_SAFETY_STOCK | PASS | MANUAL_SAFETY_STOCK | MANUAL_SAFETY_STOCK |  |
| E1-transfer | origin_type(移動)=MANUAL_SAFETY_STOCK | PASS | MANUAL_SAFETY_STOCK | MANUAL_SAFETY_STOCK |  |
| F1 | 発注CD=search_string(13桁パディング)一致 | PASS | 0 | 0 |  |
| F2 | 発注CD=null(is_used_for_ordering=false) | PASS | 0 | 0 |  |
| F3 | 発注CD=13桁 | PASS | 0 | 0 |  |
| G1-order | 発注候補重複なし(item×wh×contractor) | PASS | 0 | 0 |  |
| G1-transfer | 移動候補重複なし(item×wh×contractor) | PASS | 0 | 0 |  |
| A3 | 実績ベース→候補生成 | PASS | 722 | >0 | 発注:678, 移動:44 |
| A3-excl | 実績ベース→is_auto_order=trueなし | PASS | 0 | 0 |  |
| A3-excl-t | 実績ベース移動→is_auto_order=trueなし | PASS | 0 | 0 |  |
| A5 | 発注OFF・安全在庫あり→実績ベースに含まれる | PASS | 23 | >=0 (データ依存) | safety_stock>0 かつ is_auto_order=false: 23件 |
| A6 | 発注ON・安全在庫ゼロ→実績ベース対象外 | PASS | 0 | 0 | A3-exclと同一チェック |
| B5-B6 | 実績ベース数量計算(suggested/order_qty整合性) | PASS | 10 | 10 OK |  |
| E2 | origin_type=MANUAL_SALES_BASED | PASS | MANUAL_SALES_BASED | MANUAL_SALES_BASED |  |
| G1-sales | 実績ベース発注候補重複なし | PASS | 0 | 0 |  |
| D1 | 安全在庫→実績: 同一batch_code | PASS | 20260422022019000 | 20260422022019000 |  |
| G2 | 安全在庫+実績で同一商品重複なし | PASS | 0 | 0 | is_auto_orderで排他 |
| D2 | 実績のみ→新規batch_code | PASS | 20260422022029000 | 新規(≠20260422022019000) |  |
| D3 | 2回連続実績→同一batch_code | PASS | 20260422022035000/20260422022035000/20260422022035000 | 全一致 |  |
| E-extra | origin_type値域チェック | PASS | 0 | 0 | 許容: MANUAL_SAFETY_STOCK, MANUAL_SALES_BASED |

## 分析

全テストケースが PASS しました。
