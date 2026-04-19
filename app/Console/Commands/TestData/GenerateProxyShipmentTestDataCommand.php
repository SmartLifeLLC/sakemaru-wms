<?php

namespace App\Console\Commands\TestData;

use App\Domains\Sakemaru\SakemaruEarning;
use App\Models\Sakemaru\ClientSetting;
use App\Models\Wave;
use App\Models\WaveSetting;
use App\Models\WmsPickingItemResult;
use App\Models\WmsPickingTask;
use App\Models\WmsShortage;
use App\Models\WmsShortageAllocation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class GenerateProxyShipmentTestDataCommand extends Command
{
    protected $signature = 'testdata:proxy-shipment
                            {--count=3 : Number of test earnings to generate}
                            {--shortage-warehouse-id= : Warehouse where shortage occurs (source_warehouse_id)}
                            {--proxy-warehouse-id= : Warehouse where stock exists for proxy shipment (target_warehouse_id)}
                            {--delivery-course-id= : Delivery course ID}';

    protected $description = 'Generate proxy shipment test data (earnings → wave → shortage → allocation → confirm)';

    public function handle(): int
    {
        $shortageWarehouseId = (int) $this->option('shortage-warehouse-id');
        $proxyWarehouseId = (int) $this->option('proxy-warehouse-id');
        $deliveryCourseId = (int) $this->option('delivery-course-id');
        $count = (int) $this->option('count');

        if (! $shortageWarehouseId || ! $proxyWarehouseId || ! $deliveryCourseId) {
            $this->error('All options --shortage-warehouse-id, --proxy-warehouse-id, --delivery-course-id are required.');

            return 1;
        }

        if ($shortageWarehouseId === $proxyWarehouseId) {
            $this->error('Shortage warehouse and proxy warehouse must be different.');

            return 1;
        }

        $this->info('横持ち出荷テストデータ生成を開始します...');
        $this->newLine();

        // Step 1: Find items with stock at proxy warehouse
        $items = $this->findItemsWithStockAtProxy($proxyWarehouseId);
        if ($items->isEmpty()) {
            $this->error("横持ち出荷倉庫(ID:{$proxyWarehouseId})に在庫のある商品が見つかりません");

            return 1;
        }
        $this->info("Step 1: 横持ち出荷倉庫に在庫のある商品 {$items->count()} 件を検出");

        // Step 2: Get warehouse/course info
        $shortageWarehouse = DB::connection('sakemaru')->table('warehouses')->find($shortageWarehouseId);
        $proxyWarehouse = DB::connection('sakemaru')->table('warehouses')->find($proxyWarehouseId);
        $deliveryCourse = DB::connection('sakemaru')->table('delivery_courses')->find($deliveryCourseId);

        if (! $shortageWarehouse || ! $proxyWarehouse || ! $deliveryCourse) {
            $this->error('倉庫または配送コースが見つかりません');

            return 1;
        }

        // Step 3: Generate earnings via API
        $this->info('Step 2: 売上データを生成中...');
        $earningIds = $this->generateEarnings(
            $items,
            $shortageWarehouse,
            $deliveryCourse,
            $count
        );

        if (empty($earningIds)) {
            $this->error('売上データの生成に失敗しました');

            return 1;
        }
        $this->info("  売上 {$count} 件を生成");

        // Step 4: Create wave infrastructure
        $this->info('Step 3: Wave・ピッキングタスクを生成中...');
        $waveData = $this->createWaveInfrastructure(
            $earningIds,
            $shortageWarehouseId,
            $deliveryCourseId,
            $shortageWarehouse,
            $deliveryCourse
        );
        $this->info("  Wave {$waveData['wave_count']} 件、ピッキングタスク {$waveData['task_count']} 件を生成");

        // Step 5: Create shortages and proxy shipment allocations
        $this->info('Step 4: 欠品・横持ち出荷指示を生成中...');
        $shortageData = $this->createShortagesAndAllocations(
            $waveData['pick_results'],
            $shortageWarehouseId,
            $proxyWarehouseId,
            $deliveryCourseId
        );
        $this->info("  欠品 {$shortageData['shortage_count']} 件、横持ち出荷指示 {$shortageData['allocation_count']} 件を生成");

        // Summary
        $this->newLine();
        $this->info('=== 生成完了 ===');
        $this->table(
            ['項目', '値'],
            [
                ['欠品発生倉庫', "[{$shortageWarehouse->code}] {$shortageWarehouse->name}"],
                ['横持ち出荷倉庫', "[{$proxyWarehouse->code}] {$proxyWarehouse->name}"],
                ['配送コース', "[{$deliveryCourse->code}] {$deliveryCourse->name}"],
                ['売上伝票数', count($earningIds)],
                ['欠品数', $shortageData['shortage_count']],
                ['横持ち出荷指示数', $shortageData['allocation_count']],
                ['出荷日', ClientSetting::systemDate()->format('Y-m-d')],
                ['ステータス', 'RESERVED（ピッキング可能）'],
            ]
        );

        return 0;
    }

    /**
     * 横持ち出荷倉庫（proxy）に在庫のある商品を取得
     */
    private function findItemsWithStockAtProxy(int $proxyWarehouseId)
    {
        return DB::connection('sakemaru')
            ->table('items as i')
            ->join('real_stocks as rs', 'i.id', '=', 'rs.item_id')
            ->join('real_stock_lots as rsl', 'rs.id', '=', 'rsl.real_stock_id')
            ->where('i.type', 'ALCOHOL')
            ->where('i.is_active', true)
            ->whereNull('i.end_of_sale_date')
            ->where('i.is_ended', false)
            ->where('rs.warehouse_id', $proxyWarehouseId)
            ->where('rsl.current_quantity', '>', 0)
            ->where('rsl.status', 'ACTIVE')
            ->select(
                'i.id',
                'i.code',
                'i.name',
                'i.capacity_case',
                DB::raw('SUM(rsl.current_quantity) as total_available')
            )
            ->groupBy('i.id', 'i.code', 'i.name', 'i.capacity_case')
            ->having('total_available', '>', 0)
            ->limit(30)
            ->get();
    }

    /**
     * API経由で売上データを生成
     */
    private function generateEarnings($items, $shortageWarehouse, $deliveryCourse, int $count): array
    {
        $processDate = ClientSetting::systemDate()->format('Y-m-d');

        // Get eligible buyers
        $buyers = DB::connection('sakemaru')
            ->table('buyer_details as bd')
            ->join('buyers as b', 'bd.buyer_id', '=', 'b.id')
            ->join('partners as p', 'b.partner_id', '=', 'p.id')
            ->where('bd.is_active', 1)
            ->where('bd.can_register_earnings', 1)
            ->where('bd.is_allowed_case_quantity', 1)
            ->where('p.is_active', 1)
            ->where('p.is_supplier', 0)
            ->whereNull('p.end_of_trade_date')
            ->select(['p.code as buyer_code'])
            ->distinct()
            ->limit($count)
            ->get();

        if ($buyers->isEmpty()) {
            $this->error('適格な得意先が見つかりません');

            return [];
        }

        $earnings = [];
        $usedBuyerCodes = [];

        for ($i = 0; $i < $count; $i++) {
            $buyer = $buyers->filter(fn ($b) => ! in_array($b->buyer_code, $usedBuyerCodes))->first();
            if (! $buyer) {
                break;
            }
            $usedBuyerCodes[] = $buyer->buyer_code;

            // Select 3-5 items randomly
            $itemCount = min(rand(3, 5), $items->count());
            $selectedItems = $items->random($itemCount);

            $details = [];
            foreach ($selectedItems as $item) {
                $capacityCase = $item->capacity_case ?? 12;
                $qty = rand(1, 3);

                $details[] = [
                    'item_code' => $item->code,
                    'quantity' => $qty,
                    'quantity_type' => 'CASE',
                    'order_quantity' => $qty,
                    'order_quantity_type' => 'CASE',
                    'price' => rand(500, 3000),
                    'note' => "横持ちテスト CASE {$qty}",
                ];
            }

            $earnings[] = [
                'process_date' => $processDate,
                'delivered_date' => $processDate,
                'account_date' => $processDate,
                'buyer_code' => $buyer->buyer_code,
                'warehouse_code' => $shortageWarehouse->code,
                'delivery_course_code' => $deliveryCourse->code,
                'note' => "横持ち出荷テスト: 伝票#{$i}",
                'is_delivered' => false,
                'is_returned' => false,
                'details' => $details,
            ];

            $this->line("  [{$i}] 得意先: {$buyer->buyer_code}, 商品数: ".count($details));
        }

        if (empty($earnings)) {
            return [];
        }

        // Call API
        $response = SakemaruEarning::postData(['earnings' => $earnings]);

        if (! isset($response['success']) || ! $response['success']) {
            $this->error('API error: '.($response['debug_message'] ?? $response['message'] ?? 'Unknown'));

            return [];
        }

        // Fetch the created earnings (note is on trades table, not earnings)
        $earningIds = DB::connection('sakemaru')
            ->table('earnings as e')
            ->join('trades as t', 'e.trade_id', '=', 't.id')
            ->where('e.warehouse_id', $shortageWarehouse->id)
            ->where('e.delivery_course_id', $deliveryCourse->id)
            ->where('e.delivered_date', $processDate)
            ->where('t.note', 'like', '横持ち出荷テスト:%')
            ->orderBy('e.id', 'desc')
            ->limit($count)
            ->pluck('e.id')
            ->toArray();

        return $earningIds;
    }

    /**
     * Wave + PickingTask + PickingItemResult を生成
     */
    private function createWaveInfrastructure(
        array $earningIds,
        int $shortageWarehouseId,
        int $deliveryCourseId,
        $shortageWarehouse,
        $deliveryCourse
    ): array {
        $processDate = ClientSetting::systemDate()->format('Y-m-d');
        $pickResults = [];

        return DB::connection('sakemaru')->transaction(function () use (
            $earningIds,
            $shortageWarehouseId,
            $deliveryCourseId,
            $shortageWarehouse,
            $deliveryCourse,
            $processDate,
            &$pickResults
        ) {
            // WaveSetting: reuse existing or create
            $waveSetting = WaveSetting::where('delivery_course_id', $deliveryCourseId)->first();
            if (! $waveSetting) {
                $waveSetting = WaveSetting::create([
                    'name' => "横持ちテスト [{$deliveryCourse->code}] {$deliveryCourse->name}",
                    'delivery_course_id' => $deliveryCourseId,
                    'picking_start_time' => '09:00:00',
                    'picking_deadline_time' => '17:00:00',
                    'creator_id' => 1,
                    'last_updater_id' => 1,
                ]);
            }

            // Wave
            $wave = Wave::create([
                'wms_wave_setting_id' => $waveSetting->id,
                'wave_no' => Wave::generateWaveNo(
                    (int) $shortageWarehouse->code,
                    (int) $deliveryCourse->code,
                    $processDate,
                    time()
                ),
                'shipping_date' => $processDate,
                'status' => 'PICKING',
            ]);

            // PickingTask
            $task = WmsPickingTask::create([
                'wave_id' => $wave->id,
                'warehouse_id' => $shortageWarehouseId,
                'warehouse_code' => $shortageWarehouse->code,
                'delivery_course_id' => $deliveryCourseId,
                'delivery_course_code' => $deliveryCourse->code,
                'shipment_date' => $processDate,
                'status' => WmsPickingTask::STATUS_SHORTAGE,
                'task_type' => 'WAVE',
            ]);

            // PickingItemResults (shortage: picked_qty = 0)
            $walkingOrder = 0;
            foreach ($earningIds as $earningId) {
                $earning = DB::connection('sakemaru')->table('earnings')->find($earningId);
                if (! $earning) {
                    continue;
                }

                $tradeItems = DB::connection('sakemaru')
                    ->table('trade_items')
                    ->where('trade_id', $earning->trade_id)
                    ->get();

                foreach ($tradeItems as $tradeItem) {
                    $walkingOrder++;
                    $orderQty = (int) ($tradeItem->order_quantity ?? $tradeItem->quantity ?? 1);
                    $orderQtyType = $tradeItem->order_quantity_type ?? $tradeItem->quantity_type ?? 'CASE';

                    $pickResult = WmsPickingItemResult::create([
                        'picking_task_id' => $task->id,
                        'earning_id' => $earningId,
                        'trade_id' => $earning->trade_id,
                        'trade_item_id' => $tradeItem->id,
                        'item_id' => $tradeItem->item_id,
                        'walking_order' => $walkingOrder,
                        'ordered_qty' => $orderQty,
                        'ordered_qty_type' => $orderQtyType,
                        'planned_qty' => 0,  // No stock → no allocation
                        'planned_qty_type' => $orderQtyType,
                        'picked_qty' => 0,   // No stock → nothing picked
                        'picked_qty_type' => $orderQtyType,
                        'shortage_qty' => $orderQty,
                        'is_ready_to_shipment' => false,
                        'status' => WmsPickingItemResult::STATUS_SHORTAGE,
                        'source_type' => 'EARNING',
                    ]);

                    $pickResults[] = $pickResult;
                }
            }

            return [
                'wave_count' => 1,
                'task_count' => 1,
                'pick_results' => $pickResults,
            ];
        });
    }

    /**
     * 欠品レコード + 横持ち出荷指示を生成 + 確定
     */
    private function createShortagesAndAllocations(
        array $pickResults,
        int $shortageWarehouseId,
        int $proxyWarehouseId,
        int $deliveryCourseId
    ): array {
        $shortageCount = 0;
        $allocationCount = 0;

        DB::connection('sakemaru')->transaction(function () use (
            $pickResults,
            $shortageWarehouseId,
            $proxyWarehouseId,
            $deliveryCourseId,
            &$shortageCount,
            &$allocationCount
        ) {
            foreach ($pickResults as $pickResult) {
                $task = $pickResult->pickingTask;
                $earning = DB::connection('sakemaru')->table('earnings')->find($pickResult->earning_id);

                // Create shortage
                $shortage = WmsShortage::create([
                    'wave_id' => $task->wave_id,
                    'shipment_date' => $earning->delivered_date ?? now()->format('Y-m-d'),
                    'warehouse_id' => $shortageWarehouseId,
                    'item_id' => $pickResult->item_id,
                    'trade_id' => $pickResult->trade_id,
                    'earning_id' => $pickResult->earning_id,
                    'delivery_course_id' => $deliveryCourseId,
                    'trade_item_id' => $pickResult->trade_item_id,
                    'order_qty' => $pickResult->ordered_qty,
                    'planned_qty' => 0,
                    'picked_qty' => 0,
                    'shortage_qty' => $pickResult->ordered_qty,
                    'allocation_shortage_qty' => $pickResult->ordered_qty,
                    'picking_shortage_qty' => 0,
                    'qty_type_at_order' => $pickResult->ordered_qty_type,
                    'case_size_snap' => $pickResult->item?->capacity_case ?? 12,
                    'source_pick_result_id' => $pickResult->id,
                    'status' => WmsShortage::STATUS_SHORTAGE,
                    'is_confirmed' => true,
                    'confirmed_at' => now(),
                    'confirmed_user_id' => 1,
                    'reason_code' => WmsShortage::REASON_NO_STOCK,
                ]);
                $shortageCount++;

                // Create proxy shipment allocation (confirmed + RESERVED)
                WmsShortageAllocation::create([
                    'shortage_id' => $shortage->id,
                    'shipment_date' => $shortage->shipment_date,
                    'delivery_course_id' => $deliveryCourseId,
                    'target_warehouse_id' => $proxyWarehouseId,   // 出荷元倉庫（在庫がある倉庫）
                    'source_warehouse_id' => $shortageWarehouseId, // 欠品発生倉庫
                    'assign_qty' => $pickResult->ordered_qty,
                    'assign_qty_type' => $pickResult->ordered_qty_type,
                    'status' => WmsShortageAllocation::STATUS_RESERVED,
                    'is_confirmed' => true,
                    'confirmed_at' => now(),
                    'confirmed_user_id' => 1,
                    'created_by' => 1,
                ]);
                $allocationCount++;

                // Update picking item result
                $pickResult->update([
                    'shortage_allocated_qty' => $pickResult->ordered_qty,
                    'shortage_allocated_qty_type' => $pickResult->ordered_qty_type,
                    'is_ready_to_shipment' => true,
                    'shipment_ready_at' => now(),
                ]);
            }
        });

        return [
            'shortage_count' => $shortageCount,
            'allocation_count' => $allocationCount,
        ];
    }
}
