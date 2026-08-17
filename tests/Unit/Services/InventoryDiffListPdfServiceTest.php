<?php

namespace Tests\Unit\Services;

use App\Models\WmsInventoryCount;
use App\Models\WmsInventoryCountItem;
use App\Services\InventoryCount\InventoryDiffListPdfService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use ReflectionMethod;
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

    private function diffListItems(WmsInventoryCount $inventoryCount)
    {
        $method = new ReflectionMethod(InventoryDiffListPdfService::class, 'queryItems');
        $method->setAccessible(true);

        return $method->invoke(new InventoryDiffListPdfService, $inventoryCount);
    }
}
