<?php

namespace Tests\Feature\Services;

use App\Models\WaveGroup;
use App\Services\LotAdjustment\LotAdjustmentRunner;
use App\Services\PickingList\PickingListPdfService;
use App\Services\PickingList\PickingListService;
use App\Services\StockAllocationService;
use App\Services\StockTransferLotAllocationService;
use App\Services\WaveGroupGenerationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\WaveAllocationFixtures;
use Tests\TestCase;

class WavePickingListGenerationTest extends TestCase
{
    use WaveAllocationFixtures;

    private string $outputRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixtureWarehouseId = 99000091;
        $this->setUpAllocationDatabase();
        $pdo = DB::connection('sakemaru')->getPdo();
        $mysql = DB::connection('sakemaru')->getDriverName() === 'mysql';
        if (! $mysql) {
            $pdo->sqliteCreateFunction('CONCAT', fn (...$parts) => implode('', $parts));
        }
        foreach ([
            'items' => ['name TEXT'],
            'locations' => ['code2 TEXT', 'code3 TEXT', 'temperature_type TEXT', 'is_restricted_area INTEGER DEFAULT 0'],
            'earnings' => ['delivery_course_id INTEGER', 'buyer_id INTEGER', 'delivered_date TEXT', 'updated_at TEXT'],
            'stock_transfers' => ['delivery_course_id INTEGER', 'from_warehouse_id INTEGER', 'to_warehouse_id INTEGER', 'picking_date TEXT', 'delivered_date TEXT'],
            'trades' => ['created_at TEXT', 'is_active INTEGER DEFAULT 1', 'partner_id INTEGER', 'slip_number TEXT'],
            'wms_picking_tasks' => ['temperature_type TEXT', 'is_restricted_area INTEGER DEFAULT 0'],
            'wms_picking_item_results' => ['is_ready_to_shipment INTEGER'],
        ] as $table => $columns) {
            foreach ($columns as $column) {
                $columnName = explode(' ', $column)[0];
                if (! $mysql || ! $pdo->query("SHOW COLUMNS FROM {$table} LIKE '{$columnName}'")->fetch()) {
                    $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column}");
                }
            }
        }
        foreach ([
            'warehouses' => 'code INTEGER, name TEXT, is_virtual INTEGER DEFAULT 0, stock_warehouse_id INTEGER',
            'delivery_courses' => 'warehouse_id INTEGER, code INTEGER, name TEXT, is_active INTEGER DEFAULT 1',
            'wms_wave_groups' => 'group_no TEXT, warehouse_id INTEGER, shipping_date TEXT, generation_type TEXT, target_document_types TEXT, conditions TEXT, generation_result TEXT, picking_lists TEXT, created_by INTEGER, created_at TEXT, updated_at TEXT',
            'wms_waves' => 'wave_group_id INTEGER, wms_wave_setting_id INTEGER, wave_no TEXT, shipping_date TEXT, status TEXT, print_count INTEGER DEFAULT 0, created_at TEXT, updated_at TEXT',
            'wms_wave_settings' => 'delivery_course_id INTEGER, picking_start_time TEXT, picking_deadline_time TEXT, creator_id INTEGER, last_updater_id INTEGER, created_at TEXT, updated_at TEXT',
            'wms_picking_areas' => 'warehouse_id INTEGER, is_active INTEGER, display_order INTEGER',
            'floors' => 'name TEXT',
            'partners' => 'code INTEGER, name TEXT',
            'buyers' => 'partner_id INTEGER',
            'buyer_details' => 'buyer_id INTEGER, salesman_id INTEGER, start_date TEXT',
            'users' => 'name TEXT',
            'item_incoming_default_locations' => 'warehouse_id INTEGER, item_id INTEGER, location_id INTEGER',
            'srh_searchable_items' => 'item_id INTEGER, jancode TEXT',
            'wms_shortages' => 'wave_id INTEGER, warehouse_id INTEGER, shipment_date TEXT, delivery_course_id INTEGER, location_id INTEGER, item_id INTEGER, trade_id INTEGER, trade_item_id INTEGER, earning_id INTEGER, source_pick_result_id INTEGER, order_qty INTEGER, planned_qty INTEGER, picked_qty INTEGER, shortage_qty INTEGER, allocation_shortage_qty INTEGER, picking_shortage_qty INTEGER, qty_type_at_order TEXT, case_size_snap INTEGER, status TEXT, reason_code TEXT, note TEXT, is_confirmed INTEGER DEFAULT 0, is_synced INTEGER DEFAULT 0, created_at TEXT, updated_at TEXT',
        ] as $table => $columns) {
            if ($mysql) {
                $pdo->exec("CREATE TABLE IF NOT EXISTS {$table} (id BIGINT PRIMARY KEY AUTO_INCREMENT, {$columns}) ENGINE=InnoDB");
                $pdo->exec("TRUNCATE TABLE {$table}");
            } else {
                $pdo->exec("CREATE TABLE {$table} (id INTEGER PRIMARY KEY AUTOINCREMENT, {$columns})");
            }
        }
        $db = DB::connection('sakemaru');
        if ($mysql) {
            // Enforce the critical live production enum and idempotency constraints.
            $pdo->exec("ALTER TABLE wms_reservations MODIFY status ENUM('RESERVED','PARTIAL','RELEASED','CONSUMED','CANCELLED','SHORTAGE'), MODIFY qty_type ENUM('CASE','PIECE','CARTON'), MODIFY source_type ENUM('EARNING','PURCHASE','REPLENISH','COUNT','MOVE','STOCK_TRANSFER') NOT NULL");
            $pdo->exec("ALTER TABLE wms_picking_item_results MODIFY ordered_qty_type ENUM('CASE','PIECE','CARTON') NOT NULL, MODIFY planned_qty_type ENUM('CASE','PIECE','CARTON') NOT NULL, MODIFY picked_qty_type ENUM('CASE','PIECE','CARTON') NOT NULL");
            $pdo->exec("ALTER TABLE wms_shortages MODIFY qty_type_at_order ENUM('CASE','PIECE','CARTON') NOT NULL, MODIFY reason_code ENUM('NONE','NO_STOCK','DAMAGED','MISSING_LOC','OTHER')");
            if (! $pdo->query("SHOW INDEX FROM wms_reservations WHERE Key_name = 'uniq_wres_idem'")->fetch()) {
                $pdo->exec('ALTER TABLE wms_reservations ADD UNIQUE KEY uniq_wres_idem (wave_id, item_id, real_stock_id, location_id, source_id, source_line_id, status)');
            }
            $db->beginTransaction();
        }
        $db->table('warehouses')->insert([
            ['id' => $this->fixtureWarehouseId, 'code' => 91, 'name' => '検証用 華むすびの蔵センター'],
            ['id' => 3, 'code' => 3, 'name' => '坂井店'],
            ['id' => 10, 'code' => 10, 'name' => '敦賀店'],
            ['id' => 22, 'code' => 22, 'name' => '小浜店'],
        ]);
        $db->table('delivery_courses')->insert([
            ['id' => 1, 'warehouse_id' => $this->fixtureWarehouseId, 'code' => 1, 'name' => '後の注文・コース1'],
            ['id' => 9, 'warehouse_id' => $this->fixtureWarehouseId, 'code' => 9, 'name' => '先の注文・コース9'],
        ]);
        $db->table('wms_picking_areas')->insert(['id' => 1, 'warehouse_id' => $this->fixtureWarehouseId, 'is_active' => true, 'display_order' => 1]);
        $db->table('floors')->insert([['id' => 1, 'name' => '1F'], ['id' => 2, 'name' => '2F']]);
        $db->table('partners')->insert(['id' => 1, 'code' => 123, 'name' => '検証用得意先']);
        $db->table('buyers')->insert(['id' => 1, 'partner_id' => 1]);
        $this->outputRoot = (getenv('WMS_REVIEW_OUTPUT') ?: sys_get_temp_dir().'/wms-wave-review').'/'.$this->name();
        Storage::set('s3', Storage::build(['driver' => 'local', 'root' => $this->outputRoot, 'throw' => true]));
        $this->mock(LotAdjustmentRunner::class)->shouldReceive('runForWaveGeneration')->andReturnNull();
    }

    protected function tearDown(): void
    {
        while (DB::connection('sakemaru')->transactionLevel() > 0) {
            DB::connection('sakemaru')->rollBack();
        }
        DB::disconnect('sakemaru');
        parent::tearDown();
    }

    private function product(int $code, int $current, int $reserved, int $caseSize = 12, ?int $lotCurrent = null): array
    {
        [$item, $stock, $location] = $this->stock($current, $reserved, $caseSize);
        $db = DB::connection('sakemaru');
        $db->table('items')->where('id', $item)->update(['code' => $code, 'name' => '検証商品 '.$code.' 日本語長い商品名の確認', 'capacity_carton' => 6]);
        $db->table('locations')->where('id', $location)->update(['code1' => 'A', 'code2' => '01', 'code3' => '02', 'floor_id' => 1, 'wms_picking_area_id' => 1]);

        return [$item, $stock, $this->lot($stock, $location, $lotCurrent ?? $current, $reserved)];
    }

    private function order(int $item, int $quantity, string $type, int $course, string $time, string $sourceType = 'EARNING', int $caseSize = 12, ?int $lot = null, int $destination = 10): object
    {
        $line = $this->line($item, $quantity, $type, $caseSize);
        $db = DB::connection('sakemaru');
        $db->table('trade_items')->where('id', $line->id)->update(['capacity_carton' => 6]);
        $line = $db->table('trade_items')->where('id', $line->id)->first();
        $db->table('trades')->insert(['id' => $line->trade_id, 'serial_id' => $line->id, 'created_at' => '2026-10-12 '.$time, 'is_active' => true, 'partner_id' => 1, 'slip_number' => 'TEST-'.$line->id]);
        $values = ['id' => $line->id, 'trade_id' => $line->trade_id, 'is_active' => true, 'is_delivered' => false, 'picking_status' => 'BEFORE', 'delivery_course_id' => $course, 'delivered_date' => '2026-10-12'];
        if ($sourceType === 'EARNING') {
            $db->table('earnings')->insert($values + ['buyer_id' => 1]);
            if ($lot !== null) {
                $this->rsle($lot, $line->id, $line->id, (int) $line->total_piece_quantity);
            }
        } else {
            $db->table('stock_transfers')->insert($values + ['is_confirmed' => false, 'from_warehouse_id' => $this->fixtureWarehouseId, 'to_warehouse_id' => $destination, 'picking_date' => '2026-10-12']);
            if ($lot !== null) {
                $this->stla($line->id, $line->id, $lot, (int) $line->total_piece_quantity);
            }
        }

        return $line;
    }

    private function serviceFailingEarningLine(int $lineId, bool $once): WaveGroupGenerationService
    {
        return new class($lineId, $once) extends WaveGroupGenerationService
        {
            public StockAllocationService $allocator;

            public function __construct(private int $lineId, private bool $once) {}

            protected function earningAllocationService(): StockAllocationService
            {
                return $this->allocator = new class($this->lineId, $this->once, parent::earningAllocationService()) extends StockAllocationService
                {
                    public array $calls = [];

                    public function __construct(private int $lineId, private bool $once, private StockAllocationService $delegate)
                    {
                        parent::__construct();
                    }

                    public function allocateForItem(int $waveId, int $warehouseId, int $itemId, int $needQty, string $quantityType, int $sourceId, int $sourceLineId, string $sourceType = 'EARNING', ?int $buyerId = null): array
                    {
                        $this->calls[$sourceLineId] = ($this->calls[$sourceLineId] ?? 0) + 1;
                        $result = $this->delegate->allocateForItem($waveId, $warehouseId, $itemId, $needQty, $quantityType, $sourceId, $sourceLineId, $sourceType, $buyerId);
                        if ($sourceLineId === $this->lineId && (! $this->once || $this->calls[$sourceLineId] === 1)) {
                            throw new \RuntimeException('Injected failure after reservation writes');
                        }

                        return $result;
                    }
                };
            }
        };
    }

    private function group(): WaveGroup
    {
        return WaveGroup::create(['group_no' => 'WG-REVIEW', 'warehouse_id' => $this->fixtureWarehouseId, 'shipping_date' => '2026-10-12', 'generation_type' => 'delivery_course', 'target_document_types' => ['shipment', 'transfer'], 'conditions' => ['delivery_course_ids' => [1, 9], 'separate_floors' => true], 'created_by' => 1]);
    }

    private function assertSixPdfs(array $result): void
    {
        $this->assertSame([], $result['picking_list_errors']);
        $this->assertCount(6, $result['picking_lists']);
        foreach ($result['picking_lists'] as $entry) {
            $bytes = Storage::disk('s3')->get($entry['path']);
            $this->assertStringStartsWith('%PDF-', $bytes);
            $this->assertSame($entry['checksum'], 'sha256:'.hash('sha256', $bytes));
        }
        $db = DB::connection('sakemaru');
        $this->assertSame(0, $db->table('wms_picking_item_results as pir')
            ->join('wms_picking_tasks as pt', 'pir.picking_task_id', '=', 'pt.id')
            ->whereIn('pt.wave_id', $result['wave_ids'])
            ->whereRaw('pir.ordered_qty != pir.planned_qty + pir.shortage_qty')->count(), 'Every ordered unit must be allocated or short');
        $this->assertSame(0, $db->table('wms_picking_item_results as pir')
            ->join('wms_picking_tasks as pt', 'pir.picking_task_id', '=', 'pt.id')
            ->whereIn('pt.wave_id', $result['wave_ids'])
            ->where(fn ($q) => $q->whereColumn('pir.ordered_qty_type', '!=', 'pir.planned_qty_type')->orWhereColumn('pir.planned_qty_type', '!=', 'pir.picked_qty_type'))->count());
        file_put_contents($this->outputRoot.'/result.json', json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    #[Test]
    public function production_incidents_generate_all_six_real_pdf_formats_with_correct_shortages(): void
    {
        [$rice, , $riceLot] = $this->product(119519, -1, 0);
        $earlyRice = $this->order($rice, 2, 'PIECE', 9, '07:00:00', 'STOCK_TRANSFER', 12, $riceLot, 3);
        $lateRice = $this->order($rice, 3, 'CASE', 1, '08:00:00', 'STOCK_TRANSFER', 12, $riceLot);
        [$soba, $sobaStock, $sobaLot] = $this->product(141238, -32, 140, 20, 134);
        $this->lot($sobaStock, 2, -166, 6);
        DB::connection('sakemaru')->table('real_stock_lots')->where('id', $sobaLot)->update(['reserved_quantity' => 134]);
        // Parent reservations belong to orders outside this wave and remain held.
        $sobaOrder = $this->order($soba, 9, 'CASE', 1, '08:00:01', 'STOCK_TRANSFER', 20, $sobaLot, 22);
        [$ale, , $aleLot] = $this->product(141264, -190, 160, 20);
        $aleOrder = $this->order($ale, 10, 'CASE', 1, '08:00:02', 'STOCK_TRANSFER', 20, $aleLot, 22);
        [$sake, $sakeStock, $sakeLot] = $this->product(119285, 6, 12);
        $first = $this->order($sake, 6, 'PIECE', 9, '06:00:00', 'EARNING', 12, $this->lot($sakeStock, 4, 0, 6));
        $second = $this->order($sake, 6, 'PIECE', 1, '06:01:00', 'EARNING', 12, $this->lot($sakeStock, 4, 0, 6));
        // Zero fallback lots own the core reservations; physical lot has six free.
        DB::connection('sakemaru')->table('real_stock_lots')->where('id', $sakeLot)->update(['reserved_quantity' => 0]);
        $result = (new WaveGroupGenerationService)->generate($this->group(), 1);
        $this->assertSixPdfs($result);
        $rows = DB::connection('sakemaru')->table('wms_picking_item_results')->get()->keyBy('trade_item_id');
        foreach ([[$earlyRice, 2, 0, 'PIECE'], [$lateRice, 35, 1, 'PIECE'], [$sobaOrder, 8, 172, 'PIECE'], [$aleOrder, 0, 10, 'CASE'], [$first, 6, 0, 'PIECE'], [$second, 0, 6, 'PIECE']] as [$line, $planned, $shortage, $type]) {
            $row = $rows[$line->id];
            $this->assertSame([$planned, $shortage, $type], [(int) $row->planned_qty, (int) $row->shortage_qty, $row->planned_qty_type]);
        }
        $listService = new PickingListService;
        $shortageLists = $listService->generateShortageCourseLists($result['wave_ids']);
        $this->assertSame(379, array_sum(array_column(array_column($shortageLists, 'summary'), 'total_shortage')));
        foreach (['generatePrimaryTotalList', 'generateCourseGroupedListByWaveIds', 'generateCourseGroupedListV2ByWaveIds', 'generateBuyerGroupedListByWaveIds'] as $method) {
            $list = $listService->{$method}($result['wave_ids']);
            $items = isset($list['items']) ? $list['items'] : array_merge(...array_column($list, 'items'));
            foreach ([119519 => 1, 141238 => 172, 141264 => 200, 119285 => 6] as $code => $pieces) {
                $this->assertSame($pieces, array_sum(array_column(array_filter($items, fn ($row) => (int) $row['item_code'] === $code), 'shortage_qty')), $method.' shortage '.$code);
            }
        }
        file_put_contents($this->outputRoot.'/picking-rows.json', json_encode($rows->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    #[Test]
    public function earlier_transfer_beats_later_earning_across_courses(): void
    {
        [$item, , $lot] = $this->product(210001, 0, 6);
        $early = $this->order($item, 6, 'PIECE', 9, '07:00:00', 'STOCK_TRANSFER', 12, $lot);
        $late = $this->order($item, 6, 'PIECE', 1, '08:00:00', 'EARNING', 12, $lot);
        $result = (new WaveGroupGenerationService)->generate($this->group(), 1);
        $this->assertSixPdfs($result);
        $rows = DB::connection('sakemaru')->table('wms_picking_item_results')->get()->keyBy('trade_item_id');
        $this->assertSame(6, (int) $rows[$early->id]->planned_qty);
        $this->assertSame(6, (int) $rows[$late->id]->shortage_qty);
    }

    #[Test]
    public function carton_and_piece_orders_use_snapshot_capacities_in_every_format(): void
    {
        [$item, , $lot] = $this->product(210002, 14, 0, 12);
        $this->order($item, 2, 'CARTON', 9, '07:00:00');
        $this->order($item, 5, 'PIECE', 1, '08:00:00');
        // Change master capacity after orders; the snapshot remains 12 / 6.
        DB::connection('sakemaru')->table('items')->where('id', $item)->update(['capacity_case' => 24, 'capacity_carton' => 10]);
        $result = (new WaveGroupGenerationService)->generate($this->group(), 1);
        $this->assertSixPdfs($result);
        $list = (new PickingListService)->generatePrimaryTotalList($result['wave_ids']);
        $this->assertSame(14, array_sum(array_column($list['items'], 'total_piece_qty')));
        $this->assertSame(3, array_sum(array_column($list['items'], 'shortage_qty')));
    }

    #[Test]
    public function persistent_errors_become_shortages_without_holding_later_orders(): void
    {
        [$bad] = $this->product(210003, 12, 0);
        [$good] = $this->product(210004, 6, 0);
        $first = $this->order($bad, 6, 'PIECE', 9, '07:00:00');
        $second = $this->order($bad, 6, 'PIECE', 1, '08:00:00');
        $goodOrder = $this->order($good, 6, 'PIECE', 1, '09:00:00');
        $service = new class($bad) extends WaveGroupGenerationService
        {
            public StockAllocationService $allocator;

            public function __construct(private int $badItem) {}

            protected function earningAllocationService(): StockAllocationService
            {
                return $this->allocator = new class($this->badItem, parent::earningAllocationService()) extends StockAllocationService
                {
                    public array $calls = [];

                    public function __construct(private int $badItem, private StockAllocationService $delegate)
                    {
                        parent::__construct();
                    }

                    public function allocateForItem(int $waveId, int $warehouseId, int $itemId, int $needQty, string $quantityType, int $sourceId, int $sourceLineId, string $sourceType = 'EARNING', ?int $buyerId = null): array
                    {
                        $this->calls[$sourceLineId] = ($this->calls[$sourceLineId] ?? 0) + 1;
                        $result = $this->delegate->allocateForItem($waveId, $warehouseId, $itemId, $needQty, $quantityType, $sourceId, $sourceLineId, $sourceType, $buyerId);
                        if ($itemId === $this->badItem) {
                            throw new \RuntimeException('Injected failure after writing reservations');
                        }

                        return $result;
                    }
                };
            }
        };
        $result = $service->generate($this->group(), 1);
        $this->assertSixPdfs($result);
        $this->assertCount(2, $result['allocation_warnings']);
        $this->assertStringContainsString('/Count 3', Storage::disk('s3')->get($result['picking_lists']['primary']['path']));
        $this->assertStringContainsString('/Count 2', Storage::disk('s3')->get($result['picking_lists']['primary_total']['path']));
        $db = DB::connection('sakemaru');
        $this->assertSame(6, (int) $db->table('wms_picking_item_results')->where('trade_item_id', $goodOrder->id)->value('planned_qty'));
        $this->assertSame(0, (int) $db->table('wms_reservations')->where('item_id', $bad)->sum('qty_each'));
        $this->assertSame(2, $db->table('wms_shortages')->where('reason_code', 'OTHER')->where('is_confirmed', false)->count());
        $shorts = (new PickingListService)->generateShortageCourseLists($result['wave_ids']);
        $this->assertStringNotContainsString('引当要確認', $shorts[0]['items'][0]['item_name']);
        $this->assertSame(12, array_sum(array_column(array_column($shorts, 'summary'), 'total_shortage')));
        $this->assertCount(1, $result['allocation_recalculations']);
        $this->assertSame([2, 1], [$service->allocator->calls[$first->id], $service->allocator->calls[$second->id]]);
        $this->assertSame(2, $db->table('wms_reservations')->where('item_id', $bad)->where('status', 'SHORTAGE')->count());
        $db->table('wms_shortages')->where('item_id', $bad)->update(['is_confirmed' => true, 'status' => 'SHORTAGE']);
        $confirmedLists = (new PickingListService)->generateShortageCourseLists($result['wave_ids']);
        $this->assertSame(12, array_sum(array_column(array_column($confirmedLists, 'summary'), 'total_shortage')));
    }

    #[Test]
    public function one_pdf_error_leaves_five_downloadable_formats_and_retry_does_not_allocate_again(): void
    {
        [$item] = $this->product(210005, 6, 0);
        $this->order($item, 6, 'PIECE', 1, '07:00:00');
        $group = $this->group();
        $service = new class extends WaveGroupGenerationService
        {
            protected function pickingListRenderers(PickingListService $listService, PickingListPdfService $pdfService, array $waveIds, bool $separateFloors): array
            {
                $renderers = parent::pickingListRenderers($listService, $pdfService, $waveIds, $separateFloors);
                $renderers['secondary'] = fn () => throw new \RuntimeException('Injected PDF failure');

                return $renderers;
            }
        };
        $failed = $service->generate($group, 1);
        $this->assertCount(5, $failed['picking_lists']);
        $this->assertSame(['secondary'], array_keys($failed['picking_list_errors']));
        $reservationCount = DB::connection('sakemaru')->table('wms_reservations')->count();
        $retried = (new WaveGroupGenerationService)->generate($group->fresh(), 1);
        $this->assertSixPdfs($retried);
        $this->assertSame($failed['wave_ids'], $retried['wave_ids']);
        $this->assertSame($reservationCount, DB::connection('sakemaru')->table('wms_reservations')->count());
    }

    #[Test]
    public function missing_location_and_missing_search_index_still_print_all_formats(): void
    {
        [$item, , $lot] = $this->product(210006, 6, 0);
        DB::connection('sakemaru')->table('real_stock_lots')->where('id', $lot)->update(['location_id' => null]);
        $this->order($item, 6, 'PIECE', 1, '07:00:00');
        $result = (new WaveGroupGenerationService)->generate($this->group(), 1);
        $this->assertSixPdfs($result);
    }

    #[Test]
    public function earlier_partial_case_order_keeps_priority_over_later_piece_orders(): void
    {
        [$item] = $this->product(210007, 35, 0);
        $early = $this->order($item, 3, 'CASE', 9, '07:00:00');
        $late = $this->order($item, 2, 'PIECE', 1, '08:00:00');
        $result = (new WaveGroupGenerationService)->generate($this->group(), 1);
        $this->assertSixPdfs($result);
        $rows = DB::connection('sakemaru')->table('wms_picking_item_results')->get()->keyBy('trade_item_id');
        $this->assertSame([36, 35, 1, 'PIECE'], [(int) $rows[$early->id]->ordered_qty, (int) $rows[$early->id]->planned_qty, (int) $rows[$early->id]->shortage_qty, $rows[$early->id]->planned_qty_type]);
        $this->assertSame([0, 2], [(int) $rows[$late->id]->planned_qty, (int) $rows[$late->id]->shortage_qty]);
    }

    #[Test]
    public function duplicate_source_is_excluded_while_other_sources_generate_normally(): void
    {
        [$item] = $this->product(210008, 12, 0);
        $duplicate = $this->order($item, 6, 'PIECE', 9, '07:00:00');
        $normal = $this->order($item, 6, 'PIECE', 1, '08:00:00');
        DB::connection('sakemaru')->table('wms_reservations')->insert(['wave_id' => 999, 'source_type' => 'EARNING', 'source_id' => $duplicate->id, 'source_line_id' => $duplicate->id, 'status' => 'RESERVED', 'qty_each' => 6]);
        $result = (new WaveGroupGenerationService)->generate($this->group(), 1);
        $this->assertSixPdfs($result);
        $this->assertCount(1, $result['allocation_warnings']);
        $rows = DB::connection('sakemaru')->table('wms_picking_item_results')->get();
        $this->assertCount(1, $rows);
        $this->assertSame($normal->id, (int) $rows->first()->trade_item_id);
    }

    #[Test]
    public function all_storage_errors_preserve_waves_and_allow_a_safe_retry(): void
    {
        [$item] = $this->product(210009, 6, 0);
        $this->order($item, 6, 'PIECE', 1, '07:00:00');
        $group = $this->group();
        $service = new class extends WaveGroupGenerationService
        {
            protected function storePickingList(string $path, string $pdf): bool
            {
                return false;
            }
        };
        $failed = $service->generate($group, 1);
        $this->assertCount(6, $failed['picking_list_errors']);
        $this->assertSame([], $failed['picking_lists']);
        $this->assertSame($failed['wave_ids'], $group->fresh()->generation_result['wave_ids']);
        $retry = (new WaveGroupGenerationService)->generate($group->fresh(), 1);
        $this->assertSixPdfs($retry);
        $this->assertSame($failed['wave_ids'], $retry['wave_ids']);
        $this->assertSame(1, DB::connection('sakemaru')->table('wms_reservations')->count());
    }

    #[Test]
    public function many_orders_across_two_floors_paginate_and_match_an_independent_piece_budget(): void
    {
        $expected = [];
        for ($productIndex = 0; $productIndex < 24; $productIndex++) {
            $available = ($productIndex * 7) % 43;
            [$item, , $lot] = $this->product(310000 + $productIndex, $available, 0);
            $db = DB::connection('sakemaru');
            $locationId = $db->table('real_stock_lots')->where('id', $lot)->value('location_id');
            $db->table('locations')->where('id', $locationId)->update(['floor_id' => $productIndex % 2 + 1]);
            foreach (['CASE', 'CARTON', 'PIECE', 'PIECE'] as $index => $type) {
                $qty = $index === 3 ? 7 : 2;
                $pieces = $qty * match ($type) {
                    'CASE' => 12, 'CARTON' => 6, default => 1
                };
                $line = $this->order($item, $qty, $type, $index % 2 === 0 ? 9 : 1, sprintf('07:%02d:%02d', $index, $productIndex));
                $take = min($pieces, $available);
                $expected[$line->id] = [$take, $pieces - $take];
                $available -= $take;
            }
        }
        $result = (new WaveGroupGenerationService)->generate($this->group(), 1);
        $this->assertSixPdfs($result);
        $rows = DB::connection('sakemaru')->table('wms_picking_item_results')->get();
        $this->assertCount(96, $rows);
        foreach ($rows as $row) {
            $multiplier = match ($row->planned_qty_type) {
                'CASE' => 12, 'CARTON' => 6, default => 1
            };
            $this->assertSame($expected[$row->trade_item_id], [(int) $row->planned_qty * $multiplier, (int) $row->shortage_qty * $multiplier]);
        }
    }

    #[Test]
    public function lot_adjustment_failure_does_not_stop_generation_or_pdf_output(): void
    {
        $this->mock(LotAdjustmentRunner::class)->shouldReceive('runForWaveGeneration')->andThrow(new \RuntimeException('Injected adjustment failure'));
        [$item] = $this->product(210010, 6, 0);
        $this->order($item, 6, 'PIECE', 1, '07:00:00');
        $this->assertSixPdfs((new WaveGroupGenerationService)->generate($this->group(), 1));
    }

    #[Test]
    public function earlier_earning_can_use_stock_already_deducted_by_a_later_transfer(): void
    {
        [$item, , $lot] = $this->product(210011, 0, 6);
        $early = $this->order($item, 6, 'PIECE', 9, '07:00:00', 'EARNING', 12, $lot);
        $late = $this->order($item, 6, 'PIECE', 1, '08:00:00', 'STOCK_TRANSFER', 12, $lot);
        $result = (new WaveGroupGenerationService)->generate($this->group(), 1);
        $this->assertSixPdfs($result);
        $rows = DB::connection('sakemaru')->table('wms_picking_item_results')->get()->keyBy('trade_item_id');
        $this->assertSame(6, (int) $rows[$early->id]->planned_qty);
        $this->assertSame(6, (int) $rows[$late->id]->shortage_qty);
    }

    #[Test]
    public function one_item_lock_timeout_becomes_shortage_and_other_products_print(): void
    {
        [$bad] = $this->product(210012, 6, 0);
        [$good] = $this->product(210013, 6, 0);
        $this->order($bad, 6, 'PIECE', 9, '07:00:00');
        $normal = $this->order($good, 6, 'PIECE', 1, '08:00:00');
        $db = DB::connection('sakemaru');
        $key = 'alloc:'.$this->fixtureWarehouseId.':'.$bad;
        $mysql = $db->getDriverName() === 'mysql';
        if ($mysql) {
            config(['database.connections.review_lock' => config('database.connections.sakemaru')]);
            $this->assertSame(1, (int) DB::connection('review_lock')->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$key])->acquired);
        } else {
            $db->getPdo()->sqliteCreateFunction('GET_LOCK', fn ($lock) => $lock === $key ? 0 : 1);
        }
        try {
            $result = (new WaveGroupGenerationService)->generate($this->group(), 1);
            $this->assertSixPdfs($result);
            $this->assertCount(1, $result['allocation_warnings']);
            $this->assertCount(1, $result['allocation_recalculations']);
            $this->assertSame(6, (int) $db->table('wms_reservations')->where('item_id', $bad)->where('status', 'SHORTAGE')->sum('shortage_qty'));
            $this->assertSame(6, (int) $db->table('wms_picking_item_results')->where('trade_item_id', $normal->id)->value('planned_qty'));
            $this->assertSame(0, (int) $db->table('wms_reservations')->where('item_id', $bad)->sum('qty_each'));
        } finally {
            if ($mysql) {
                DB::connection('review_lock')->select('SELECT RELEASE_LOCK(?)', [$key]);
                DB::disconnect('review_lock');
            }
        }
    }

    #[Test]
    public function one_case_from_two_shelves_is_printed_at_each_correct_floor(): void
    {
        [$item, $stock, $firstLot] = $this->product(210014, 12, 0, 12, 7);
        $db = DB::connection('sakemaru');
        $secondLocation = $db->table('locations')->insertGetId(['warehouse_id' => $this->fixtureWarehouseId, 'code1' => 'B', 'code2' => '02', 'code3' => '01', 'is_disabled' => false, 'floor_id' => 2, 'wms_picking_area_id' => 1]);
        $this->lot($stock, $secondLocation, 5);
        $line = $this->order($item, 1, 'CASE', 1, '07:00:00');
        $result = (new WaveGroupGenerationService)->generate($this->group(), 1);
        $this->assertSixPdfs($result);
        $rows = $db->table('wms_picking_item_results')->where('trade_item_id', $line->id)->orderBy('id')->get();
        $this->assertSame([7, 5], $rows->pluck('planned_qty')->map(fn ($qty) => (int) $qty)->all());
        $this->assertSame(['PIECE', 'PIECE'], $rows->pluck('planned_qty_type')->all());
        $this->assertSame(12, (int) $rows->sum('ordered_qty'));
        $this->assertSame(0, (int) $rows->sum('shortage_qty'));
        $this->assertSame([1, 2], $db->table('wms_picking_tasks')->orderBy('id')->pluck('floor_id')->map(fn ($id) => (int) $id)->all());
        $pages = (new PickingListService)->generatePrimaryTotalListPages($result['wave_ids'], true);
        $this->assertSame([7, 5], array_map(fn ($page) => array_sum(array_column($page['items'], 'total_piece_qty')), $pages));
    }

    #[Test]
    public function transfer_sources_at_two_shelves_are_printed_separately_without_duplicating_shortage(): void
    {
        [$item, $stock, $firstLot] = $this->product(210015, -1, 0, 12, 0);
        $db = DB::connection('sakemaru');
        $secondLocation = $db->table('locations')->insertGetId(['warehouse_id' => $this->fixtureWarehouseId, 'code1' => 'B', 'code2' => '02', 'code3' => '01', 'is_disabled' => false, 'floor_id' => 2, 'wms_picking_area_id' => 1]);
        $secondLot = $this->lot($stock, $secondLocation, -1);
        $line = $this->order($item, 1, 'CASE', 1, '07:00:00', 'STOCK_TRANSFER');
        $this->stla($line->id, $line->id, $firstLot, 7);
        $this->stla($line->id, $line->id, $secondLot, 5);
        $result = (new WaveGroupGenerationService)->generate($this->group(), 1);
        $this->assertSixPdfs($result);
        $rows = $db->table('wms_picking_item_results')->where('trade_item_id', $line->id)->get();
        $this->assertSame([7, 4], $rows->pluck('planned_qty')->map(fn ($qty) => (int) $qty)->all());
        $this->assertSame(12, (int) $rows->sum('ordered_qty'));
        $this->assertSame(1, (int) $rows->sum('shortage_qty'));
        $this->assertSame(2, $db->table('wms_picking_tasks')->count());
    }

    #[Test]
    public function missing_item_master_becomes_printable_shortage_and_other_order_prints(): void
    {
        [$bad] = $this->product(210016, 6, 0);
        [$good] = $this->product(210017, 6, 0);
        $this->order($bad, 6, 'PIECE', 9, '07:00:00');
        $normal = $this->order($good, 6, 'PIECE', 1, '08:00:00');
        DB::connection('sakemaru')->table('items')->where('id', $bad)->delete();
        $result = (new WaveGroupGenerationService)->generate($this->group(), 1);
        $this->assertSixPdfs($result);
        $this->assertCount(1, $result['allocation_warnings']);
        $shorts = (new PickingListService)->generateShortageCourseLists($result['wave_ids']);
        $this->assertSame(6, array_sum(array_column(array_column($shorts, 'summary'), 'total_shortage')));
        $this->assertSame('ID:'.$bad, array_merge(...array_column($shorts, 'items'))[0]['item_code']);
        $this->assertSame(6, (int) DB::connection('sakemaru')->table('wms_picking_item_results')->where('trade_item_id', $normal->id)->value('planned_qty'));
    }

    #[Test]
    public function malformed_transfer_proof_becomes_shortage_after_one_recalculation(): void
    {
        [$bad, , $lot] = $this->product(210018, -6, 0);
        [$good] = $this->product(210019, 6, 0);
        $line = $this->order($bad, 6, 'PIECE', 9, '07:00:00', 'STOCK_TRANSFER');
        $this->stla($line->id, $line->id, $lot, 100);
        $this->order($good, 6, 'PIECE', 1, '08:00:00');
        $result = (new WaveGroupGenerationService)->generate($this->group(), 1);
        $this->assertSixPdfs($result);
        $this->assertCount(1, $result['allocation_warnings']);
        $shorts = (new PickingListService)->generateShortageCourseLists($result['wave_ids']);
        $this->assertSame(6, array_sum(array_column(array_column($shorts, 'summary'), 'total_shortage')));
        $this->assertCount(1, $result['allocation_recalculations']);
        $this->assertSame(1, DB::connection('sakemaru')->table('wms_reservations')->where('source_line_id', $line->id)->count());
    }

    #[Test]
    public function non_positive_order_lines_do_not_hold_a_later_positive_order(): void
    {
        [$item] = $this->product(210020, 6, 0);
        $this->order($item, -6, 'PIECE', 9, '07:00:00');
        $this->order($item, 0, 'PIECE', 9, '07:01:00');
        $normal = $this->order($item, 6, 'PIECE', 1, '08:00:00');
        $result = (new WaveGroupGenerationService)->generate($this->group(), 1);
        $this->assertSixPdfs($result);
        $this->assertSame([], $result['allocation_warnings']);
        $this->assertSame(6, (int) DB::connection('sakemaru')->table('wms_picking_item_results')->where('trade_item_id', $normal->id)->value('planned_qty'));
    }

    #[Test]
    public function pdf_storage_does_not_hold_the_warehouse_allocation_lock(): void
    {
        [$item] = $this->product(210021, 6, 0);
        $this->order($item, 6, 'PIECE', 1, '07:00:00');
        $mysql = DB::connection('sakemaru')->getDriverName() === 'mysql';
        if ($mysql) {
            config(['database.connections.review_lock' => config('database.connections.sakemaru')]);
        }
        $service = new class($mysql, $this->fixtureWarehouseId) extends WaveGroupGenerationService
        {
            public array $acquired = [];

            public function __construct(private bool $mysql, private int $warehouseId) {}

            protected function storePickingList(string $path, string $pdf): bool
            {
                if ($this->mysql) {
                    $key = 'wave_generation:'.$this->warehouseId;
                    $value = (int) DB::connection('review_lock')->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$key])->acquired;
                    $this->acquired[] = $value;
                    DB::connection('review_lock')->select('SELECT RELEASE_LOCK(?)', [$key]);
                }

                return parent::storePickingList($path, $pdf);
            }
        };
        try {
            $this->assertSixPdfs($service->generate($this->group(), 1));
            if ($mysql) {
                $this->assertSame([1, 1, 1, 1, 1, 1], $service->acquired);
            }
        } finally {
            DB::disconnect('review_lock');
        }
    }

    #[Test]
    public function a_transient_failure_is_recalculated_once_and_the_first_order_keeps_priority(): void
    {
        [$item] = $this->product(210022, 6, 0);
        $first = $this->order($item, 6, 'PIECE', 9, '07:00:00');
        $second = $this->order($item, 6, 'PIECE', 1, '08:00:00');
        $service = $this->serviceFailingEarningLine($first->id, once: true);
        $group = $this->group();
        $result = $service->generate($group, 1);
        $this->assertSixPdfs($result);
        $db = DB::connection('sakemaru');
        $rows = $db->table('wms_picking_item_results')->get()->keyBy('trade_item_id');
        $this->assertSame([6, 0], [(int) $rows[$first->id]->planned_qty, (int) $rows[$first->id]->shortage_qty]);
        $this->assertSame([0, 6], [(int) $rows[$second->id]->planned_qty, (int) $rows[$second->id]->shortage_qty]);
        $this->assertSame([2, 1], [$service->allocator->calls[$first->id], $service->allocator->calls[$second->id]]);
        $this->assertCount(1, $result['allocation_recalculations']);
        $this->assertTrue($result['allocation_recalculations'][0]['completed']);
        $this->assertSame(6, (int) $db->table('wms_reservations')->sum('qty_each'));
        $this->assertSame(2, $db->table('wms_reservations')->count());
        $this->assertSame(0, $db->table('wms_shortages')->where('reason_code', 'OTHER')->count());
        $retried = $service->generate($group->fresh(), 1);
        $this->assertSame($result['wave_ids'], $retried['wave_ids']);
        $this->assertSame([2, 1], [$service->allocator->calls[$first->id], $service->allocator->calls[$second->id]]);
    }

    #[Test]
    public function an_unrecoverable_first_line_becomes_shortage_and_the_next_same_item_can_allocate(): void
    {
        [$item] = $this->product(210023, 6, 0);
        $first = $this->order($item, 6, 'PIECE', 9, '07:00:00');
        $second = $this->order($item, 6, 'PIECE', 1, '08:00:00');
        $service = $this->serviceFailingEarningLine($first->id, once: false);
        $result = $service->generate($this->group(), 1);
        $this->assertSixPdfs($result);
        $db = DB::connection('sakemaru');
        $rows = $db->table('wms_picking_item_results')->get()->keyBy('trade_item_id');
        $this->assertSame([0, 6], [(int) $rows[$first->id]->planned_qty, (int) $rows[$first->id]->shortage_qty]);
        $this->assertSame([6, 0], [(int) $rows[$second->id]->planned_qty, (int) $rows[$second->id]->shortage_qty]);
        $this->assertSame([2, 1], [$service->allocator->calls[$first->id], $service->allocator->calls[$second->id]]);
        $this->assertCount(1, $result['allocation_recalculations']);
        $this->assertSame(6, (int) $db->table('wms_reservations')->sum('qty_each'));
        $this->assertSame(6, (int) $db->table('wms_reservations')->sum('shortage_qty'));
        $shorts = (new PickingListService)->generateShortageCourseLists($result['wave_ids']);
        $this->assertSame(6, array_sum(array_column(array_column($shorts, 'summary'), 'total_shortage')));
    }

    #[Test]
    public function transfer_recalculation_does_not_restore_stock_already_used_by_an_earlier_earning(): void
    {
        [$item, , $lot] = $this->product(210024, 0, 6);
        $first = $this->order($item, 6, 'PIECE', 9, '07:00:00', 'EARNING', 12, $lot);
        $second = $this->order($item, 6, 'PIECE', 1, '08:00:00', 'STOCK_TRANSFER', 12, $lot);
        $service = new class extends WaveGroupGenerationService
        {
            public StockTransferLotAllocationService $allocator;

            protected function transferAllocationService(): StockTransferLotAllocationService
            {
                return $this->allocator = new class(parent::transferAllocationService()) extends StockTransferLotAllocationService
                {
                    public int $calls = 0;

                    public function __construct(private StockTransferLotAllocationService $delegate) {}

                    public function allocateForTradeItem(int $waveId, int $warehouseId, int $stockTransferId, object $tradeItem): array
                    {
                        $this->calls++;
                        $result = $this->delegate->allocateForTradeItem($waveId, $warehouseId, $stockTransferId, $tradeItem);
                        if ($this->calls === 1) {
                            throw new \RuntimeException('Injected transfer failure after writes');
                        }

                        return $result;
                    }
                };
            }
        };
        $result = $service->generate($this->group(), 1);
        $this->assertSixPdfs($result);
        $rows = DB::connection('sakemaru')->table('wms_picking_item_results')->get()->keyBy('trade_item_id');
        $this->assertSame([6, 0], [(int) $rows[$first->id]->planned_qty, (int) $rows[$first->id]->shortage_qty]);
        $this->assertSame([0, 6], [(int) $rows[$second->id]->planned_qty, (int) $rows[$second->id]->shortage_qty]);
        $this->assertSame(2, $service->allocator->calls);
        $this->assertCount(1, $result['allocation_recalculations']);
        $this->assertSame(6, (int) DB::connection('sakemaru')->table('wms_reservations')->sum('qty_each'));
    }

    #[Test]
    public function recalculation_does_not_duplicate_transfer_restoration_or_pick_from_an_already_used_shelf(): void
    {
        [$item, $stock, $firstLot] = $this->product(210025, 0, 12);
        $db = DB::connection('sakemaru');
        $firstLocation = $db->table('real_stock_lots')->where('id', $firstLot)->value('location_id');
        $db->table('real_stock_lots')->where('id', $firstLot)->update(['reserved_quantity' => 6]);
        $secondLocation = $db->table('locations')->insertGetId(['warehouse_id' => $this->fixtureWarehouseId, 'code1' => 'B', 'code2' => '02', 'code3' => '01', 'is_disabled' => false, 'floor_id' => 2, 'wms_picking_area_id' => 1]);
        $secondLot = $this->lot($stock, $secondLocation, 0, 6);
        $first = $this->order($item, 6, 'PIECE', 9, '07:00:00', 'EARNING', 12, $firstLot);
        $second = $this->order($item, 6, 'PIECE', 9, '07:01:00', 'EARNING', 12, $secondLot);
        $transfer = $this->order($item, 12, 'PIECE', 1, '08:00:00', 'STOCK_TRANSFER');
        $this->stla($transfer->id, $transfer->id, $firstLot, 6);
        $this->stla($transfer->id, $transfer->id, $secondLot, 6);
        $result = $this->serviceFailingEarningLine($second->id, once: true)->generate($this->group(), 1);
        $this->assertSixPdfs($result);
        $rows = $db->table('wms_picking_item_results')->get()->keyBy('trade_item_id');
        $this->assertSame([$firstLocation, $secondLocation], [(int) $rows[$first->id]->location_id, (int) $rows[$second->id]->location_id]);
        $this->assertSame([6, 6, 0], [(int) $rows[$first->id]->planned_qty, (int) $rows[$second->id]->planned_qty, (int) $rows[$transfer->id]->planned_qty]);
        $this->assertSame(12, (int) $rows[$transfer->id]->shortage_qty);
        $this->assertCount(1, $result['allocation_recalculations']);
    }

    #[Test]
    public function an_incomplete_allocator_result_is_recalculated_once_instead_of_leaving_unresolved_quantity(): void
    {
        [$item] = $this->product(210026, 6, 0);
        $first = $this->order($item, 6, 'PIECE', 9, '07:00:00');
        $second = $this->order($item, 6, 'PIECE', 1, '08:00:00');
        $service = new class extends WaveGroupGenerationService
        {
            public StockAllocationService $allocator;

            protected function earningAllocationService(): StockAllocationService
            {
                return $this->allocator = new class(parent::earningAllocationService()) extends StockAllocationService
                {
                    public int $calls = 0;

                    public function __construct(private StockAllocationService $delegate)
                    {
                        parent::__construct();
                    }

                    public function allocateForItem(int $waveId, int $warehouseId, int $itemId, int $needQty, string $quantityType, int $sourceId, int $sourceLineId, string $sourceType = 'EARNING', ?int $buyerId = null): array
                    {
                        $this->calls++;
                        $result = $this->delegate->allocateForItem($waveId, $warehouseId, $itemId, $needQty, $quantityType, $sourceId, $sourceLineId, $sourceType, $buyerId);
                        if ($this->calls === 1) {
                            $result['allocated'] = 0;
                            $result['shortage'] = 0;
                        }

                        return $result;
                    }
                };
            }
        };
        $result = $service->generate($this->group(), 1);
        $this->assertSixPdfs($result);
        $rows = DB::connection('sakemaru')->table('wms_picking_item_results')->get()->keyBy('trade_item_id');
        $this->assertSame([6, 0], [(int) $rows[$first->id]->planned_qty, (int) $rows[$first->id]->shortage_qty]);
        $this->assertSame([0, 6], [(int) $rows[$second->id]->planned_qty, (int) $rows[$second->id]->shortage_qty]);
        $this->assertCount(1, $result['allocation_recalculations']);
        $this->assertSame(3, $service->allocator->calls);
        $this->assertSame(6, (int) DB::connection('sakemaru')->table('wms_reservations')->sum('qty_each'));
    }
}
