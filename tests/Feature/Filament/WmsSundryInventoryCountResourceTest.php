<?php

namespace Tests\Feature\Filament;

use App\Filament\Resources\WmsInventoryCountResource;
use App\Filament\Resources\WmsSundryInventoryCount\Pages\ListWmsSundryInventoryCounts;
use App\Filament\Resources\WmsSundryInventoryCount\Pages\ViewWmsSundryInventoryCount;
use App\Filament\Resources\WmsSundryInventoryCountResource;
use App\Models\Sakemaru\User;
use App\Models\WmsSundryInventoryCount;
use App\Models\WmsSundryInventoryCountAmount;
use App\Models\WmsSundryInventoryCountItem;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Sakemaru\Auth\Services\PermissionService;
use Tests\TestCase;

class WmsSundryInventoryCountResourceTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['sakemaru'];

    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::connection('sakemaru')->hasTable('wms_sundry_inventory_counts')) {
            $this->markTestSkipped('wms_sundry_inventory_counts table is not available.');
        }

        $this->mock(PermissionService::class, function ($mock): void {
            $mock->shouldReceive('check')->andReturnTrue();
        });
    }

    public function test_resource_is_a_separate_menu_from_the_existing_inventory_count(): void
    {
        $this->assertSame('棚卸し（雑貨）', WmsSundryInventoryCountResource::getNavigationLabel());
        $this->assertSame(WmsInventoryCountResource::getNavigationGroup(), WmsSundryInventoryCountResource::getNavigationGroup());
        $this->assertSame('棚卸し', WmsInventoryCountResource::getNavigationLabel());
        $this->assertNotSame(WmsInventoryCountResource::getModel(), WmsSundryInventoryCountResource::getModel());
        $this->assertStringEndsWith('/wms-sundry-inventory-counts', WmsSundryInventoryCountResource::getUrl());
        $this->assertStringEndsWith('/wms-inventory-counts', WmsInventoryCountResource::getUrl());
    }

    public function test_list_page_renders_create_button(): void
    {
        // 一覧は既存の棚卸し一覧と同じ保存ビュー（AdvancedTables）を使うため、そのテーブルが無い環境では確認できない。
        if (! Schema::connection('sakemaru')->hasTable('wms_filament_filter_sets_managed_preset_views')) {
            $this->markTestSkipped('wms_filament_filter_sets_managed_preset_views table is not available.');
        }

        Livewire::actingAs($this->user())
            ->test(ListWmsSundryInventoryCounts::class)
            ->assertSuccessful()
            ->assertSee('棚卸し（雑貨）作成');
    }

    public function test_view_page_shows_amount_based_details_and_saves_input(): void
    {
        [$count, $item, $amount] = $this->sundryCount();

        $component = Livewire::actingAs($this->user())
            ->test(ViewWmsSundryInventoryCount::class, ['record' => $count])
            ->assertSuccessful()
            ->assertSee('数量明細（在庫管理あり）')
            ->assertSee('金額明細（在庫管理なし）')
            ->assertSee('集計')
            ->assertSee('990001')
            ->assertSee('雑貨画面テスト商品');

        $component
            ->call('saveChanges', [
                'items' => [$item->id => '8'],
                'amounts' => [$amount->id => ['counted_amount' => '900', 'opening_amount' => '1000']],
            ])
            ->assertReturned(true);

        $item->refresh();
        $this->assertSame(8.0, $item->counted_quantity);
        $this->assertSame(-2.0, $item->difference_quantity);
        $this->assertSame(-300.0, $item->difference_amount);

        $amount->refresh();
        $this->assertSame(1000.0, $amount->opening_amount);
        $this->assertSame(900.0, $amount->counted_amount);
        $this->assertSame(-100.0, $amount->difference_amount);

        $component
            ->call('setListTab', 'amounts')
            ->assertSee('中分類CD')
            ->assertSee('前残の出所')
            ->assertSee('手入力')
            ->assertSee('売上原価')
            ->assertSee('在庫金額報告書との突合（大分類別・参考）')
            ->assertSee('2026-05 月末')
            ->call('setListTab', 'summary')
            ->assertSee('在庫管理あり（中分類・数量）')
            ->assertSee('在庫管理なし（中分類・金額）')
            ->assertSee('在庫管理なし 計')
            ->assertSee('未入力の明細は実棚金額・差異金額に含めていません。');

        $component
            ->call('saveChanges', ['items' => [$item->id => 'x']])
            ->assertReturned(false);
        $this->assertSame(8.0, $item->fresh()->counted_quantity);
    }

    public function test_confirmed_count_is_read_only_on_the_page(): void
    {
        [$count, $item] = $this->sundryCount(WmsSundryInventoryCount::STATUS_CONFIRMED);

        Livewire::actingAs($this->user())
            ->test(ViewWmsSundryInventoryCount::class, ['record' => $count])
            ->assertSuccessful()
            ->assertSee('確定済のため入力できません。')
            ->call('saveChanges', ['items' => [$item->id => '1']])
            ->assertReturned(false);

        $this->assertNull($item->fresh()->counted_quantity);
    }

    public function test_weighed_view_page_takes_jar_and_reserve_input(): void
    {
        $count = WmsSundryInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'kind' => WmsSundryInventoryCount::KIND_WEIGHED,
            'client_id' => 1,
            'warehouse_id' => 990301,
            'warehouse_code' => '99',
            'warehouse_name' => '量り売り棚卸画面テスト倉庫',
            'count_date' => '2026-06-30',
            'status' => WmsSundryInventoryCount::STATUS_COUNTING,
            'category_ids' => [],
            'theory_end_date' => '2026-06-30',
            'sales_from_date' => '2026-03-01',
        ]);

        $item = (new WmsSundryInventoryCountItem([
            'sundry_inventory_count_id' => $count->id,
            'item_id' => 990129,
            'item_code' => '990129',
            'item_name' => '量り売り画面テスト焼酎',
            'category2_code' => '2012',
            'category2_name' => '焼酎',
            'display_order' => 1,
            'cost_price' => 50,
            'system_quantity' => 1000,
            'period_sales_quantity' => 500,
        ]))->recalculate();
        $item->save();

        $component = Livewire::actingAs($this->user())
            ->test(ViewWmsSundryInventoryCount::class, ['record' => $count])
            ->assertSuccessful()
            ->assertSee('棚卸し（量り売り）')
            ->assertSee('量り売り明細')
            ->assertSee('実棚数（カメ）')
            ->assertSee('実棚数（QT）')
            ->assertSee('期間売上数量')
            ->assertSee('量り売り画面テスト焼酎')
            ->assertSee('期間売上 2026/03/01')
            ->assertDontSee('金額明細（在庫管理なし）')
            ->assertDontSee('数量明細（在庫管理あり）');

        $component
            ->call('saveChanges', ['items' => [$item->id => ['jar' => '620', 'reserve' => '360']]])
            ->assertReturned(true);

        $item->refresh();
        $this->assertSame(620.0, $item->counted_quantity_jar);
        $this->assertSame(360.0, $item->counted_quantity_reserve);
        $this->assertSame(980.0, $item->counted_quantity);
        $this->assertSame(-20.0, $item->difference_quantity);
        $this->assertSame(-1000.0, $item->difference_amount);

        // 集計: 期間売上 500 × 4% = ロス 20 → ロス金額 1,000 → ロス申請後差異 0
        $component
            ->call('setListTab', 'summary')
            ->assertSee('ロス申請後 差異金額')
            ->assertSee('入力済みの明細だけを合計しています。')
            ->assertDontSee('在庫管理あり（中分類・数量）');

        $component
            ->call('saveChanges', ['items' => [$item->id => ['jar' => 'x']]])
            ->assertReturned(false);
        $this->assertSame(620.0, $item->fresh()->counted_quantity_jar);
    }

    /**
     * @return array{0: WmsSundryInventoryCount, 1: WmsSundryInventoryCountItem, 2: WmsSundryInventoryCountAmount}
     */
    private function sundryCount(string $status = WmsSundryInventoryCount::STATUS_COUNTING): array
    {
        $count = WmsSundryInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => 990301,
            'warehouse_code' => '99',
            'warehouse_name' => '雑貨棚卸画面テスト倉庫',
            'count_date' => '2026-06-30',
            'status' => $status,
            'category_ids' => [],
            'amount_category_ids' => [990004],
            'amount_report_references' => [[
                'category1_id' => 990004,
                'category1_code' => '1004',
                'category1_name' => '雑貨',
                'report_month' => '2026-05',
                'report_date' => '2026-05-31',
                'report_amount' => 5000,
                'managed_amount' => 1200,
                'opening_amount' => 3800,
                'flow_amount' => -100,
                'system_amount' => 3700,
            ]],
            'theory_end_date' => '2026-06-30',
        ]);

        $item = (new WmsSundryInventoryCountItem([
            'sundry_inventory_count_id' => $count->id,
            'item_id' => 990001,
            'item_code' => '990001',
            'item_name' => '雑貨画面テスト商品',
            'category2_code' => '2041',
            'category2_name' => '雑貨',
            'cost_price' => 150,
            'system_quantity' => 10,
        ]))->recalculate();
        $item->save();

        $amount = (new WmsSundryInventoryCountAmount([
            'sundry_inventory_count_id' => $count->id,
            'category1_id' => 990004,
            'category1_code' => '1004',
            'category1_name' => '雑貨',
            'category2_id' => 990041,
            'category2_code' => '2041',
            'category2_name' => '雑貨',
            'opening_date' => '2026-05-05',
            'opening_amount' => 0,
            'opening_source' => WmsSundryInventoryCountAmount::OPENING_SOURCE_LEGACY,
        ]))->recalculate();
        $amount->save();

        return [$count, $item, $amount];
    }

    private function user(): User
    {
        $warehouseId = DB::connection('sakemaru')->table('warehouses')->value('id');

        if (! $warehouseId) {
            $this->markTestSkipped('No warehouse is available.');
        }

        return User::create([
            'code' => 9999999800 + random_int(0, 99),
            'client_id' => 1,
            'name' => 'WMS_TEST_USER_'.uniqid(),
            'email' => 'wms-sundry-inventory-count-'.uniqid().'@test.local',
            'password' => bcrypt('test-password'),
            'is_active' => true,
            'default_warehouse_id' => $warehouseId,
            'creator_id' => 1,
            'last_updater_id' => 1,
        ]);
    }
}
