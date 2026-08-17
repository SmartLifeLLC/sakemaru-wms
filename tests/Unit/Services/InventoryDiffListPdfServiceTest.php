<?php

namespace Tests\Unit\Services;

use App\Models\WmsInventoryCount;
use App\Models\WmsInventoryCountItem;
use App\Services\InventoryCount\InventoryDiffListPdfService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionMethod;
use ReflectionProperty;
use Tests\TestCase;

class InventoryDiffListPdfServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['sakemaru'];

    public function test_diff_list_includes_start_and_end_differences(): void
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

        $items = $this->diffListItems($inventoryCount);

        $this->assertSame([$endOnly->id, $startOnly->id], $items->pluck('id')->all());
        $this->assertFalse($items->contains('id', $matched->id));

        $endOnlyRow = $items->firstWhere('id', $endOnly->id);
        $startOnlyRow = $items->firstWhere('id', $startOnly->id);

        $this->assertEquals(0.0, $endOnlyRow->getAttribute('pdf_start_difference_quantity'));
        $this->assertEquals(2.0, $endOnlyRow->getAttribute('pdf_end_difference_quantity'));
        $this->assertEquals(2.0, $startOnlyRow->getAttribute('pdf_start_difference_quantity'));
        $this->assertEquals(0.0, $startOnlyRow->getAttribute('pdf_end_difference_quantity'));
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

    public function test_uncounted_list_excludes_zero_system_quantity_items(): void
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

        $target = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'real_stock_id' => random_int(900000000, 999999999),
            'item_id' => 999251,
            'item_code' => 'UNC001',
            'item_name' => '未入力で理論あり',
            'system_quantity' => 3,
            'cost_price' => 10,
        ]);

        $zeroSystemQuantity = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'real_stock_id' => $target->real_stock_id + 1,
            'item_id' => 999252,
            'item_code' => 'UNC002',
            'item_name' => '未入力で理論ゼロ',
            'system_quantity' => 0,
            'cost_price' => 20,
        ]);

        $items = $this->uncountedListItems($inventoryCount, 1);

        $this->assertTrue($items->contains('id', $target->id));
        $this->assertFalse($items->contains('id', $zeroSystemQuantity->id));
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
        $zeroSystemQuantityStockId = $countedStockId + 3;

        WmsInventoryCountItem::create([
            'inventory_count_id' => $firstInventoryCount->id,
            'real_stock_id' => $countedStockId,
            'item_id' => 999301,
            'item_code' => 'BULK001',
            'item_name' => '別日で入力済み',
            'system_quantity' => 10,
            'cost_price' => 10,
        ]);

        WmsInventoryCountItem::create([
            'inventory_count_id' => $secondInventoryCount->id,
            'real_stock_id' => $countedStockId,
            'item_id' => 999301,
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
            'item_id' => 999302,
            'item_code' => 'BULK002',
            'item_name' => '全日未入力',
            'system_quantity' => 5,
            'cost_price' => 20,
        ]);

        $latestUncounted = WmsInventoryCountItem::create([
            'inventory_count_id' => $secondInventoryCount->id,
            'real_stock_id' => $uncountedStockId,
            'item_id' => 999302,
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
            'item_id' => 999303,
            'item_code' => 'BULK003',
            'item_name' => 'ゼロ入力済み',
            'system_quantity' => 1,
            'first_count_quantity' => 0,
            'input_count' => 1,
            'cost_price' => 30,
        ]);

        WmsInventoryCountItem::create([
            'inventory_count_id' => $secondInventoryCount->id,
            'real_stock_id' => $zeroSystemQuantityStockId,
            'item_id' => 999304,
            'item_code' => 'BULK004',
            'item_name' => '理論ゼロ未入力',
            'system_quantity' => 0,
            'cost_price' => 40,
        ]);

        $items = $this->multiCountUncountedItems(collect([$firstInventoryCount, $secondInventoryCount]), 1);

        $this->assertSame([$latestUncounted->id], $items->pluck('id')->all());
        $this->assertFalse($items->contains('real_stock_id', $countedStockId));
        $this->assertFalse($items->contains('real_stock_id', $zeroCountedStockId));
        $this->assertFalse($items->contains('real_stock_id', $zeroSystemQuantityStockId));

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
}
