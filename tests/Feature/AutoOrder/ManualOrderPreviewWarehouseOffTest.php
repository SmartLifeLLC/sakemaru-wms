<?php

namespace Tests\Feature\AutoOrder;

use App\Filament\Pages\WmsOrderRegistration;
use App\Services\AutoOrder\OrderRegistrationSearchService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class ManualOrderPreviewWarehouseOffTest extends TestCase
{
    public function test_sales_preview_returns_candidates_with_all_warehouse_switches_off(): void
    {
        if (! Schema::connection('sakemaru')->hasTable('stats_item_warehouse_daily_sales')) {
            $this->markTestSkipped('候補表示の結合確認には販売サマリのある専用テストDBが必要です。');
        }

        $db = DB::connection('sakemaru');
        $warehouse = $db->table('warehouses')->where('code', 8)->where('is_active', 1)->first();
        $end = $warehouse ? $db->table('stats_item_warehouse_daily_sales')->where('warehouse_id', $warehouse->id)->max('business_date') : null;
        if (! $warehouse || ! $end) {
            $this->markTestSkipped('倉庫CD 8の販売実績を持つ専用テストDBが必要です。');
        }

        config(['app.client_id' => (int) $warehouse->client_id]);
        $db->beginTransaction();
        try {
            Carbon::setTestNow(Carbon::parse($end)->setTime(10, 0));
            $db->table('wms_warehouse_auto_order_settings')->update(['is_auto_order_enabled' => false]);
            $search = Mockery::mock(OrderRegistrationSearchService::class);
            // Date calculation is unchanged; this test exercises the actual preview SQL and result mapping.
            $search->shouldReceive('defaultExpectedArrivalDate')->andReturn(Carbon::parse($end)->addDay()->toDateString());
            app()->instance(OrderRegistrationSearchService::class, $search);

            $page = new WmsOrderRegistration;
            $page->warehouseId = (int) $warehouse->id;
            $page->salesStartDate = Carbon::parse($end)->subDays(2)->toDateString();
            $page->salesEndDate = $end;
            $page->selectedExternalOrderContractorIds = [1021, 1330];
            $page->selectedExternalOrderCategory2Ids = [2011, 2013, 2014, 2015, 2016];
            $page->externalOrderJxContractorsData = [['id' => 1021], ['id' => 1330]];
            $page->externalOrderCategory2Data = array_map(fn ($id) => ['id' => $id], [2011, 2013, 2014, 2015, 2016, 2012]);

            $before = $db->table('wms_order_incoming_schedules')->count();
            $page->calculateSalesBasedExternalOrderPreview();

            self::assertNull($page->salesBasedExternalOrderPreviewError);
            self::assertNotEmpty($page->salesBasedExternalOrderPreviewRows);
            foreach ($page->salesBasedExternalOrderPreviewRows as $row) {
                self::assertSame((int) $warehouse->id, $row['warehouse_id']);
                self::assertContains($row['contractor_id'], [1021, 1330]);
                self::assertGreaterThan(0, $row['sales_qty']);
            }
            self::assertSame(0, $db->table('wms_warehouse_auto_order_settings')->where('is_auto_order_enabled', true)->count());
            self::assertSame($before, $db->table('wms_order_incoming_schedules')->count());
        } finally {
            $db->rollBack();
            Carbon::setTestNow();
        }
    }
}
