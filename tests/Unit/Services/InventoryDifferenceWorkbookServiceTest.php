<?php

namespace Tests\Unit\Services;

use App\Models\WmsInventoryCount;
use App\Models\WmsInventoryCountItem;
use App\Services\InventoryCount\InventoryDifferenceWorkbookService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

class InventoryDifferenceWorkbookServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['sakemaru'];

    public function test_difference_workbook_exports_diff_and_uncounted_sheets(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-08-20 10:00:00'));

        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => 22,
            'warehouse_code' => '22',
            'warehouse_name' => '差異データテスト倉庫',
            'count_date' => '2026-08-19',
            'status' => WmsInventoryCount::STATUS_COUNTING,
            'current_count_round' => 3,
            'first_count_confirmed_at' => now()->subHour(),
            'second_count_confirmed_at' => now(),
        ]);

        $targetItemId = $this->createItemInMajorCategory(1001);
        $matchedItemId = $this->createItemInMajorCategory(1002);
        $zeroSystemItemId = $this->createItemInMajorCategory(1003);
        $excludedCategoryItemId = $this->createItemInMajorCategory(9999);
        $ownedSetItemId = $this->createItemInMajorCategory(1001, true);

        $this->createItemPrice($targetItemId, '2026-08-18', 20);
        $this->createItemPrice($targetItemId, '2026-08-19', 24);
        $this->createItemPrice($targetItemId, '2026-08-19', 25);
        $this->createItemPrice($targetItemId, '2026-08-19', 999, false);
        $this->createItemPrice($targetItemId, '2026-08-20', 500);
        $expectedCostPrice = 500;

        WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'real_stock_id' => random_int(900000000, 999999999),
            'item_id' => $targetItemId,
            'item_code' => 'WB001',
            'item_name' => '差異データ対象商品',
            'location_id' => 1,
            'location_code1' => 'A',
            'location_code2' => '01',
            'location_code3' => '01',
            'location_no' => 'A0-01-01',
            'system_quantity' => 10,
            'ending_system_quantity' => 12,
            'first_count_quantity' => 7,
            'second_count_quantity' => 11,
            'final_count_quantity' => 5,
            'first_count_confirmed_system_quantity' => 10,
            'first_count_confirmed_difference_quantity' => -3,
            'first_count_confirmed_difference_amount' => -30,
            'second_count_confirmed_system_quantity' => 12,
            'second_count_confirmed_difference_quantity' => -1,
            'second_count_confirmed_difference_amount' => -10,
            'cost_price' => 10,
            'difference_quantity' => -1,
            'input_count' => 2,
        ]);

        WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'real_stock_id' => random_int(900000000, 999999999),
            'item_id' => $matchedItemId,
            'item_code' => 'WB002',
            'item_name' => '差異なし入力済み商品',
            'location_id' => 2,
            'location_code1' => 'A',
            'location_code2' => '01',
            'location_code3' => '02',
            'location_no' => 'A0-01-02',
            'system_quantity' => 12,
            'ending_system_quantity' => 12,
            'first_count_quantity' => 12,
            'second_count_quantity' => 12,
            'final_count_quantity' => 12,
            'first_count_confirmed_system_quantity' => 12,
            'first_count_confirmed_difference_quantity' => 0,
            'first_count_confirmed_difference_amount' => 0,
            'second_count_confirmed_system_quantity' => 12,
            'second_count_confirmed_difference_quantity' => 0,
            'second_count_confirmed_difference_amount' => 0,
            'cost_price' => 10,
            'input_count' => 3,
        ]);

        WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'real_stock_id' => random_int(900000000, 999999999),
            'item_id' => $zeroSystemItemId,
            'item_code' => 'WB003',
            'item_name' => '理論ゼロ差異ゼロ未入力商品',
            'location_id' => 3,
            'location_code1' => 'A',
            'location_code2' => '01',
            'location_code3' => '03',
            'location_no' => 'A0-01-03',
            'system_quantity' => 0,
            'ending_system_quantity' => 0,
            'cost_price' => 10,
            'input_count' => 0,
        ]);

        WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'real_stock_id' => random_int(900000000, 999999999),
            'item_id' => $excludedCategoryItemId,
            'item_code' => 'WB004',
            'item_name' => '未棚対象外大分類商品',
            'location_id' => 4,
            'location_code1' => 'A',
            'location_code2' => '01',
            'location_code3' => '04',
            'location_no' => 'A0-01-04',
            'system_quantity' => 5,
            'ending_system_quantity' => 5,
            'cost_price' => 10,
            'input_count' => 0,
        ]);

        WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'real_stock_id' => random_int(900000000, 999999999),
            'item_id' => $targetItemId,
            'item_code' => 'WB005',
            'item_name' => '終了理論未取得商品',
            'location_id' => 5,
            'location_code1' => 'A',
            'location_code2' => '01',
            'location_code3' => '05',
            'location_no' => 'A0-01-05',
            'system_quantity' => 10,
            'ending_system_quantity' => null,
            'first_count_quantity' => 1,
            'first_count_confirmed_system_quantity' => 10,
            'first_count_confirmed_difference_quantity' => -9,
            'first_count_confirmed_difference_amount' => -90,
            'cost_price' => 10,
            'input_count' => 1,
        ]);

        WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'real_stock_id' => random_int(900000000, 999999999),
            'item_id' => $targetItemId,
            'item_code' => 'WB006',
            'item_name' => '3回目未確定差異商品',
            'location_id' => 6,
            'location_code1' => 'A',
            'location_code2' => '01',
            'location_code3' => '06',
            'location_no' => 'A0-01-06',
            'system_quantity' => 12,
            'ending_system_quantity' => 12,
            'first_count_quantity' => 12,
            'second_count_quantity' => 12,
            'final_count_quantity' => 5,
            'first_count_confirmed_system_quantity' => 12,
            'first_count_confirmed_difference_quantity' => 0,
            'first_count_confirmed_difference_amount' => 0,
            'second_count_confirmed_system_quantity' => 12,
            'second_count_confirmed_difference_quantity' => 0,
            'second_count_confirmed_difference_amount' => 0,
            'cost_price' => 10,
            'input_count' => 3,
        ]);

        WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'real_stock_id' => random_int(900000000, 999999999),
            'item_id' => $ownedSetItemId,
            'item_code' => 'WBOWN001',
            'item_name' => '自社セット対象外商品',
            'system_quantity' => 10,
            'ending_system_quantity' => 8,
            'first_count_quantity' => 10,
            'first_count_confirmed_system_quantity' => 8,
            'first_count_confirmed_difference_quantity' => 2,
            'first_count_confirmed_difference_amount' => 20,
            'cost_price' => 10,
            'difference_quantity' => 2,
            'input_count' => 1,
        ]);

        $workbook = $this->loadWorkbook((new InventoryDifferenceWorkbookService)->generate($inventoryCount));

        $this->assertSame(['部門別', '集計', '差異', '未棚'], $workbook->getSheetNames());

        $departmentRows = $this->rowsByColumn($workbook->getSheetByName('部門別'), '部門CD');
        $this->assertArrayHasKey('1001', $departmentRows);
        $this->assertArrayHasKey('合計', $departmentRows);
        $this->assertSame('差異データ大分類1001', $departmentRows['1001']['部門名']);
        $this->assertSame(3, $departmentRows['1001']['総数']);
        $this->assertEquals(34 * $expectedCostPrice, $departmentRows['1001']['CP在庫金額']);
        $this->assertSame(2, $departmentRows['1001']['1回目差異数']);
        $this->assertEqualsWithDelta(2 / 3, $departmentRows['1001']['1回目差異率'], 0.0000001);
        $this->assertEquals(-12 * $expectedCostPrice, $departmentRows['1001']['1回目±不明差異金額']);
        $this->assertEqualsWithDelta((-12 * $expectedCostPrice) / (34 * $expectedCostPrice), $departmentRows['1001']['1回目±在庫差異率'], 0.0000001);
        $this->assertEquals(12 * $expectedCostPrice, $departmentRows['1001']['1回目絶対値不明差異金額']);
        $this->assertEqualsWithDelta((12 * $expectedCostPrice) / (34 * $expectedCostPrice), $departmentRows['1001']['1回目絶対値在庫差異率'], 0.0000001);
        $this->assertSame(2, $departmentRows['1001']['2回目差異数']);
        $this->assertEquals(-10 * $expectedCostPrice, $departmentRows['1001']['2回目±不明差異金額']);
        $this->assertEquals(10 * $expectedCostPrice, $departmentRows['1001']['2回目絶対値不明差異金額']);
        $this->assertSame(6, $departmentRows['合計']['総数']);
        $this->assertEquals(34 * $expectedCostPrice, $departmentRows['合計']['CP在庫金額']);

        $summaryRows = $this->rowsByColumn($workbook->getSheetByName('集計'), '区分');
        $this->assertArrayHasKey('全体', $summaryRows);
        $this->assertArrayHasKey('差異あり', $summaryRows);
        $this->assertArrayHasKey('未棚', $summaryRows);
        $this->assertEquals(34 * $expectedCostPrice, $summaryRows['全体']['CP在庫金額']);
        $this->assertEquals(12 * $expectedCostPrice, $summaryRows['差異あり']['CP在庫金額']);
        $this->assertEquals(10 * $expectedCostPrice, $summaryRows['未棚']['CP在庫金額']);
        $this->assertEquals(-12 * $expectedCostPrice, $summaryRows['全体']['1回目±差異金額']);
        $this->assertEquals(12 * $expectedCostPrice, $summaryRows['全体']['1回目絶対差異金額']);
        $this->assertEquals(-10 * $expectedCostPrice, $summaryRows['全体']['2回目±差異金額']);
        $this->assertEquals(10 * $expectedCostPrice, $summaryRows['全体']['2回目絶対差異金額']);

        $diffHeaders = $this->sheetHeaders($workbook->getSheetByName('差異'));
        $this->assertSame('棚卸しNo', $diffHeaders[0]);
        $this->assertNotContains('差異回', $diffHeaders);
        $this->assertNotContains('最大絶対差異', $diffHeaders);
        $this->assertNotContains('グループ', $diffHeaders);
        $this->assertNotContains('ロケ', $diffHeaders);
        $this->assertNotContains('ロットNO', $diffHeaders);
        $this->assertNotContains('賞味期限', $diffHeaders);
        $this->assertContains('大分類CD', $diffHeaders);
        $this->assertContains('大分類名', $diffHeaders);
        $this->assertContains('原価', $diffHeaders);
        $this->assertContains('CP在庫金額', $diffHeaders);
        $this->assertContains('1回目±差異金額', $diffHeaders);
        $this->assertContains('1回目絶対差異金額', $diffHeaders);

        $diffRows = $this->rowsByItemCode($workbook->getSheetByName('差異'));
        $this->assertArrayHasKey('WB001', $diffRows);
        $this->assertArrayNotHasKey('WB002', $diffRows);
        $this->assertArrayNotHasKey('WB005', $diffRows);
        $this->assertArrayNotHasKey('WB006', $diffRows);
        $this->assertArrayNotHasKey('WBOWN001', $diffRows);

        $this->assertSame(12, $diffRows['WB001']['理論在庫']);
        $this->assertSame('1001', $diffRows['WB001']['大分類CD']);
        $this->assertSame('差異データ大分類1001', $diffRows['WB001']['大分類名']);
        $this->assertEquals($expectedCostPrice, $diffRows['WB001']['原価']);
        $this->assertEquals(12 * $expectedCostPrice, $diffRows['WB001']['CP在庫金額']);
        $this->assertSame(7, $diffRows['WB001']['1回目数量']);
        $this->assertSame(-3, $diffRows['WB001']['1回目±差異']);
        $this->assertSame(3, $diffRows['WB001']['1回目絶対差異']);
        $this->assertEquals(-3 * $expectedCostPrice, $diffRows['WB001']['1回目±差異金額']);
        $this->assertEquals(3 * $expectedCostPrice, $diffRows['WB001']['1回目絶対差異金額']);
        $this->assertSame(11, $diffRows['WB001']['2回目数量']);
        $this->assertSame(-1, $diffRows['WB001']['2回目±差異']);
        $this->assertSame(1, $diffRows['WB001']['2回目絶対差異']);
        $this->assertEquals(-1 * $expectedCostPrice, $diffRows['WB001']['2回目±差異金額']);
        $this->assertEquals($expectedCostPrice, $diffRows['WB001']['2回目絶対差異金額']);
        $this->assertNull($diffRows['WB001']['3回目数量']);
        $this->assertNull($diffRows['WB001']['3回目±差異']);
        $this->assertNull($diffRows['WB001']['3回目±差異金額']);

        $uncountedHeaders = $this->sheetHeaders($workbook->getSheetByName('未棚'));
        $this->assertSame('未入力回', $uncountedHeaders[0]);
        $this->assertNotContains('グループ', $uncountedHeaders);
        $this->assertNotContains('ロケ', $uncountedHeaders);
        $this->assertNotContains('ロットNO', $uncountedHeaders);
        $this->assertNotContains('賞味期限', $uncountedHeaders);
        $this->assertContains('大分類CD', $uncountedHeaders);
        $this->assertContains('大分類名', $uncountedHeaders);
        $this->assertContains('原価', $uncountedHeaders);
        $this->assertContains('CP在庫金額', $uncountedHeaders);

        $uncountedRows = $this->rowsByItemCode($workbook->getSheetByName('未棚'));
        $this->assertArrayNotHasKey('WB001', $uncountedRows);
        $this->assertArrayNotHasKey('WB002', $uncountedRows);
        $this->assertArrayHasKey('WB003', $uncountedRows);
        $this->assertArrayHasKey('WB004', $uncountedRows);
        $this->assertArrayHasKey('WB005', $uncountedRows);
        $this->assertArrayNotHasKey('WB006', $uncountedRows);
        $this->assertArrayNotHasKey('WBOWN001', $uncountedRows);
        $this->assertSame('1回目,2回目', $uncountedRows['WB003']['未入力回']);
        $this->assertSame('1回目,2回目', $uncountedRows['WB004']['未入力回']);
        $this->assertSame('2回目', $uncountedRows['WB005']['未入力回']);
        $this->assertSame('1001', $uncountedRows['WB005']['大分類CD']);
        $this->assertSame('差異データ大分類1001', $uncountedRows['WB005']['大分類名']);
        $this->assertEquals($expectedCostPrice, $uncountedRows['WB005']['原価']);
        $this->assertEquals(10 * $expectedCostPrice, $uncountedRows['WB005']['CP在庫金額']);
    }

    public function test_difference_workbook_exports_empty_sheets(): void
    {
        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => 22,
            'warehouse_code' => '22',
            'warehouse_name' => '空差異データテスト倉庫',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_COUNTING,
            'current_count_round' => 1,
        ]);

        $workbook = $this->loadWorkbook((new InventoryDifferenceWorkbookService)->generate($inventoryCount));

        $this->assertSame(['部門別', '集計', '差異', '未棚'], $workbook->getSheetNames());
        $this->assertSame(2, $workbook->getSheetByName('部門別')->getHighestRow());
        $this->assertSame(4, $workbook->getSheetByName('集計')->getHighestRow());
        $this->assertSame(1, $workbook->getSheetByName('差異')->getHighestRow());
        $this->assertSame(1, $workbook->getSheetByName('未棚')->getHighestRow());
    }

    private function loadWorkbook(string $content): Spreadsheet
    {
        $path = tempnam(sys_get_temp_dir(), 'wms-inventory-diff-xlsx-');

        try {
            file_put_contents($path, $content);

            return IOFactory::load($path);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * @return array<int, string>
     */
    private function sheetHeaders(?Worksheet $sheet): array
    {
        $this->assertNotNull($sheet);

        $rows = $sheet->toArray(null, true, false, false);

        return array_values(array_filter($rows[0] ?? [], fn ($value): bool => $value !== null && $value !== ''));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function rowsByItemCode(?Worksheet $sheet): array
    {
        return $this->rowsByColumn($sheet, '商品CD');
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function rowsByColumn(?Worksheet $sheet, string $keyColumn): array
    {
        $this->assertNotNull($sheet);

        $rawRows = $sheet->toArray(null, true, false, true);
        $headers = array_shift($rawRows);
        $rows = [];

        foreach ($rawRows as $rawRow) {
            if (! collect($rawRow)->filter(fn ($value): bool => $value !== null && $value !== '')->isNotEmpty()) {
                continue;
            }

            $row = [];
            foreach ($headers as $column => $label) {
                $row[$label] = $rawRow[$column] ?? null;
            }

            $rows[(string) $row[$keyColumn]] = $row;
        }

        return $rows;
    }

    private function createItemInMajorCategory(int $majorCategoryCode, bool $ownedSet = false): int
    {
        $majorCategoryId = DB::connection('sakemaru')->table('item_categories')->insertGetId([
            'client_id' => 1,
            'name' => '差異データ大分類'.$majorCategoryCode,
            'code' => $majorCategoryCode,
            'depth' => 1,
            'creator_id' => 1,
            'last_updater_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $middleCategoryId = DB::connection('sakemaru')->table('item_categories')->insertGetId([
            'client_id' => 1,
            'name' => '差異データ中分類'.$majorCategoryCode,
            'parent_id' => $majorCategoryId,
            'code' => random_int(100000, 999999),
            'depth' => 2,
            'creator_id' => 1,
            'last_updater_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $itemSetId = $ownedSet ? $this->createOwnedItemSet() : null;

        return DB::connection('sakemaru')->table('items')->insertGetId([
            'name_main' => '差異データ対象商品'.Str::upper(Str::random(8)),
            'code' => random_int(800000000, 899999999),
            'type' => 'NOT_ALCOHOL',
            'manufacturer_id' => 0,
            'volume' => 1,
            'capacity_case' => 1,
            'creator_id' => 1,
            'packaging' => '1',
            'nickname' => '差異データ対象',
            'client_id' => 1,
            'item_set_id' => $itemSetId,
            'item_category1_id' => $majorCategoryId,
            'item_category2_id' => $middleCategoryId,
            'container_type_id' => 0,
            'manufacture_type_id' => 0,
            'storage_type_id' => 0,
            'measurement_unit_weight' => 0,
            'measurement_case_weight' => 0,
            'order_rank' => 'ORDER_MANUAL',
            'last_updater_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createOwnedItemSet(): int
    {
        return (int) DB::connection('sakemaru')->table('item_sets')->insertGetId([
            'description' => '棚卸対象外自社セット',
            'set_type' => 'OWNED',
            'is_active' => true,
            'client_id' => 1,
            'creator_id' => 1,
            'last_updater_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function createItemPrice(int $itemId, string $startDate, float $costUnitPrice, bool $isActive = true): int
    {
        return (int) DB::connection('sakemaru')->table('item_prices')->insertGetId([
            'item_id' => $itemId,
            'start_date' => $startDate,
            'producer_unit_price' => 0,
            'producer_case_price' => 0,
            'producer_crate_price' => 0,
            'cost_unit_price' => $costUnitPrice,
            'wholesale_unit_price' => 0,
            'sale_unit_price' => 0,
            'sub_unit_price' => 0,
            'retail_unit_price' => 0,
            'tax_exempt_unit_price' => 0,
            'type' => 'EXEMPT',
            'client_id' => 1,
            'creator_id' => 0,
            'is_active' => $isActive,
            'created_at' => now(),
            'updated_at' => now(),
            'is_created_from_data_transfer' => false,
            'last_updater_id' => 0,
        ]);
    }
}
