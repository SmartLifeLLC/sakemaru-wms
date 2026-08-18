<?php

namespace Tests\Unit\Services;

use App\Models\WmsInventoryCount;
use App\Models\WmsInventoryCountItem;
use App\Services\InventoryCount\InventoryDiffListPdfService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

class InventoryDiffListPdfServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['sakemaru'];

    public function test_diff_list_includes_only_ending_differences(): void
    {
        if (! Schema::connection('sakemaru')->hasColumn('wms_inventory_count_items', 'ending_system_quantity')) {
            $this->markTestSkipped('wms_inventory_count_items.ending_system_quantity is not available.');
        }

        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => 22,
            'warehouse_code' => '22',
            'warehouse_name' => 'PDF差異テスト倉庫',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_COUNTING,
        ]);

        $endOnly = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => 999201,
            'item_code' => 'PDF001',
            'item_name' => '終了差異のみ',
            'system_quantity' => 5,
            'ending_system_quantity' => 3,
            'final_count_quantity' => 5,
            'cost_price' => 10,
        ]);

        $startOnly = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => 999202,
            'item_code' => 'PDF002',
            'item_name' => '開始差異のみ',
            'system_quantity' => 5,
            'ending_system_quantity' => 7,
            'final_count_quantity' => 7,
            'cost_price' => 20,
        ]);

        $matched = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => 999203,
            'item_code' => 'PDF003',
            'item_name' => '差異なし',
            'system_quantity' => 4,
            'ending_system_quantity' => 4,
            'final_count_quantity' => 4,
            'cost_price' => 30,
        ]);

        $noEndingStock = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => 999204,
            'item_code' => 'PDF004',
            'item_name' => '終了理論なし',
            'system_quantity' => 4,
            'ending_system_quantity' => null,
            'final_count_quantity' => 8,
            'cost_price' => 40,
        ]);

        $items = $this->diffListItems($inventoryCount);

        $this->assertSame([$endOnly->id], $items->pluck('id')->all());
        $this->assertFalse($items->contains('id', $startOnly->id));
        $this->assertFalse($items->contains('id', $matched->id));
        $this->assertFalse($items->contains('id', $noEndingStock->id));

        $endOnlyRow = $items->firstWhere('id', $endOnly->id);

        $this->assertEquals(2.0, $endOnlyRow->getAttribute('pdf_end_difference_quantity'));
        $this->assertNull($endOnlyRow->getAttribute('pdf_start_difference_quantity'));
    }

    public function test_diff_list_can_be_generated_for_draft_with_no_difference_rows(): void
    {
        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => 22,
            'warehouse_code' => '22',
            'warehouse_name' => 'PDF空データテスト倉庫',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_DRAFT,
        ]);

        $pdf = (new InventoryDiffListPdfService)->generate($inventoryCount);

        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function test_diff_pdf_omits_money_columns_and_prints_jan_code(): void
    {
        if (! Schema::connection('sakemaru')->hasColumn('wms_inventory_count_items', 'ending_system_quantity')) {
            $this->markTestSkipped('wms_inventory_count_items.ending_system_quantity is not available.');
        }

        $pdftotext = trim((string) shell_exec('command -v pdftotext 2>/dev/null'));

        if ($pdftotext === '') {
            $this->markTestSkipped('pdftotext is not available.');
        }

        $itemId = random_int(900000000, 999999999);
        $janCode = '4999999999999';
        $productCode = 'PDFJAN'.Str::upper(Str::random(10));

        $quantityInformationId = DB::connection('sakemaru')->table('item_quantity_information')->insertGetId([
            'item_id' => $itemId,
            'product_code' => $productCode,
            'quantity_code' => '00',
            'dm_code' => '0',
            'own_code' => $janCode,
            'quantity' => 1,
            'creator_id' => 1,
            'last_updater_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::connection('sakemaru')->table('item_search_information')->insert([
            'client_id' => 1,
            'item_id' => $itemId,
            'code_type' => 'OTHER',
            'quantity_type' => 'PIECE',
            'item_quantity_information_id' => $quantityInformationId,
            'search_string' => $janCode,
            'creator_id' => 1,
            'last_updater_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => 22,
            'warehouse_code' => '22',
            'warehouse_name' => 'PDFバーコードテスト倉庫',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_COUNTING,
        ]);

        WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => $itemId,
            'item_code' => 'PDFJAN001',
            'item_name' => 'JAN表示差異',
            'system_quantity' => 5,
            'ending_system_quantity' => 3,
            'final_count_quantity' => 5,
            'cost_price' => 12345,
        ]);

        $text = $this->extractPdfText((new InventoryDiffListPdfService)->generate($inventoryCount), $pdftotext);

        $this->assertStringContainsString($janCode, $text);
        $this->assertStringContainsString('ロケ', $text);
        $this->assertStringNotContainsString('ロケーションNO', $text);
        $this->assertStringNotContainsString('仕入原価', $text);
        $this->assertStringNotContainsString('終了差額', $text);
        $this->assertStringNotContainsString('差異金額', $text);
    }

    public function test_uncounted_list_excludes_zero_system_quantity_without_difference_and_filters_major_categories(): void
    {
        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => 22,
            'warehouse_code' => '22',
            'warehouse_name' => '未PDFゼロ理論テスト倉庫',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_COUNTING,
        ]);

        $targetItemId = $this->createItemInMajorCategory(1001);
        $zeroSystemQuantityWithoutDifferenceItemId = $this->createItemInMajorCategory(1002);
        $zeroSystemQuantityWithDifferenceItemId = $this->createItemInMajorCategory(1006);
        $excludedItemId = $this->createItemInMajorCategory(9999);
        $countedItemId = $this->createItemInMajorCategory(1003);

        $target = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'real_stock_id' => random_int(900000000, 999999999),
            'item_id' => $targetItemId,
            'item_code' => 'UNC001',
            'item_name' => '未入力で理論あり',
            'system_quantity' => 3,
            'cost_price' => 10,
        ]);

        $zeroSystemQuantityWithoutDifference = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'real_stock_id' => $target->real_stock_id + 1,
            'item_id' => $zeroSystemQuantityWithoutDifferenceItemId,
            'item_code' => 'UNC002',
            'item_name' => '未入力で理論ゼロ差異なし',
            'system_quantity' => 0,
            'cost_price' => 20,
        ]);

        $zeroSystemQuantityWithDifference = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'real_stock_id' => $target->real_stock_id + 2,
            'item_id' => $zeroSystemQuantityWithDifferenceItemId,
            'item_code' => 'UNC003',
            'item_name' => '未入力で理論ゼロ差異あり',
            'system_quantity' => 0,
            'difference_quantity' => 1,
            'cost_price' => 30,
        ]);

        $excludedCategory = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'real_stock_id' => $target->real_stock_id + 3,
            'item_id' => $excludedItemId,
            'item_code' => 'UNC004',
            'item_name' => '対象外大分類',
            'system_quantity' => 5,
            'cost_price' => 40,
        ]);

        $counted = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'real_stock_id' => $target->real_stock_id + 4,
            'item_id' => $countedItemId,
            'item_code' => 'UNC005',
            'item_name' => '対象大分類だが入力済み',
            'system_quantity' => 0,
            'first_count_quantity' => 0,
            'cost_price' => 50,
        ]);

        $items = $this->uncountedListItems($inventoryCount, 1);

        $this->assertTrue($items->contains('id', $target->id));
        $this->assertTrue($items->contains('id', $zeroSystemQuantityWithDifference->id));
        $this->assertFalse($items->contains('id', $zeroSystemQuantityWithoutDifference->id));
        $this->assertFalse($items->contains('id', $excludedCategory->id));
        $this->assertFalse($items->contains('id', $counted->id));
    }

    public function test_multi_count_uncounted_list_excludes_items_counted_in_any_selected_count(): void
    {
        $firstInventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => 22,
            'warehouse_code' => '22',
            'warehouse_name' => '複数未PDFテスト倉庫',
            'count_date' => now()->subDay()->toDateString(),
            'status' => WmsInventoryCount::STATUS_COUNTING,
        ]);

        $secondInventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => 22,
            'warehouse_code' => '22',
            'warehouse_name' => '複数未PDFテスト倉庫',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_COUNTING,
        ]);

        $countedStockId = random_int(900000000, 999999999);
        $uncountedStockId = $countedStockId + 1;
        $zeroCountedStockId = $countedStockId + 2;
        $zeroSystemQuantityWithoutDifferenceStockId = $countedStockId + 3;
        $zeroSystemQuantityWithDifferenceStockId = $countedStockId + 4;
        $excludedCategoryStockId = $countedStockId + 5;
        $countedItemId = $this->createItemInMajorCategory(1001);
        $uncountedItemId = $this->createItemInMajorCategory(1002);
        $zeroCountedItemId = $this->createItemInMajorCategory(1003);
        $zeroSystemQuantityWithoutDifferenceItemId = $this->createItemInMajorCategory(1006);
        $zeroSystemQuantityWithDifferenceItemId = $this->createItemInMajorCategory(1001);
        $excludedItemId = $this->createItemInMajorCategory(9999);

        WmsInventoryCountItem::create([
            'inventory_count_id' => $firstInventoryCount->id,
            'real_stock_id' => $countedStockId,
            'item_id' => $countedItemId,
            'item_code' => 'BULK001',
            'item_name' => '別日で入力済み',
            'system_quantity' => 10,
            'cost_price' => 10,
        ]);

        WmsInventoryCountItem::create([
            'inventory_count_id' => $secondInventoryCount->id,
            'real_stock_id' => $countedStockId,
            'item_id' => $countedItemId,
            'item_code' => 'BULK001',
            'item_name' => '別日で入力済み',
            'system_quantity' => 10,
            'first_count_quantity' => 8,
            'input_count' => 1,
            'cost_price' => 10,
        ]);

        WmsInventoryCountItem::create([
            'inventory_count_id' => $firstInventoryCount->id,
            'real_stock_id' => $uncountedStockId,
            'item_id' => $uncountedItemId,
            'item_code' => 'BULK002',
            'item_name' => '全日未入力',
            'system_quantity' => 5,
            'cost_price' => 20,
        ]);

        $latestUncounted = WmsInventoryCountItem::create([
            'inventory_count_id' => $secondInventoryCount->id,
            'real_stock_id' => $uncountedStockId,
            'item_id' => $uncountedItemId,
            'item_code' => 'BULK002',
            'item_name' => '全日未入力',
            'location_code1' => 'A',
            'location_no' => 'A-01-01',
            'system_quantity' => 5,
            'cost_price' => 20,
        ]);

        WmsInventoryCountItem::create([
            'inventory_count_id' => $firstInventoryCount->id,
            'real_stock_id' => $zeroCountedStockId,
            'item_id' => $zeroCountedItemId,
            'item_code' => 'BULK003',
            'item_name' => 'ゼロ入力済み',
            'system_quantity' => 1,
            'first_count_quantity' => 0,
            'input_count' => 1,
            'cost_price' => 30,
        ]);

        $zeroSystemQuantityWithoutDifference = WmsInventoryCountItem::create([
            'inventory_count_id' => $secondInventoryCount->id,
            'real_stock_id' => $zeroSystemQuantityWithoutDifferenceStockId,
            'item_id' => $zeroSystemQuantityWithoutDifferenceItemId,
            'item_code' => 'BULK004',
            'item_name' => '理論ゼロ差異なし未入力',
            'system_quantity' => 0,
            'cost_price' => 40,
        ]);

        $zeroSystemQuantityWithDifference = WmsInventoryCountItem::create([
            'inventory_count_id' => $secondInventoryCount->id,
            'real_stock_id' => $zeroSystemQuantityWithDifferenceStockId,
            'item_id' => $zeroSystemQuantityWithDifferenceItemId,
            'item_code' => 'BULK005',
            'item_name' => '理論ゼロ差異あり未入力',
            'system_quantity' => 0,
            'difference_quantity' => 2,
            'cost_price' => 50,
        ]);

        WmsInventoryCountItem::create([
            'inventory_count_id' => $secondInventoryCount->id,
            'real_stock_id' => $excludedCategoryStockId,
            'item_id' => $excludedItemId,
            'item_code' => 'BULK006',
            'item_name' => '対象外大分類',
            'system_quantity' => 10,
            'cost_price' => 60,
        ]);

        $items = $this->multiCountUncountedItems(collect([$firstInventoryCount, $secondInventoryCount]), 1);

        $this->assertCount(2, $items);
        $this->assertTrue($items->contains('id', $latestUncounted->id));
        $this->assertTrue($items->contains('id', $zeroSystemQuantityWithDifference->id));
        $this->assertFalse($items->contains('real_stock_id', $countedStockId));
        $this->assertFalse($items->contains('real_stock_id', $zeroCountedStockId));
        $this->assertFalse($items->contains('real_stock_id', $zeroSystemQuantityWithoutDifference->real_stock_id));
        $this->assertFalse($items->contains('real_stock_id', $excludedCategoryStockId));

        $pdf = (new InventoryDiffListPdfService)->generateUncountedForCounts(collect([$firstInventoryCount, $secondInventoryCount]), 1);

        $this->assertStringStartsWith('%PDF', $pdf);
    }

    private function diffListItems(WmsInventoryCount $inventoryCount)
    {
        $method = new ReflectionMethod(InventoryDiffListPdfService::class, 'queryItems');
        $method->setAccessible(true);

        return $method->invoke(new InventoryDiffListPdfService, $inventoryCount);
    }

    private function uncountedListItems(WmsInventoryCount $inventoryCount, int $round)
    {
        $service = new InventoryDiffListPdfService;

        $property = new ReflectionProperty(InventoryDiffListPdfService::class, 'uncountedRound');
        $property->setAccessible(true);
        $property->setValue($service, $round);

        $method = new ReflectionMethod(InventoryDiffListPdfService::class, 'queryItems');
        $method->setAccessible(true);

        return $method->invoke($service, $inventoryCount);
    }

    private function multiCountUncountedItems($inventoryCounts, int $round)
    {
        $method = new ReflectionMethod(InventoryDiffListPdfService::class, 'queryMultiCountUncountedItems');
        $method->setAccessible(true);

        return $method->invoke(new InventoryDiffListPdfService, $inventoryCounts, $round);
    }

    private function extractPdfText(string $pdf, string $pdftotext): string
    {
        $pdfPath = tempnam(sys_get_temp_dir(), 'wms-diff-pdf-');
        $textPath = tempnam(sys_get_temp_dir(), 'wms-diff-text-');

        try {
            file_put_contents($pdfPath, $pdf);

            $command = escapeshellarg($pdftotext).' -layout '.escapeshellarg($pdfPath).' '.escapeshellarg($textPath);
            exec($command, $output, $exitCode);

            $this->assertSame(0, $exitCode, implode("\n", $output));

            return (string) file_get_contents($textPath);
        } finally {
            if (is_file($pdfPath)) {
                unlink($pdfPath);
            }

            if (is_file($textPath)) {
                unlink($textPath);
            }
        }
    }

    private function createItemInMajorCategory(int $majorCategoryCode): int
    {
        $majorCategoryId = DB::connection('sakemaru')->table('item_categories')->insertGetId([
            'client_id' => 1,
            'name' => '未PDF大分類'.$majorCategoryCode,
            'code' => $majorCategoryCode,
            'depth' => 1,
            'creator_id' => 1,
            'last_updater_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $middleCategoryId = DB::connection('sakemaru')->table('item_categories')->insertGetId([
            'client_id' => 1,
            'name' => '未PDF中分類'.$majorCategoryCode,
            'parent_id' => $majorCategoryId,
            'code' => random_int(100000, 999999),
            'depth' => 2,
            'creator_id' => 1,
            'last_updater_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::connection('sakemaru')->table('items')->insertGetId([
            'name_main' => '未PDF対象商品'.Str::upper(Str::random(8)),
            'code' => random_int(800000000, 899999999),
            'type' => 'NOT_ALCOHOL',
            'manufacturer_id' => 0,
            'volume' => 1,
            'capacity_case' => 1,
            'creator_id' => 1,
            'packaging' => '1',
            'nickname' => '未PDF対象',
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
