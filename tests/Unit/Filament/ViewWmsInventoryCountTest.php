<?php

namespace Tests\Unit\Filament;

use App\Filament\Resources\WmsInventoryCount\Pages\ViewWmsInventoryCount;
use App\Filament\Resources\WmsInventoryCount\Pages\ViewWmsInventoryCountLogs;
use App\Models\WmsInventoryCount;
use App\Models\WmsInventoryCountItem;
use App\Models\WmsInventoryCountItemLog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class ViewWmsInventoryCountTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['sakemaru'];

    public function test_tab_visibility_uses_original_values_until_inline_save(): void
    {
        $blade = file_get_contents(resource_path('views/filament/resources/wms-inventory-count/pages/view-wms-inventory-count.blade.php'));

        $this->assertStringContainsString('row.originalEndDiff', $blade);
        $this->assertStringContainsString("row.origFirst !== ''", $blade);
        $this->assertStringContainsString("row.origSecond !== ''", $blade);
        $this->assertStringContainsString("row.origFinal !== ''", $blade);
        $this->assertStringContainsString('get originalEndDiff()', $blade);
        $this->assertStringNotContainsString("this.activeTab === 'diff' && !(row.endDiff", $blade);
        $this->assertStringNotContainsString("this.activeTab === 'uncounted' && this.activeRound === 1 && row.first !== ''", $blade);
    }

    public function test_inline_count_inputs_allow_negative_quantities(): void
    {
        $blade = file_get_contents(resource_path('views/filament/resources/wms-inventory-count/pages/view-wms-inventory-count.blade.php'));

        $this->assertStringContainsString("value === '-'", $blade);
        $this->assertStringContainsString("replace(/[^0-9-]/g,'')", $blade);
        $this->assertStringContainsString("['e','E','+','.']", $blade);
        $this->assertStringNotContainsString("['e','E','+','-','.']", $blade);
    }

    public function test_diff_pdf_action_uses_active_count_round(): void
    {
        $page = file_get_contents(app_path('Filament/Resources/WmsInventoryCount/Pages/ViewWmsInventoryCount.php'));

        $this->assertStringContainsString('->generate($record, $this->activeCountRound)', $page);
        $this->assertStringContainsString("\$filename = '棚卸差分確認_'.\$this->activeRoundLabel().'_'.", $page);
    }

    public function test_uncounted_pdf_action_uses_active_count_round(): void
    {
        $page = file_get_contents(app_path('Filament/Resources/WmsInventoryCount/Pages/ViewWmsInventoryCount.php'));

        $this->assertStringContainsString('->generateUncounted($record, $this->activeCountRound)', $page);
        $this->assertStringContainsString("\$filename = '棚卸未カウント_'.\$this->activeRoundLabel().'_'.", $page);
    }

    public function test_difference_workbook_action_is_placed_after_uncounted_pdf(): void
    {
        $page = file_get_contents(app_path('Filament/Resources/WmsInventoryCount/Pages/ViewWmsInventoryCount.php'));

        $uncountedPosition = strpos($page, "Action::make('downloadUncountedListPdf')");
        $workbookPosition = strpos($page, "Action::make('downloadDifferenceWorkbook')");

        $this->assertNotFalse($uncountedPosition);
        $this->assertNotFalse($workbookPosition);
        $this->assertGreaterThan($uncountedPosition, $workbookPosition);
        $this->assertStringContainsString('->label(\'差異データ\')', $page);
        $this->assertStringContainsString('InventoryDifferenceWorkbookService)->generate($record)', $page);
        $this->assertStringContainsString('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $page);

        $blade = file_get_contents(resource_path('views/filament/resources/wms-inventory-count/pages/view-wms-inventory-count.blade.php'));
        $bladeUncountedPosition = strpos($blade, "\$this->getAction('downloadUncountedListPdf')");
        $bladeWorkbookPosition = strpos($blade, "\$this->getAction('downloadDifferenceWorkbook')");

        $this->assertNotFalse($bladeUncountedPosition);
        $this->assertNotFalse($bladeWorkbookPosition);
        $this->assertGreaterThan($bladeUncountedPosition, $bladeWorkbookPosition);
    }

    public function test_second_round_difference_refresh_action_is_available_in_details(): void
    {
        $page = file_get_contents(app_path('Filament/Resources/WmsInventoryCount/Pages/ViewWmsInventoryCount.php'));

        $this->assertStringContainsString("Action::make('refreshSecondRoundConfirmedDifferences')", $page);
        $this->assertStringContainsString('refreshSecondRoundConfirmedDifferences($record)', $page);
        $this->assertStringContainsString('->label(\'2回目差異再計算\')', $page);

        $blade = file_get_contents(resource_path('views/filament/resources/wms-inventory-count/pages/view-wms-inventory-count.blade.php'));
        $theoryRefreshPosition = strpos($blade, "\$this->getAction('refreshDailySnapshotStock')");
        $differenceRefreshPosition = strpos($blade, "\$this->getAction('refreshSecondRoundConfirmedDifferences')");

        $this->assertNotFalse($theoryRefreshPosition);
        $this->assertNotFalse($differenceRefreshPosition);
        $this->assertGreaterThan($theoryRefreshPosition, $differenceRefreshPosition);
    }

    public function test_inline_save_accepts_negative_count_quantity(): void
    {
        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => 22,
            'warehouse_code' => '22',
            'warehouse_name' => '負数入力テスト倉庫',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_COUNTING,
            'current_count_round' => 1,
        ]);

        $item = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => 999601,
            'item_code' => 'NEG001',
            'item_name' => '負数入力対象',
            'system_quantity' => 10,
            'ending_system_quantity' => 10,
            'cost_price' => 10,
        ]);

        $page = new ViewWmsInventoryCount;
        $page->record = $inventoryCount;
        $page->activeCountRound = 1;

        $page->saveInlineChanges([
            $item->id => ['first' => -3],
        ]);

        $item->refresh();

        $this->assertSame(-3, $item->first_count_quantity);
        $this->assertSame(-13, $item->difference_quantity);
        $this->assertSame(1, $item->input_count);
    }

    public function test_difference_tabs_compare_active_round_with_ending_system_quantity(): void
    {
        if (! Schema::connection('sakemaru')->hasColumn('wms_inventory_count_items', 'ending_system_quantity')) {
            $this->markTestSkipped('wms_inventory_count_items.ending_system_quantity is not available.');
        }

        foreach ([
            'first_count_confirmed_system_quantity',
            'first_count_confirmed_difference_quantity',
            'first_count_confirmed_difference_amount',
        ] as $column) {
            if (! Schema::connection('sakemaru')->hasColumn('wms_inventory_count_items', $column)) {
                $this->markTestSkipped("wms_inventory_count_items.{$column} is not available.");
            }
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

    public function test_first_round_confirmation_copies_only_matched_items_to_second_round(): void
    {
        if (! Schema::connection('sakemaru')->hasColumn('wms_inventory_count_items', 'ending_system_quantity')) {
            $this->markTestSkipped('wms_inventory_count_items.ending_system_quantity is not available.');
        }

        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => 22,
            'warehouse_code' => '22',
            'warehouse_name' => '1回目確定テスト倉庫',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_COUNTING,
            'current_count_round' => 1,
        ]);

        $matched = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => 999501,
            'item_code' => 'ROUND001',
            'item_name' => '終了理論一致',
            'system_quantity' => 10,
            'ending_system_quantity' => 8,
            'first_count_quantity' => 8,
            'difference_quantity' => 99,
            'difference_amount' => 990,
            'cost_price' => 10,
        ]);

        $different = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => 999502,
            'item_code' => 'ROUND002',
            'item_name' => '終了理論差異あり',
            'system_quantity' => 10,
            'ending_system_quantity' => 8,
            'first_count_quantity' => 7,
            'difference_quantity' => 99,
            'difference_amount' => 990,
            'cost_price' => 10,
        ]);

        $uncounted = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => 999503,
            'item_code' => 'ROUND003',
            'item_name' => '未入力',
            'system_quantity' => 10,
            'ending_system_quantity' => 8,
            'cost_price' => 10,
        ]);

        $page = new ViewWmsInventoryCount;
        $page->record = $inventoryCount;
        $page->activeCountRound = 1;

        $page->confirmRound(1);

        $this->assertSame(2, $inventoryCount->refresh()->current_count_round);
        $this->assertSame(2, $page->activeCountRound);
        $this->assertNotNull($inventoryCount->first_count_confirmed_at);
        $this->assertSame(8, $matched->refresh()->second_count_quantity);
        $this->assertSame(8, $matched->first_count_confirmed_system_quantity);
        $this->assertSame(0, $matched->first_count_confirmed_difference_quantity);
        $this->assertSame('0.00', $matched->first_count_confirmed_difference_amount);
        $this->assertSame(99, $matched->difference_quantity);
        $this->assertNull($different->refresh()->second_count_quantity);
        $this->assertSame(8, $different->first_count_confirmed_system_quantity);
        $this->assertSame(-1, $different->first_count_confirmed_difference_quantity);
        $this->assertSame('-10.00', $different->first_count_confirmed_difference_amount);
        $this->assertSame(99, $different->difference_quantity);
        $this->assertNull($uncounted->refresh()->second_count_quantity);
        $this->assertNull($uncounted->first_count_confirmed_system_quantity);
        $this->assertNull($uncounted->first_count_confirmed_difference_quantity);
        $this->assertSame(1, $page->countForTab('matched'));
        $this->assertSame(2, $page->countForTab('uncounted'));

        $different->update(['ending_system_quantity' => 12]);
        $page->setActiveCountRound(1);

        $this->assertSame(1, $page->activeCountRound);
        $this->assertSame(1, $page->countForTab('diff'));
        $this->assertSame(1, $page->countForTab('matched'));
        $this->assertSame(-1, $page->roundDifferenceForDisplay($different->refresh(), 1));

        $confirmedAt = $inventoryCount->refresh()->first_count_confirmed_at;
        $page->confirmRound(1);

        $this->assertEquals($confirmedAt, $inventoryCount->refresh()->first_count_confirmed_at);
        $this->assertSame(-1, $different->refresh()->first_count_confirmed_difference_quantity);
    }

    public function test_second_round_confirmation_adopts_first_quantity_when_second_is_blank(): void
    {
        foreach ([
            'ending_system_quantity',
            'second_count_confirmed_system_quantity',
            'second_count_confirmed_difference_quantity',
            'second_count_confirmed_difference_amount',
        ] as $column) {
            if (! Schema::connection('sakemaru')->hasColumn('wms_inventory_count_items', $column)) {
                $this->markTestSkipped("wms_inventory_count_items.{$column} is not available.");
            }
        }

        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => 22,
            'warehouse_code' => '22',
            'warehouse_name' => '2回目確定テスト倉庫',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_COUNTING,
            'current_count_round' => 2,
            'first_count_confirmed_at' => now(),
        ]);

        $fallbackMatched = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => 999505,
            'item_code' => 'ROUND005',
            'item_name' => '2回目未入力1回目一致',
            'system_quantity' => 10,
            'ending_system_quantity' => 8,
            'first_count_quantity' => 8,
            'cost_price' => 10,
        ]);

        $fallbackDifferent = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => 999506,
            'item_code' => 'ROUND006',
            'item_name' => '2回目未入力1回目差異',
            'system_quantity' => 10,
            'ending_system_quantity' => 8,
            'first_count_quantity' => 7,
            'cost_price' => 10,
        ]);

        $secondDifferent = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => 999507,
            'item_code' => 'ROUND007',
            'item_name' => '2回目入力差異',
            'system_quantity' => 10,
            'ending_system_quantity' => 8,
            'first_count_quantity' => 7,
            'second_count_quantity' => 6,
            'cost_price' => 20,
        ]);

        $uncounted = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => 999508,
            'item_code' => 'ROUND008',
            'item_name' => '2回目未入力1回目なし',
            'system_quantity' => 10,
            'ending_system_quantity' => 8,
            'cost_price' => 10,
        ]);

        $page = new ViewWmsInventoryCount;
        $page->record = $inventoryCount;
        $page->activeCountRound = 2;

        $page->confirmRound(2);

        $this->assertSame(3, $inventoryCount->refresh()->current_count_round);
        $this->assertNotNull($inventoryCount->second_count_confirmed_at);
        $this->assertSame(8, $fallbackMatched->refresh()->second_count_confirmed_system_quantity);
        $this->assertSame(8, $fallbackMatched->second_count_quantity);
        $this->assertSame(0, $fallbackMatched->second_count_confirmed_difference_quantity);
        $this->assertSame('0.00', $fallbackMatched->second_count_confirmed_difference_amount);
        $this->assertSame(8, $fallbackMatched->final_count_quantity);
        $this->assertSame(8, $fallbackDifferent->refresh()->second_count_confirmed_system_quantity);
        $this->assertSame(7, $fallbackDifferent->second_count_quantity);
        $this->assertSame(-1, $fallbackDifferent->second_count_confirmed_difference_quantity);
        $this->assertSame('-10.00', $fallbackDifferent->second_count_confirmed_difference_amount);
        $this->assertNull($fallbackDifferent->final_count_quantity);
        $this->assertSame(8, $secondDifferent->refresh()->second_count_confirmed_system_quantity);
        $this->assertSame(-2, $secondDifferent->second_count_confirmed_difference_quantity);
        $this->assertSame('-40.00', $secondDifferent->second_count_confirmed_difference_amount);
        $this->assertNull($uncounted->refresh()->second_count_confirmed_difference_quantity);

        $page->setActiveCountRound(2);

        $this->assertSame(2, $page->countForTab('diff'));
        $this->assertSame(1, $page->countForTab('matched'));
        $this->assertSame(1, $page->countForTab('uncounted'));
        $this->assertSame(-1, $page->roundDifferenceForDisplay($fallbackDifferent->refresh(), 2));
    }

    public function test_reopen_final_round_clears_final_confirmed_difference_snapshot(): void
    {
        foreach ([
            'final_count_confirmed_system_quantity',
            'final_count_confirmed_difference_quantity',
            'final_count_confirmed_difference_amount',
        ] as $column) {
            if (! Schema::connection('sakemaru')->hasColumn('wms_inventory_count_items', $column)) {
                $this->markTestSkipped("wms_inventory_count_items.{$column} is not available.");
            }
        }

        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => 22,
            'warehouse_code' => '22',
            'warehouse_name' => '3回目再開テスト倉庫',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_CHECKED,
            'current_count_round' => 3,
            'final_count_confirmed_at' => now(),
        ]);

        $item = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => 999504,
            'item_code' => 'ROUND004',
            'item_name' => '3回目再開対象',
            'system_quantity' => 10,
            'ending_system_quantity' => 8,
            'final_count_quantity' => 7,
            'final_count_confirmed_system_quantity' => 8,
            'final_count_confirmed_difference_quantity' => -1,
            'final_count_confirmed_difference_amount' => -10,
            'cost_price' => 10,
        ]);

        $page = new ViewWmsInventoryCount;
        $page->record = $inventoryCount;
        $page->activeCountRound = 3;

        $page->reopenFinalRound();

        $this->assertSame(WmsInventoryCount::STATUS_COUNTING, $inventoryCount->refresh()->status);
        $this->assertNull($inventoryCount->final_count_confirmed_at);
        $this->assertNull($item->refresh()->final_count_confirmed_system_quantity);
        $this->assertNull($item->final_count_confirmed_difference_quantity);
        $this->assertNull($item->final_count_confirmed_difference_amount);
    }

    public function test_owned_set_items_are_excluded_from_inventory_count_rows(): void
    {
        if (! Schema::connection('sakemaru')->hasTable('item_sets')
            || ! Schema::connection('sakemaru')->hasColumn('items', 'item_set_id')
        ) {
            $this->markTestSkipped('item set tables are not available.');
        }

        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => 22,
            'warehouse_code' => '22',
            'warehouse_name' => '自社セット除外テスト倉庫',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_COUNTING,
            'current_count_round' => 1,
        ]);

        WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => 999901,
            'item_code' => 'VISIBLE001',
            'item_name' => '通常棚卸対象',
            'system_quantity' => 5,
            'ending_system_quantity' => 3,
            'first_count_quantity' => 5,
            'cost_price' => 10,
        ]);

        WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => $this->createOwnedSetItem(),
            'item_code' => 'OWNEDSET001',
            'item_name' => '自社セット対象外',
            'system_quantity' => 5,
            'ending_system_quantity' => 3,
            'first_count_quantity' => 5,
            'cost_price' => 10,
        ]);

        $page = new ViewWmsInventoryCount;
        $page->record = $inventoryCount;
        $page->activeCountRound = 1;

        $this->assertSame(1, $page->totalCount());
        $this->assertSame(1, $page->countForTab('diff'));
        $this->assertSame(['VISIBLE001'], collect($page->rows()->items())->pluck('item_code')->all());
    }

    public function test_owned_set_items_are_excluded_from_inventory_count_logs(): void
    {
        if (! Schema::connection('sakemaru')->hasTable('item_sets')
            || ! Schema::connection('sakemaru')->hasColumn('items', 'item_set_id')
        ) {
            $this->markTestSkipped('item set tables are not available.');
        }

        $inventoryCount = WmsInventoryCount::create([
            'count_no' => 'TST-'.Str::upper(Str::random(12)),
            'client_id' => 1,
            'warehouse_id' => 22,
            'warehouse_code' => '22',
            'warehouse_name' => '自社セットログ除外テスト倉庫',
            'count_date' => now()->toDateString(),
            'status' => WmsInventoryCount::STATUS_COUNTING,
            'current_count_round' => 1,
        ]);

        $visibleItem = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => 999902,
            'item_code' => 'VISIBLELOG',
            'item_name' => '通常ログ対象',
            'system_quantity' => 5,
            'ending_system_quantity' => 3,
            'first_count_quantity' => 4,
            'cost_price' => 10,
        ]);

        $ownedItem = WmsInventoryCountItem::create([
            'inventory_count_id' => $inventoryCount->id,
            'item_id' => $this->createOwnedSetItem(),
            'item_code' => 'OWNEDLOG',
            'item_name' => '自社セットログ対象外',
            'system_quantity' => 5,
            'ending_system_quantity' => 3,
            'first_count_quantity' => 4,
            'cost_price' => 10,
        ]);

        WmsInventoryCountItemLog::create([
            'inventory_count_item_id' => $visibleItem->id,
            'device_id' => 'WEB',
            'user_id' => null,
            'count_round' => 1,
            'old_quantity' => null,
            'new_quantity' => 4,
            'request_uuid' => (string) Str::uuid(),
            'created_at' => now(),
        ]);

        WmsInventoryCountItemLog::create([
            'inventory_count_item_id' => $ownedItem->id,
            'device_id' => 'WEB',
            'user_id' => null,
            'count_round' => 1,
            'old_quantity' => null,
            'new_quantity' => 4,
            'request_uuid' => (string) Str::uuid(),
            'created_at' => now(),
        ]);

        $page = new ViewWmsInventoryCountLogs;
        $page->record = $inventoryCount;

        $this->assertSame(1, $page->totalLogCount());
        $this->assertSame([$visibleItem->id], collect($page->logs()->items())->pluck('inventory_count_item_id')->all());
    }

    private function createOwnedSetItem(): int
    {
        $itemSetId = DB::connection('sakemaru')->table('item_sets')->insertGetId([
            'description' => '棚卸対象外自社セット',
            'set_type' => 'OWNED',
            'is_active' => true,
            'client_id' => 1,
            'creator_id' => 1,
            'last_updater_id' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) DB::connection('sakemaru')->table('items')->insertGetId([
            'name_main' => '自社セット対象外'.Str::upper(Str::random(8)),
            'code' => random_int(800000000, 899999999),
            'type' => 'NOT_ALCOHOL',
            'manufacturer_id' => 0,
            'volume' => 1,
            'capacity_case' => 1,
            'creator_id' => 1,
            'packaging' => '1',
            'nickname' => '自社セット対象外',
            'client_id' => 1,
            'item_set_id' => $itemSetId,
            'item_category1_id' => 0,
            'item_category2_id' => 0,
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
