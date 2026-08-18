<?php

namespace Tests\Unit\Services;

use App\Models\WmsInventoryCount;
use App\Models\WmsInventoryCountItem;
use App\Services\InventoryCount\InventoryDifferenceWorkbookService;
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
        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => 22,
            'warehouse_code' => '22',
            'warehouse_name' => '差異データテスト倉庫',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_COUNTING,
            'current_count_round' => 3,
            'first_count_confirmed_at' => now()->subHour(),
            'second_count_confirmed_at' => now(),
        ]);

        $targetItemId = $this->createItemInMajorCategory(1001);
        $matchedItemId = $this->createItemInMajorCategory(1002);
        $zeroSystemItemId = $this->createItemInMajorCategory(1003);
        $excludedCategoryItemId = $this->createItemInMajorCategory(9999);

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

        $workbook = $this->loadWorkbook((new InventoryDifferenceWorkbookService)->generate($inventoryCount));

        $this->assertSame(['差異', '未棚'], $workbook->getSheetNames());

        $diffHeaders = $this->sheetHeaders($workbook->getSheetByName('差異'));
        $this->assertSame('棚卸しNo', $diffHeaders[0]);
        $this->assertNotContains('差異回', $diffHeaders);
        $this->assertNotContains('最大絶対差異', $diffHeaders);
        $this->assertNotContains('グループ', $diffHeaders);
        $this->assertNotContains('ロケ', $diffHeaders);
        $this->assertNotContains('ロットNO', $diffHeaders);
        $this->assertNotContains('賞味期限', $diffHeaders);

        $diffRows = $this->rowsByItemCode($workbook->getSheetByName('差異'));
        $this->assertArrayHasKey('WB001', $diffRows);
        $this->assertArrayNotHasKey('WB002', $diffRows);
        $this->assertArrayNotHasKey('WB005', $diffRows);
        $this->assertArrayNotHasKey('WB006', $diffRows);

        $this->assertSame(12, $diffRows['WB001']['理論在庫']);
        $this->assertSame(7, $diffRows['WB001']['1回目数量']);
        $this->assertSame(-3, $diffRows['WB001']['1回目±差異']);
        $this->assertSame(3, $diffRows['WB001']['1回目絶対差異']);
        $this->assertSame(11, $diffRows['WB001']['2回目数量']);
        $this->assertSame(-1, $diffRows['WB001']['2回目±差異']);
        $this->assertSame(1, $diffRows['WB001']['2回目絶対差異']);
        $this->assertNull($diffRows['WB001']['3回目数量']);
        $this->assertNull($diffRows['WB001']['3回目±差異']);

        $uncountedHeaders = $this->sheetHeaders($workbook->getSheetByName('未棚'));
        $this->assertSame('未入力回', $uncountedHeaders[0]);
        $this->assertNotContains('グループ', $uncountedHeaders);
        $this->assertNotContains('ロケ', $uncountedHeaders);
        $this->assertNotContains('ロットNO', $uncountedHeaders);
        $this->assertNotContains('賞味期限', $uncountedHeaders);

        $uncountedRows = $this->rowsByItemCode($workbook->getSheetByName('未棚'));
        $this->assertArrayNotHasKey('WB001', $uncountedRows);
        $this->assertArrayNotHasKey('WB002', $uncountedRows);
        $this->assertArrayNotHasKey('WB003', $uncountedRows);
        $this->assertArrayNotHasKey('WB004', $uncountedRows);
        $this->assertArrayHasKey('WB005', $uncountedRows);
        $this->assertArrayNotHasKey('WB006', $uncountedRows);
        $this->assertSame('2回目', $uncountedRows['WB005']['未入力回']);
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

        $this->assertSame(['差異', '未棚'], $workbook->getSheetNames());
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

            $rows[(string) $row['商品CD']] = $row;
        }

        return $rows;
    }

    private function createItemInMajorCategory(int $majorCategoryCode): int
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
}
