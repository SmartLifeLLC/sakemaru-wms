# JX発注CD監査 2026-05-06

本番AuroraをSELECTのみで確認。送信済みJXドキュメント全件を対象に、発注候補のordering_codeと商品検索マスタの発注用CDを比較。

## サマリー

- 送信済みJXドキュメント: 71 件
- 明細ありドキュメント: 38 件
- 空ファイル: 33 件
- 明細行: 159 行
- エラー候補行: 33 行
- 空/ゼロ送信かつ正しい発注CDあり: 31 行
- 送信CDと発注用CDの不一致: 2 行
- マスタ上も発注CD候補なし: 0 行
- エラー候補を含むファイル: 22 件

## 出力ファイル

- 明細比較CSV: `/Users/jungsinyu/Projects/sakemaru-wms/storage/reports/jx-order-code-audit/jx_order_cd_comparison_20260506.csv`
- ファイル別サマリーCSV: `/Users/jungsinyu/Projects/sakemaru-wms/storage/reports/jx-order-code-audit/jx_order_cd_summary_20260506.csv`
