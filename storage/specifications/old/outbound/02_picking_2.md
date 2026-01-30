wms_picking_tasksの仕様変更

1. wms_picking_tasksは配送コース別のグルーピングが必要
2. wms_picking_tasksには伝票idは必要ない。
3. 代わりにwms_picking_item_resultsに伝票idが必要(earning_id)
4. wms_picking_tasksには出荷日(shipment_date)が必要
5. wms_picking_item_resultsのstatusにはSHORTAGEはいらない。PENDING(初期状態),PICKING(ピッキング中),COMPLETED（ピッキング完了)の状態のみを持つ
6. wms_picking_item_resultsにはhas_physical_shortage(stored)があるがtrueの条件は 1.status == COMPLETED 2. planed_qty > picked_qty
7. wms_picking_item_resultsにhas_soft_shortageを追加(stored) trueの条件は ordered_qty > planed_qty
8. wms_picking_item_resultsにhas_shortageをついか(stored) trueの条件は has_physical_shortage = true or has_soft_shortage = true
9. 上記の変更とともに、waveの生成時のコードを（在庫引き当て時のロジック）修正する。テスト生成コードも修正する。
- 関連テーブル
wms_picking_tasks
wms_picking_item_results
wms_reservations
wms_waves
wms_real_stocks

- 関連クラス
[GeneratePickerWaveCommand.php](../../../app/Console/Commands/TestData/GeneratePickerWaveCommand.php)
[GenerateTestEarningsCommand.php](../../../app/Console/Commands/TestData/GenerateTestEarningsCommand.php)
[GenerateWmsTestDataCommand.php](../../../app/Console/Commands/TestData/GenerateWmsTestDataCommand.php)
[PickingTaskController.php](../../../app/Http/Controllers/Api/PickingTaskController.php)
