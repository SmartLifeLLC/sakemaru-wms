# 発注先の設定変更

現状のadmin/wms-warehouse-contractor-settingsを廃止する。
理由はcontractor-settingsは倉庫別に管理する必要がないため。
wms_warehouse_contractor_settingsテーブル を削除
warehouse_contractorsテーブルも必要なくなったと思う。確認が必要。

自動発注では以下のながれがベースになる。
1. 商品の補充が必要かを判断
2. 商品の補充が倉庫からを判断
3. 倉庫補充を加味して、商品補充を判断（補充倉庫の商品について）
4. 補充対象の仕入れ先ー＞発注先を把握
5. 発注先別に発注リストを生成
6. 発注先が自動発注だった場合には自動発注を実施


/Users/jungsinyu/Projects/smart-wms/storage/specifications/auto-ordering　の確認と
現在の実装内容の確認をし上記対応を実施する。
