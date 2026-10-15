<?php

namespace Tests\Unit\Services;

use App\Filament\Resources\WmsInventoryCount\Pages\ViewWmsInventoryCount;
use App\Http\Controllers\Api\InventoryCountController;
use App\Models\WmsInventoryCount;
use App\Models\WmsInventoryCountItem;
use App\Services\InventoryCount\InventoryCountFinalizationService;
use App\Services\InventoryCount\InventoryCountLedgerBalanceService;
use App\Services\InventoryCount\InventoryCountService;
use App\Services\InventoryCount\InventoryDiffListPdfService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InventoryCountFinalizationServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['sakemaru'];

    protected function setUp(): void
    {
        parent::setUp();
        if (! DB::connection('sakemaru')->table('clients')->where('id', 1)->exists()) {
            DB::connection('sakemaru')->table('clients')->insert(['id' => 1, 'code' => 1, 'name' => 'Finalization test']);
        }
        $allocations = DB::connection('sakemaru')->table('stock_allocations');
        if (! (clone $allocations)->where('client_id', 1)->where('code', 1)->exists()) {
            $allocations->insert(['client_id' => 1, 'code' => 1, 'name' => 'Finalization test']);
        }
        $this->mock(InventoryCountLedgerBalanceService::class)
            ->shouldReceive('balancesBeforeAdjustmentByItem')->andReturn([])->byDefault();
    }

    public function test_finalization_matches_pdf_preserves_rounds_and_only_queues_relative_differences(): void
    {
        $count = $this->createCount();
        $entered = $this->item($count, ['final_count_quantity' => 5,
            'ending_system_quantity' => 10, 'final_count_confirmed_system_quantity' => 8,
            'final_count_confirmed_difference_quantity' => -3, 'final_count_confirmed_difference_amount' => -37.02]);
        $uncounted = $this->item($count, ['ending_system_quantity' => 7]);
        $this->item($count, ['final_count_quantity' => 0, 'ending_system_quantity' => 6]);
        $this->item($count, ['final_count_quantity' => -2, 'ending_system_quantity' => -5]);
        $this->item($count, ['final_count_quantity' => 8, 'ending_system_quantity' => 8]);
        $this->item($count, ['ending_system_quantity' => 9], 1004);
        $this->item($count, ['ending_system_quantity' => 9], 1001, false);
        $this->item($count, ['ending_system_quantity' => 9], 1001, true, true);
        $originalItems = $count->items()->orderBy('id')->get()->toArray();
        $service = new InventoryCountFinalizationService;
        $preview = $service->preview($count);
        $pdfIds = (new InventoryDiffListPdfService)->diffItemsForRound($count, 3)->pluck('id')->sort()->values()->all();

        $this->assertSame($pdfIds, array_column($preview['rows'], 'wms_inventory_count_item_id'));
        $this->assertSame(4, $preview['detail_count']);
        $this->assertSame(1, $preview['uncounted_count']);
        $this->assertSame(3, $preview['increase_quantity']);
        $this->assertSame(-16, $preview['decrease_quantity']);
        $this->mock(InventoryCountLedgerBalanceService::class)
            ->shouldReceive('balancesBeforeAdjustmentByItem')->once()->with(1, 22, '2026-09-24')
            ->andReturn([$entered->item_id => 12, $uncounted->item_id => 9]);

        (new InventoryCountService)->confirm($count, 1, '2026-09-24', $preview['token']);
        $count->refresh();
        $this->assertSame(WmsInventoryCount::STATUS_CONFIRMED, $count->status);
        $this->assertFalse($count->handy_reception);
        $this->assertSame('2026-09-24', $count->inventory_adjustment_date->toDateString());
        $this->assertSame($originalItems, $count->items()->orderBy('id')->get()->toArray());
        $queue = $this->queues($count)->sole();
        $this->assertSame('2026-09-24', $queue->adjustment_date);
        $this->assertSame('2026-09-24', $queue->process_date);
        $this->assertSame('BEFORE', $queue->status);
        $this->assertSame([(int) $queue->id], $count->inventory_adjustment_queue_ids);
        $details = collect(json_decode($queue->items, true))->keyBy('wms_inventory_count_item_id');
        $this->assertSame(12, $details[$entered->id]['stock_quantity_before']);
        $this->assertSame(9, $details[$entered->id]['stock_quantity_after']);
        $this->assertSame(8, $details[$entered->id]['source_count_items'][0]['stock_quantity_before']);
        $this->assertSame(5, $details[$entered->id]['source_count_items'][0]['stock_quantity_after']);
        $this->assertSame(-3, $details[$entered->id]['inventory_adjustment_quantity']);
        $this->assertSame('棚卸 - 2026-08-20 - 理論 : 8 実棚: 5 差分 : -3', $details[$entered->id]['note']);
        $this->assertSame($details[$entered->id]['note'], $details[$entered->id]['source_count_items'][0]['note']);
        $this->assertSame(-37.02, $details[$entered->id]['amount']);
        $this->assertSame(2, $details[$uncounted->id]['stock_quantity_after']);
        $this->assertTrue($details[$uncounted->id]['uncounted_as_zero']);
        $this->assertSame('棚卸 - 2026-08-20 - 理論 : 7 実棚: 0 差分 : -7', $details[$uncounted->id]['note']);
        $this->assertContains('棚卸 - 2026-08-20 - 理論 : -5 実棚: -2 差分 : 3', $details->pluck('note')->all());
        $this->assertContains('棚卸 - 2026-08-20 - 理論 : 6 実棚: 0 差分 : -6', $details->pluck('note')->all());
        $this->assertNull($uncounted->refresh()->final_count_quantity);
        $this->assertFalse(DB::connection('sakemaru')->table('inventory_adjustments')->where('wms_inventory_count_id', $count->id)->exists());

        $service->confirm($count, 1, '2026-09-24', $preview['token']);
        $this->assertCount(1, $this->queues($count));
        $this->expectException(ValidationException::class);
        $service->confirm($count, 1, '2026-09-25', $preview['token']);
    }

    public function test_changed_preview_and_invalid_date_do_not_freeze_or_create_queues(): void
    {
        $count = $this->createCount();
        $item = $this->item($count);
        $service = new InventoryCountFinalizationService;
        $token = $service->preview($count)['token'];
        $item->update(['ending_system_quantity' => 99]);
        foreach (['2026-09-24', '2026-02-30', '2026-08-19'] as $date) {
            try {
                $service->confirm($count, 1, $date, $token);
                $this->fail('Invalid confirmation should be rejected.');
            } catch (ValidationException) {
                $this->assertSame(WmsInventoryCount::STATUS_CHECKED, $count->fresh()->status);
                $this->assertCount(0, $this->queues($count));
            }
        }
    }

    public function test_zero_difference_freezes_and_remembers_date_without_empty_vouchers(): void
    {
        $count = $this->createCount();
        $this->item($count, ['ending_system_quantity' => 0]);
        $this->finalize($count);
        $this->assertSame(WmsInventoryCount::STATUS_CONFIRMED, $count->fresh()->status);
        $this->assertSame('2026-09-24', $count->fresh()->inventory_adjustment_date->toDateString());
        $this->assertSame([], $count->fresh()->inventory_adjustment_queue_ids);
        $this->assertCount(0, $this->queues($count));
    }

    public function test_selected_round_must_be_confirmed_and_existing_queues_block_recreation(): void
    {
        $count = $this->createCount();
        $this->item($count);
        $service = new InventoryCountFinalizationService;
        $preview = $service->preview($count);
        $this->finalize($count);
        $count->refresh()->update(['status' => WmsInventoryCount::STATUS_CHECKED]);
        try {
            $service->confirm($count, 1, '2026-09-24', $preview['token']);
            $this->fail('Existing queue must block recreation.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('重複作成', $e->getMessage());
            $this->assertCount(1, $this->queues($count));
        }
        $count->update(['final_count_confirmed_at' => null]);
        $this->expectException(ValidationException::class);
        $service->preview($count, 3);
    }

    public function test_second_round_can_finalize_without_using_third_round_input(): void
    {
        $count = $this->createCount();
        $count->update(['status' => WmsInventoryCount::STATUS_COUNTING, 'final_count_confirmed_at' => null]);
        $entered = $this->item($count, [
            'second_count_quantity' => 5, 'second_count_confirmed_system_quantity' => 8,
            'second_count_confirmed_difference_quantity' => -3, 'final_count_quantity' => 999,
        ]);
        $uncounted = $this->item($count, ['ending_system_quantity' => 7, 'final_count_quantity' => 8]);
        $service = new InventoryCountFinalizationService;
        $this->assertSame([2 => '2回目'], $service->confirmedRounds($count));
        $preview = $service->preview($count, 2);
        $this->assertSame(2, $preview['count_round']);
        $this->assertSame(1, $preview['uncounted_count']);
        $this->assertSame(-10, $preview['decrease_quantity']);
        (new InventoryCountService)->confirm($count, 1, '2026-09-24', $preview['token'], 2);
        $this->assertSame(2, $count->fresh()->inventory_adjustment_count_round);
        $this->assertSame(999, $entered->fresh()->final_count_quantity);
        $this->assertSame(8, $uncounted->fresh()->final_count_quantity);
        $rows = json_decode($this->queues($count)->sole()->items, true);
        $this->assertSame([-3, -7], array_column($rows, 'stock_quantity_after'));
        $this->assertSame([5, 0], array_map(fn ($row) => $row['source_count_items'][0]['stock_quantity_after'], $rows));
        $this->assertSame([2, 2], array_column($rows, 'count_round'));
        $this->assertSame([
            '棚卸 - 2026-08-20 - 理論 : 8 実棚: 5 差分 : -3',
            '棚卸 - 2026-08-20 - 理論 : 7 実棚: 0 差分 : -7',
        ], array_column($rows, 'note'));
    }

    public function test_queue_inserts_roll_back_if_final_status_cannot_be_saved(): void
    {
        $count = $this->createCount();
        $this->item($count);
        WmsInventoryCount::updating(function ($record) use ($count): void {
            if ($record->id === $count->id && $record->status === WmsInventoryCount::STATUS_CONFIRMED) {
                throw new \RuntimeException('Simulated write failure');
            }
        });
        try {
            $this->finalize($count);
            $this->fail('Expected simulated write failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated write failure', $e->getMessage());
        }
        $this->assertSame(WmsInventoryCount::STATUS_CHECKED, $count->fresh()->status);
        $this->assertCount(0, $this->queues($count));
    }

    public function test_large_difference_is_chunked_and_current_inventory_is_not_written_by_wms(): void
    {
        $count = $this->createCount();
        $item = $this->item($count);
        $allocation = DB::connection('sakemaru')->table('stock_allocations')->where('client_id', 1)->where('code', 1)->value('id');
        $stockId = DB::connection('sakemaru')->table('real_stocks')->insertGetId([
            'client_id' => 1, 'warehouse_id' => 22, 'item_id' => $item->item_id, 'stock_allocation_id' => $allocation,
            'current_quantity' => 123, 'reserved_quantity' => 0, 'picking_quantity' => 0, 'order_rank' => '',
        ]);
        $item->update(['real_stock_id' => $stockId]);
        for ($i = 0; $i < 200; $i++) {
            $this->item($count);
        }
        $this->finalize($count);
        $queues = $this->queues($count);
        $this->assertCount(2, $queues);
        $this->assertSame([200, 1], $queues->map(fn ($q) => count(json_decode($q->items, true)))->all());
        $this->assertSame(123, (int) DB::connection('sakemaru')->table('real_stocks')->where('id', $stockId)->value('current_quantity'));
    }

    public function test_same_product_shelves_are_aggregated_without_losing_frozen_source_details(): void
    {
        $count = $this->createCount();
        $item = $this->item($count, ['ending_system_quantity' => 8, 'final_count_quantity' => 5]);
        $second = $item->replicate();
        $second->fill(['ending_system_quantity' => 12, 'final_count_quantity' => 10, 'location_no' => 'B01'])->save();
        $this->mock(InventoryCountLedgerBalanceService::class)
            ->shouldReceive('balancesBeforeAdjustmentByItem')->once()->andReturn([$item->item_id => 25]);
        $original = $count->items()->get()->toArray();

        $this->finalize($count);
        $rows = json_decode($this->queues($count)->sole()->items, true);
        $this->assertCount(1, $rows);
        $this->assertSame(25, $rows[0]['stock_quantity_before']);
        $this->assertSame(20, $rows[0]['stock_quantity_after']);
        $this->assertSame(-5, $rows[0]['inventory_adjustment_quantity']);
        $this->assertSame('棚卸 - 2026-08-20 - 理論 : 20 実棚: 15 差分 : -5', $rows[0]['note']);
        $this->assertSame([
            '棚卸 - 2026-08-20 - 理論 : 8 実棚: 5 差分 : -3',
            '棚卸 - 2026-08-20 - 理論 : 12 実棚: 10 差分 : -2',
        ], array_column($rows[0]['source_count_items'], 'note'));
        $this->assertNull($rows[0]['wms_inventory_count_item_id']);
        $this->assertSame([$item->id, $second->id], array_column($rows[0]['source_count_items'], 'wms_inventory_count_item_id'));
        $this->assertSame($original, $count->items()->get()->toArray());
    }

    public function test_offsetting_shelf_differences_do_not_create_a_voucher_or_query_the_ledger(): void
    {
        $count = $this->createCount();
        $item = $this->item($count, ['ending_system_quantity' => 8, 'final_count_quantity' => 5]);
        $second = $item->replicate();
        $second->fill(['ending_system_quantity' => 12, 'final_count_quantity' => 15])->save();
        $this->mock(InventoryCountLedgerBalanceService::class)->shouldNotReceive('balancesBeforeAdjustmentByItem');
        $this->finalize($count);
        $this->assertCount(0, $this->queues($count));
        $this->assertSame(WmsInventoryCount::STATUS_CONFIRMED, $count->fresh()->status);
    }

    public function test_multiple_allocations_even_with_zero_stock_are_rejected(): void
    {
        $count = $this->createCount();
        $item = $this->item($count);
        $allocation = DB::connection('sakemaru')->table('stock_allocations')->insertGetId(['client_id' => 1, 'code' => 987, 'name' => 'Other test']);
        DB::connection('sakemaru')->table('real_stocks')->insert([
            'client_id' => 1, 'warehouse_id' => 22, 'item_id' => $item->item_id, 'stock_allocation_id' => $allocation,
            'current_quantity' => 0, 'reserved_quantity' => 0, 'picking_quantity' => 0, 'order_rank' => '',
        ]);
        try {
            $this->finalize($count);
            $this->fail('Mixed allocation must be rejected.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('在庫区分', $e->getMessage());
            $this->assertCount(0, $this->queues($count));
            $this->assertSame(WmsInventoryCount::STATUS_CHECKED, $count->fresh()->status);
        }
    }

    public function test_pending_other_count_blocks_using_a_stale_ledger_balance(): void
    {
        $first = $this->createCount();
        $this->item($first);
        $this->finalize($first);
        $second = $this->createCount();
        $this->item($second);
        try {
            $this->finalize($second);
            $this->fail('Pending queue must block finalization.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('未完了', $e->getMessage());
            $this->assertCount(0, $this->queues($second));
            $this->assertSame(WmsInventoryCount::STATUS_CHECKED, $second->fresh()->status);
        }
    }

    public function test_september_first_voucher_uses_real_ledger_balance_and_does_not_rewrite_counted_stock(): void
    {
        $count = $this->createCount();
        $item = $this->item($count, [
            'ending_system_quantity' => 10, 'final_count_quantity' => 5,
            'final_count_confirmed_system_quantity' => 10, 'final_count_confirmed_difference_quantity' => -5,
        ]);
        $this->purchase($item, '2026-08-20', 10);
        $this->purchase($item, '2026-09-01', 2);
        $this->purchase($item, '2026-09-10', 3);
        $this->app->instance(InventoryCountLedgerBalanceService::class, new InventoryCountLedgerBalanceService);
        $service = new InventoryCountFinalizationService;
        $service->confirm($count, 1, '2026-09-01', $service->preview($count)['token']);

        $rows = json_decode($this->queues($count)->sole()->items, true);
        $this->assertSame(12, $rows[0]['stock_quantity_before']);
        $this->assertSame(7, $rows[0]['stock_quantity_after']);
        $this->assertSame(-5, $rows[0]['inventory_adjustment_quantity']);
        $this->assertSame('2026-09-01', $rows[0]['ledger_balance_date']);
        $this->assertSame(10, $item->fresh()->ending_system_quantity);
        $this->assertSame(5, $item->fresh()->final_count_quantity);
        $this->assertSame(-5, $item->fresh()->final_count_confirmed_difference_quantity);
    }

    public function test_fractional_ledger_balance_is_not_silently_truncated(): void
    {
        $count = $this->createCount();
        $item = $this->item($count);
        $this->mock(InventoryCountLedgerBalanceService::class)
            ->shouldReceive('balancesBeforeAdjustmentByItem')->once()->andReturn([$item->item_id => 1.5]);
        try {
            $this->finalize($count);
            $this->fail('Fractional balance must be rejected.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('整数以外', $e->getMessage());
            $this->assertCount(0, $this->queues($count));
            $this->assertSame(WmsInventoryCount::STATUS_CHECKED, $count->fresh()->status);
        }
    }

    public function test_adjustment_baseline_excludes_only_same_day_events_ordered_after_the_voucher(): void
    {
        $item = $this->item($this->createCount());
        $this->purchase($item, '2026-08-20', 10);
        $db = DB::connection('sakemaru');
        foreach ([['2026-08-31', 2], ['2026-09-01', 3]] as [$date, $quantity]) {
            $trade = $this->purchase($item, $date, $quantity);
            $db->table('trades')->where('id', $trade)->update(['trade_category' => 'CONTAINER_PICKUP']);
            $db->table('container_pickups')->insert([
                'client_id' => 1, 'trade_id' => $trade, 'warehouse_id' => 22, 'buyer_id' => 1,
                'delivered_date' => $date, 'is_active' => true,
            ]);
        }
        $ledger = new InventoryCountLedgerBalanceService;
        $this->assertSame(15.0, $ledger->balancesByItem(1, 22, '2026-09-01')[$item->item_id]);
        $this->assertSame(12.0, $ledger->balancesBeforeAdjustmentByItem(1, 22, '2026-09-01')[$item->item_id]);
        $this->assertSame(15.0, $ledger->balancesBeforeAdjustmentByItem(1, 22, '2026-09-02')[$item->item_id]);
    }

    public function test_later_absolute_adjustment_blocks_backdating_and_same_day_adjustment_is_included(): void
    {
        $count = $this->createCount();
        $item = $this->item($count, ['ending_system_quantity' => 10, 'final_count_quantity' => 5]);
        $this->purchase($item, '2026-08-20', 10);
        $trade = $this->purchase($item, '2026-09-02', -2);
        $db = DB::connection('sakemaru');
        $db->table('trades')->where('id', $trade)->update(['trade_category' => 'INVENTORY_ADJUSTMENT']);
        $adjustment = $db->table('inventory_adjustments')->insertGetId([
            'client_id' => 1, 'trade_id' => $trade, 'warehouse_id' => 22,
            'adjustment_date' => '2026-09-02', 'is_active' => true,
        ]);
        $db->table('inventory_adjustment_items')->insert([
            'inventory_adjustment_id' => $adjustment, 'trade_item_id' => $db->table('trade_items')->where('trade_id', $trade)->value('id'),
            'stock_quantity_before' => 10, 'stock_quantity_after' => 8, 'inventory_adjustment_quantity' => -2,
        ]);
        $this->app->instance(InventoryCountLedgerBalanceService::class, new InventoryCountLedgerBalanceService);
        $service = new InventoryCountFinalizationService;
        $preview = $service->preview($count);
        try {
            $service->confirm($count, 1, '2026-09-01', $preview['token']);
            $this->fail('Later absolute adjustment must block finalization.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('指定日より後', $e->getMessage());
            $this->assertCount(0, $this->queues($count));
            $this->assertSame(WmsInventoryCount::STATUS_CHECKED, $count->fresh()->status);
        }
        $service->confirm($count, 1, '2026-09-02', $preview['token']);
        $rows = json_decode($this->queues($count)->sole()->items, true);
        $this->assertSame(8, $rows[0]['stock_quantity_before']);
        $this->assertSame(3, $rows[0]['stock_quantity_after']);
    }

    private function purchase(WmsInventoryCountItem $item, string $date, int $quantity): int
    {
        $db = DB::connection('sakemaru');
        $trade = $db->table('trades')->insertGetId([
            'client_id' => 1, 'creator_id' => 1, 'last_updater_id' => 1, 'trade_category' => 'PURCHASE',
            'uuid' => (string) Str::uuid(), 'serial_id' => random_int(900000000, 999999999), 'entry_lot_number' => 0,
            'process_date' => $date, 'is_active' => true, 'is_latest' => true,
        ]);
        $db->table('purchases')->insert([
            'trade_id' => $trade, 'client_id' => 1, 'supplier_id' => 1, 'warehouse_id' => 22,
            'delivered_date' => $date, 'is_active' => true,
        ]);
        $db->table('trade_items')->insert([
            'client_id' => 1, 'trade_id' => $trade, 'item_id' => $item->item_id, 'stock_allocation_id' => 0,
            'order_quantity_type' => 'PIECE', 'quantity_type' => 'PIECE', 'price_category' => 'OTHER',
            'quantity' => $quantity, 'is_active' => true,
        ]);

        return $trade;
    }

    public function test_stale_web_actions_and_handy_requests_cannot_modify_finalized_data(): void
    {
        $count = $this->createCount();
        $item = $this->item($count, ['final_count_quantity' => 1]);
        $original = $item->fresh()->getAttributes();
        $page = new ViewWmsInventoryCount;
        $page->record = $count;
        $page->activeCountRound = 3;
        $this->finalize($count);
        $page->saveInlineChanges([$item->id => ['final' => 999]]);
        $page->reopenFinalRound();
        $page->confirmRound(3);
        $page->calculateActiveRoundDifferences();
        $page->fillActiveRoundUncountedWithZero();
        $this->assertSame($original, $item->fresh()->getAttributes());
        $this->assertSame(WmsInventoryCount::STATUS_CONFIRMED, $count->fresh()->status);

        $service = new InventoryCountService;
        foreach ([fn () => $service->cancel($count), fn () => $service->startCounting($count),
            fn () => $service->calculateDifferences($count), fn () => $service->storeConfirmedRoundDifferences($count, 3),
            fn () => $count->enableHandyReception(),
            fn () => $service->registerCount($item, 99, 3, 'DENSO', null, (string) Str::uuid(), true),
        ] as $action) {
            try {
                $action();
                $this->fail('Finalized data must be immutable.');
            } catch (ValidationException) {
                $this->assertSame($original, $item->fresh()->getAttributes());
            }
        }
        $controller = new InventoryCountController($service);
        $response = $controller->count(Request::create('/', 'POST', ['quantity' => 7, 'count_round' => 3, 'request_uuid' => (string) Str::uuid()]), $item->id);
        $this->assertSame(422, $response->getStatusCode());
        $response = $controller->bulkCount(Request::create('/', 'POST', ['count_round' => 3, 'items' => [['item_id' => $item->id, 'quantity' => 7, 'request_uuid' => (string) Str::uuid()]]]), $count->id);
        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame($original, $item->fresh()->getAttributes());
    }

    private function createCount(): WmsInventoryCount
    {
        return WmsInventoryCount::create([
            'count_no' => 'FINAL-'.Str::random(12), 'client_id' => 1, 'warehouse_id' => 22,
            'warehouse_code' => '22', 'warehouse_name' => 'Test', 'count_date' => '2026-08-20',
            'status' => WmsInventoryCount::STATUS_CHECKED, 'current_count_round' => 3,
            'handy_reception' => true, 'first_count_confirmed_at' => now()->subDays(2),
            'second_count_confirmed_at' => now()->subDay(), 'final_count_confirmed_at' => now(),
        ]);
    }

    private function item(WmsInventoryCount $count, array $attributes = [], int $categoryCode = 1001, bool $managed = true, bool $owned = false): WmsInventoryCountItem
    {
        $db = DB::connection('sakemaru');
        $categoryId = $db->table('item_categories')->insertGetId([
            'client_id' => 1, 'name' => 'Final category', 'code' => $categoryCode, 'depth' => 1, 'creator_id' => 1, 'last_updater_id' => 1,
        ]);
        $setId = $owned ? $db->table('item_sets')->insertGetId([
            'client_id' => 1, 'description' => 'Owned set', 'set_type' => 'OWNED', 'is_active' => true, 'creator_id' => 1, 'last_updater_id' => 1,
        ]) : null;
        $code = (string) random_int(800000000, 899999999);
        $itemId = $db->table('items')->insertGetId([
            'client_id' => 1, 'name_main' => 'Final item', 'code' => $code, 'type' => 'NOT_ALCOHOL', 'manufacturer_id' => 0,
            'volume' => 1, 'capacity_case' => 1, 'creator_id' => 1, 'packaging' => '1', 'nickname' => 'Final',
            'item_category1_id' => $categoryId, 'item_category2_id' => 0, 'item_set_id' => $setId, 'is_managed_stock' => $managed,
            'container_type_id' => 0, 'manufacture_type_id' => 0, 'storage_type_id' => 0, 'measurement_unit_weight' => 0,
            'measurement_case_weight' => 0, 'order_rank' => 'ORDER_MANUAL', 'last_updater_id' => 1,
        ]);

        return WmsInventoryCountItem::create(array_merge([
            'inventory_count_id' => $count->id, 'item_id' => $itemId, 'item_code' => $code, 'item_name' => 'Final item',
            'system_quantity' => 99, 'ending_system_quantity' => 8, 'cost_price' => 12.34,
            'first_count_confirmed_difference_quantity' => -91, 'first_count_confirmed_system_quantity' => 99,
        ], $attributes));
    }

    private function finalize(WmsInventoryCount $count): void
    {
        $service = new InventoryCountFinalizationService;
        $service->confirm($count, 1, '2026-09-24', $service->preview($count)['token']);
    }

    private function queues(WmsInventoryCount $count): \Illuminate\Support\Collection
    {
        return DB::connection('sakemaru')->table('inventory_adjustment_queue')->where('wms_inventory_count_id', $count->id)->orderBy('id')->get();
    }
}
