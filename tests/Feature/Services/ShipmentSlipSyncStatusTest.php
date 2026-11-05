<?php

namespace Tests\Feature\Services;

use App\Filament\Resources\WmsShipmentSlips\Tables\WmsShipmentSlipsTable;
use App\Models\Sakemaru\ClientSetting;
use App\Models\WmsPickingTask;
use App\Services\Shortage\ShortageApprovalService;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class ShipmentSlipSyncStatusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['database.connections.sakemaru' => [
            'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
        ]]);
        DB::purge('sakemaru');
        ClientSetting::clearCachedSetting();

        $pdo = DB::connection('sakemaru')->getPdo();
        // Match MySQL DATE comparisons when Eloquent binds a cast Carbon date at midnight.
        $pdo->sqliteCreateCollation('mysql_date', fn (string $a, string $b) => strcmp(substr($a, 0, 10), substr($b, 0, 10)));
        foreach ([
            'wms_picking_tasks' => 'wave_id INTEGER, delivery_course_id INTEGER, shipment_date TEXT COLLATE mysql_date, status TEXT, floor_id INTEGER, wms_picking_area_id INTEGER',
            'wms_picking_item_results' => 'picking_task_id INTEGER, item_id INTEGER, trade_id INTEGER, trade_item_id INTEGER, earning_id INTEGER, stock_transfer_id INTEGER, source_type TEXT, status TEXT',
            'wms_shortages' => 'source_pick_result_id INTEGER, shortage_qty INTEGER, is_synced INTEGER, is_confirmed INTEGER',
            'trades' => 'is_active INTEGER',
            'trade_items' => 'trade_id INTEGER, is_active INTEGER',
            'earnings' => 'trade_id INTEGER, buyer_id INTEGER, is_active INTEGER',
            'stock_transfers' => 'trade_id INTEGER, is_active INTEGER',
            'items' => 'code INTEGER, name TEXT, is_active INTEGER',
            'client_settings' => 'client_id INTEGER, system_date TEXT',
            'buyer_details' => 'buyer_id INTEGER, slip_type_id INTEGER, start_date TEXT',
            'slip_types' => 'category INTEGER',
            'floors' => 'name TEXT, is_active INTEGER',
            'wms_picking_areas' => 'name TEXT',
        ] as $table => $columns) {
            $pdo->exec("CREATE TABLE {$table} (id INTEGER PRIMARY KEY, {$columns})");
        }

        $db = DB::connection('sakemaru');
        $db->table('client_settings')->insert(['id' => 1, 'client_id' => 6, 'system_date' => '2026-11-05']);
        $db->table('wms_picking_tasks')->insert([
            ['id' => 13399, 'wave_id' => 5315, 'delivery_course_id' => 910296, 'shipment_date' => '2026-11-05', 'status' => 'COMPLETED'],
            ['id' => 13400, 'wave_id' => 5315, 'delivery_course_id' => 910296, 'shipment_date' => '2026-11-05', 'status' => 'COMPLETED'],
        ]);
        $this->addPickingLine(1, 'EARNING', 'COMPLETED', 13399);
    }

    protected function tearDown(): void
    {
        ClientSetting::clearCachedSetting();
        DB::disconnect('sakemaru');
        parent::tearDown();
    }

    public static function inactiveSources(): array
    {
        return [
            'inactive earning' => ['EARNING', 'earnings'],
            'inactive earning trade' => ['EARNING', 'trades'],
            'inactive earning line' => ['EARNING', 'trade_items'],
            'inactive transfer' => ['STOCK_TRANSFER', 'stock_transfers'],
            'inactive transfer trade' => ['STOCK_TRANSFER', 'trades'],
            'inactive transfer line' => ['STOCK_TRANSFER', 'trade_items'],
        ];
    }

    public static function sourceTypes(): array
    {
        return ['earning' => ['EARNING'], 'stock transfer' => ['STOCK_TRANSFER']];
    }

    #[Test]
    #[DataProvider('inactiveSources')]
    public function inactive_source_does_not_block_other_slips_in_the_course(string $sourceType, string $inactiveTable): void
    {
        $this->addPickingLine(2, $sourceType, 'PENDING', 13400);
        $this->addShortage(2, false);

        // A genuine unfinished, unsynced line in another task blocks the whole group.
        $this->assertFalse($this->groupRecord()->is_stock_synced);
        $before = $this->printability();
        $this->assertFalse($before['can_print']);
        $this->assertCount(1, $before['incomplete_items']);
        $this->assertCount(1, $before['unsynced_shortages']);

        DB::connection('sakemaru')->table($inactiveTable)->where('id', 2)->update(['is_active' => false]);

        $this->assertTrue($this->groupRecord()->is_stock_synced);
        $after = $this->printability();
        $this->assertTrue($after['can_print']);
        $this->assertSame([], $after['incomplete_items']);
        $this->assertSame([], $after['unsynced_shortages']);
        $this->assertSame(0, DB::connection('sakemaru')->table('wms_shortages')->where('id', 12002)->value('is_synced'));
        $this->assertSame(7, DB::connection('sakemaru')->table('wms_shortages')->where('id', 12002)->value('shortage_qty'));
    }

    #[Test]
    #[DataProvider('sourceTypes')]
    public function active_unsynced_shortage_still_blocks_printing(string $sourceType): void
    {
        $this->addPickingLine(2, $sourceType, 'COMPLETED', 13400);
        $this->addShortage(2, false);

        $this->assertFalse($this->groupRecord()->is_stock_synced);
        $result = $this->printability();
        $this->assertFalse($result['can_print']);
        $this->assertSame([], $result['incomplete_items']);
        $this->assertSame([12002], array_column($result['unsynced_shortages'], 'shortage_id'));
    }

    #[Test]
    #[DataProvider('sourceTypes')]
    public function active_unfinished_picking_still_blocks_printing_even_when_synced(string $sourceType): void
    {
        $this->addPickingLine(2, $sourceType, 'PICKING', 13400);
        $this->addShortage(2, true);

        $this->assertTrue($this->groupRecord()->is_stock_synced);
        $result = $this->printability();
        $this->assertFalse($result['can_print']);
        $this->assertSame([2], array_column($result['incomplete_items'], 'item_result_id'));
        $this->assertSame([], $result['unsynced_shortages']);
    }

    #[Test]
    #[DataProvider('sourceTypes')]
    public function completed_synced_active_sources_remain_printable(string $sourceType): void
    {
        $this->addPickingLine(2, $sourceType, 'COMPLETED', 13400);
        $this->addShortage(2, true);

        $this->assertTrue($this->groupRecord()->is_stock_synced);
        $result = $this->printability();
        $this->assertTrue($result['can_print']);
        $this->assertSame([], $result['incomplete_items']);
        $this->assertSame([], $result['unsynced_shortages']);
    }

    #[Test]
    public function a_course_without_any_active_source_cannot_be_printed(): void
    {
        DB::connection('sakemaru')->table('earnings')->where('id', 1)->update(['is_active' => false]);

        $result = $this->printability();

        $this->assertFalse($result['can_print']);
        $this->assertSame('印刷対象の有効な伝票明細がありません。', $result['error_message']);
        $this->assertSame([], $result['incomplete_items']);
        $this->assertSame([], $result['unsynced_shortages']);
    }

    private function addPickingLine(int $id, string $sourceType, string $status, int $taskId): void
    {
        $db = DB::connection('sakemaru');
        $db->table('trades')->insert(['id' => $id, 'is_active' => true]);
        $db->table('trade_items')->insert(['id' => $id, 'trade_id' => $id, 'is_active' => true]);
        $db->table('items')->insert(['id' => $id, 'code' => 211105 + $id, 'name' => '同期確認商品', 'is_active' => true]);
        $db->table($sourceType === 'EARNING' ? 'earnings' : 'stock_transfers')
            ->insert(['id' => $id, 'trade_id' => $id, 'is_active' => true]);
        $db->table('wms_picking_item_results')->insert([
            'id' => $id, 'picking_task_id' => $taskId, 'item_id' => $id,
            'trade_id' => $id, 'trade_item_id' => $id, 'source_type' => $sourceType,
            'earning_id' => $sourceType === 'EARNING' ? $id : null,
            'stock_transfer_id' => $sourceType === 'STOCK_TRANSFER' ? $id : null,
            'status' => $status,
        ]);
    }

    private function addShortage(int $pickResultId, bool $synced): void
    {
        DB::connection('sakemaru')->table('wms_shortages')->insert([
            'id' => 12002, 'source_pick_result_id' => $pickResultId,
            'shortage_qty' => 7, 'is_synced' => $synced, 'is_confirmed' => true,
        ]);
    }

    private function groupRecord(): WmsPickingTask
    {
        $records = WmsPickingTask::whereKey(13399)->get();
        WmsShipmentSlipsTable::loadGroupedTasks($records);

        return $records->first();
    }

    private function printability(): array
    {
        return (new ShortageApprovalService)->checkPrintability(910296, '2026-11-05', 5315);
    }
}
