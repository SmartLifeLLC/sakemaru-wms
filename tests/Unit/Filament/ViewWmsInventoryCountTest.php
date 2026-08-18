<?php

namespace Tests\Unit\Filament;

use App\Filament\Resources\WmsInventoryCount\Pages\ViewWmsInventoryCount;
use App\Models\WmsInventoryCount;
use App\Models\WmsInventoryCountItem;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ViewWmsInventoryCountTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['sakemaru'];

    public function test_difference_tabs_compare_active_round_with_ending_system_quantity(): void
    {
        if (! Schema::connection('sakemaru')->hasColumn('wms_inventory_count_items', 'ending_system_quantity')) {
            $this->markTestSkipped('wms_inventory_count_items.ending_system_quantity is not available.');
        }

        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => 22,
            'warehouse_code' => '22',
            'warehouse_name' => '終了差異タブテスト倉庫',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_COUNTING,
            'current_count_round' => 1,
        ]);

        WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => 999401,
            'item_code' => 'TAB001',
            'item_name' => '終了差異あり',
            'system_quantity' => 5,
            'ending_system_quantity' => 3,
            'first_count_quantity' => 5,
            'difference_quantity' => 0,
            'cost_price' => 10,
        ]);

        WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => 999402,
            'item_code' => 'TAB002',
            'item_name' => '開始差異のみ',
            'system_quantity' => 5,
            'ending_system_quantity' => 7,
            'first_count_quantity' => 7,
            'difference_quantity' => 2,
            'cost_price' => 20,
        ]);

        WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => 999403,
            'item_code' => 'TAB003',
            'item_name' => '終了理論なし',
            'system_quantity' => 5,
            'ending_system_quantity' => null,
            'first_count_quantity' => 10,
            'difference_quantity' => 5,
            'cost_price' => 30,
        ]);

        $page = new ViewWmsInventoryCount;
        $page->record = $inventoryCount;
        $page->activeCountRound = 1;

        $this->assertSame(1, $page->countForTab('diff'));
        $this->assertSame(1, $page->countForTab('matched'));
    }
}
