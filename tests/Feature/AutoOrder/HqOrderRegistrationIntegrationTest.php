<?php

namespace Tests\Feature\AutoOrder;

use App\Filament\Pages\WmsOrderRegistrationHq;
use App\Services\AutoOrder\HqOrderRegistrationReferenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * 外部発注（本部）の結合テスト。
 *
 * 基幹DB（sakemaru 接続）のマスタ・実績を読むだけのテスト。必要なデータが無い環境ではスキップする。
 */
class HqOrderRegistrationIntegrationTest extends TestCase
{
    private bool $sakemaruTransactionStarted = false;

    protected function setUp(): void
    {
        parent::setUp();

        try {
            DB::connection('sakemaru')->beginTransaction();
            $this->sakemaruTransactionStarted = true;
        } catch (\Throwable $e) {
            $this->markTestSkipped('sakemaru DBに接続できないためスキップ: '.$e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if ($this->sakemaruTransactionStarted) {
            DB::connection('sakemaru')->rollBack();
        }

        parent::tearDown();
    }

    public function test_candidate_search_is_fixed_to_hq_warehouse_and_returns_reference_fields(): void
    {
        $hqWarehouseId = $this->hqWarehouseIdOrSkip();
        $this->skipUnlessTablesExist(['stats_item_warehouse_daily_sales', 'stats_item_warehouse_sales_summaries']);

        $item = DB::connection('sakemaru')
            ->table('item_contractors as ic')
            ->join('items as i', 'i.id', '=', 'ic.item_id')
            ->where('ic.warehouse_id', $hqWarehouseId)
            ->where('i.end_of_sale_type', 'NORMAL')
            ->where('i.is_ended', false)
            ->orderBy('i.id')
            ->first(['i.id', 'i.code']);

        if (! $item) {
            $this->markTestSkipped('本部倉庫の商品発注先マスタがありません。');
        }

        $page = new WmsOrderRegistrationHq;
        $page->warehouseId = $hqWarehouseId;

        // 画面から別の倉庫IDが渡されても、本部倉庫で検索する。
        $result = $page->searchItemsForModal(
            warehouseId: $hqWarehouseId + 100000,
            itemCode: (string) $item->code,
            perPage: 100,
        );

        $row = collect($result['data'])->firstWhere('id', (int) $item->id);
        $this->assertNotNull($row, '本部倉庫の商品が検索できませんでした。');

        foreach ([
            'last_incoming_date',
            'last_incoming_case_qty',
            'last_incoming_piece_qty',
            'last_purchase_date',
            'last_purchase_case_qty',
            'last_purchase_piece_qty',
            'sales_week1_qty',
            'sales_week2_qty',
            'sales_week3_qty',
            'previous_month_sales_qty',
        ] as $key) {
            $this->assertArrayHasKey($key, $row);
        }
    }

    public function test_weekly_sales_exclude_branch_wholesale_recorded_on_hq_warehouse(): void
    {
        $hqWarehouseId = $this->hqWarehouseIdOrSkip();
        $this->skipUnlessTablesExist(['stats_item_warehouse_daily_sales']);

        $service = app(HqOrderRegistrationReferenceService::class);
        $courseIds = $service->excludedDeliveryCourseIds();
        if ($courseIds === []) {
            $this->markTestSkipped('除外対象（敦賀・小浜）の配送コースがありません。');
        }

        $range = $service->weeklySalesDateRanges()['previous_month'];
        $branch = DB::connection('sakemaru')
            ->table('earnings as e')
            ->join('trade_items as ti', 'ti.trade_id', '=', 'e.trade_id')
            ->where('e.warehouse_id', $hqWarehouseId)
            ->whereIn('e.delivery_course_id', $courseIds)
            ->whereBetween('e.delivered_date', [$range['start'], $range['end']])
            ->where('e.is_active', true)
            ->where('ti.is_active', true)
            ->whereNotNull('ti.item_id')
            ->selectRaw('ti.item_id, SUM(COALESCE(CAST(ti.total_piece_quantity AS SIGNED), 0)) as qty')
            ->groupBy('ti.item_id')
            ->havingRaw('SUM(COALESCE(CAST(ti.total_piece_quantity AS SIGNED), 0)) > 0')
            ->orderByDesc('qty')
            ->first();

        if (! $branch) {
            $this->markTestSkipped('前月に店舗卸（計上は本部・出荷は店舗）の実績がありません。');
        }

        $itemId = (int) $branch->item_id;
        $total = (int) DB::connection('sakemaru')
            ->table('stats_item_warehouse_daily_sales')
            ->where('warehouse_id', $hqWarehouseId)
            ->where('item_id', $itemId)
            ->whereBetween('business_date', [$range['start'], $range['end']])
            ->sum('shipped_piece_qty');

        $adjusted = $service->adjustedWeeklySalesQuantities($hqWarehouseId, [$itemId]);
        $expected = HqOrderRegistrationReferenceService::subtractBranchQuantity($total, (int) $branch->qty);

        $this->assertSame($expected, $adjusted[$itemId]['previous_month_sales_qty']);
        $this->assertLessThan($total, $adjusted[$itemId]['previous_month_sales_qty']);

        // 本部倉庫以外では除外しない。
        $this->assertSame([], $service->branchWholesaleQuantities($hqWarehouseId + 100000, [$itemId], ['period' => $range]));
    }

    public function test_last_purchase_comes_from_confirmed_purchases(): void
    {
        $hqWarehouseId = $this->hqWarehouseIdOrSkip();

        $latest = DB::connection('sakemaru')
            ->table('trade_items as ti')
            ->join('purchases as p', 'p.trade_id', '=', 'ti.trade_id')
            ->join('trades as t', 't.id', '=', 'p.trade_id')
            ->where('p.warehouse_id', $hqWarehouseId)
            ->where('p.is_active', true)
            ->where('p.delivered_date', '>=', now()->subDays(60)->toDateString())
            ->where('t.is_active', true)
            ->where('t.trade_category', 'PURCHASE')
            ->where('t.trade_direction', 'NORMAL')
            ->where('ti.is_active', true)
            ->where('ti.quantity', '>', 0)
            ->whereNotNull('ti.item_id')
            ->orderByDesc('p.delivered_date')
            ->first(['ti.item_id', 'p.delivered_date']);

        if (! $latest) {
            $this->markTestSkipped('本部倉庫の直近の仕入データがありません。');
        }

        $itemId = (int) $latest->item_id;
        $purchases = app(HqOrderRegistrationReferenceService::class)->lastPurchases($hqWarehouseId, [$itemId]);

        $this->assertArrayHasKey($itemId, $purchases);
        $this->assertSame((string) $latest->delivered_date, $purchases[$itemId]['date']);
        $this->assertGreaterThan(0, $purchases[$itemId]['case_qty'] + $purchases[$itemId]['piece_qty']);
    }

    public function test_supplier_list_only_contains_suppliers_linked_to_given_contractors(): void
    {
        $hqWarehouseId = $this->hqWarehouseIdOrSkip();

        $contractorId = DB::connection('sakemaru')
            ->table('item_contractors')
            ->where('warehouse_id', $hqWarehouseId)
            ->value('contractor_id');

        if (! $contractorId) {
            $this->markTestSkipped('本部倉庫の商品発注先マスタがありません。');
        }

        $expectedSupplierIds = DB::connection('sakemaru')
            ->table('item_contractors')
            ->where('warehouse_id', $hqWarehouseId)
            ->where('contractor_id', $contractorId)
            ->distinct()
            ->pluck('supplier_id')
            ->map(fn ($id): int => (int) $id)
            ->sort()
            ->values()
            ->all();

        $selection = app(HqOrderRegistrationReferenceService::class)
            ->contractorSupplierLinks($hqWarehouseId, [(int) $contractorId]);

        $linkedSupplierIds = collect($selection['links'][(string) $contractorId] ?? [])->sort()->values()->all();
        $listedSupplierIds = collect($selection['suppliers'])->pluck('id')->sort()->values()->all();

        $this->assertSame([(string) $contractorId], array_map('strval', array_keys($selection['links'])));
        $this->assertSame($linkedSupplierIds, $listedSupplierIds);
        $this->assertSame(array_values(array_intersect($expectedSupplierIds, $linkedSupplierIds)), $linkedSupplierIds);
    }

    private function hqWarehouseIdOrSkip(): int
    {
        $warehouseId = app(HqOrderRegistrationReferenceService::class)->hqWarehouseId();

        if (! $warehouseId) {
            $this->markTestSkipped('本部倉庫（CD 91）のマスタがありません。');
        }

        return $warehouseId;
    }

    /**
     * @param  array<int, string>  $tables
     */
    private function skipUnlessTablesExist(array $tables): void
    {
        foreach ($tables as $table) {
            if (! Schema::connection('sakemaru')->hasTable($table)) {
                $this->markTestSkipped("テーブル {$table} が無いためスキップします。");
            }
        }
    }
}
