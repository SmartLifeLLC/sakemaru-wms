<?php

namespace Tests\Unit\Services;

use App\Models\WmsSundryInventoryCount;
use App\Services\InventoryCount\InventoryCountLedgerBalanceService;
use App\Services\SundryInventoryCount\SundryFinalizationCalculator;
use App\Services\SundryInventoryCount\SundryFinalizationService;
use App\Services\SundryInventoryCount\SundryFinalizationWorkbook;
use App\Services\SundryInventoryCount\SundryInventoryCountService;
use App\Services\SundryInventoryCount\SundryInventoryTheoryCalculator;
use App\Support\SundryCalculationProof;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class SundryFinalizationServiceTest extends SundryQuantityValuationServiceTest
{
    private function fixture(): array
    {
        config(['sundry_book_reconciliation.unmanaged_gaps' => [], 'sundry_book_reconciliation.managed_gaps' => []]);
        $db = DB::connection('sakemaru');
        $schema = Schema::connection('sakemaru');
        $this->assertSame(':memory:', $db->getDatabaseName());
        (require database_path('migrations/2026_10_18_010000_create_sundry_finalization_records.php'))->up();
        (require database_path('migrations/2026_10_18_020000_create_sundry_book_reconciliations.php'))->up();
        $schema->table('item_categories', function (Blueprint $t): void { $t->string('code')->nullable(); $t->string('name')->nullable(); });
        $db->table('item_categories')->where('id', 1)->update(['code' => '1004']);
        $schema->table('real_stocks', function (Blueprint $t): void {
            foreach (['client_id', 'warehouse_id', 'item_id', 'stock_allocation_id'] as $c) { $t->integer($c)->nullable(); }
        });
        foreach ([
            'item_cost_snapshots' => ['client_id', 'item_id', 'target_year_month', 'cost_unit_price'],
            'stock_allocations' => ['client_id', 'code'],
            'inventory_adjustments' => ['client_id', 'warehouse_id', 'trade_id', 'is_active', 'adjustment_date'],
            'inventory_adjustment_items' => ['inventory_adjustment_id', 'trade_item_id'],
            'trades' => ['is_active', 'is_latest'], 'trade_items' => ['item_id'],
            'review_balances' => ['item_id', 'quantity', 'posting_quantity'],
            'review_flows' => ['date', 'purchase', 'sales_cost'], 'review_reference' => ['amount'],
            'inventory_adjustment_queue' => ['client_id', 'warehouse_code', 'process_date', 'adjustment_date', 'source_type', 'source_id',
                'wms_inventory_count_id', 'request_id', 'note', 'items', 'status', 'retry_count', 'is_success', 'error_message', 'created_at', 'updated_at'],
        ] as $table => $columns) {
            $schema->create($table, function (Blueprint $t) use ($columns): void {
                $t->id();
                foreach ($columns as $c) { $t->string($c)->nullable(); }
            });
        }
        $db->table('stock_allocations')->insert(['id' => 1, 'client_id' => 6, 'code' => 1]);
        $db->table('item_cost_snapshots')->insert(['client_id' => 6, 'item_id' => 4, 'target_year_month' => '2026-09', 'cost_unit_price' => 100]);
        $db->table('review_balances')->insert(['item_id' => 4, 'quantity' => 10, 'posting_quantity' => 12]);
        $db->table('review_reference')->insert(['amount' => 1000]);
        $db->table('review_flows')->insert(['date' => '2026-10-02', 'purchase' => 200, 'sales_cost' => 80]);
        $count = WmsSundryInventoryCount::firstOrFail();
        $count->update(['status' => 'confirmed', 'warehouse_code' => '10']);
        $count->items()->update(['counted_quantity' => 7, 'item_code' => '404', 'item_name' => 'TEST', 'category2_id' => 2]);
        $count->amounts()->update(['opening_date' => '2026-08-31', 'counted_amount' => 400]);
        $theory = new class extends SundryInventoryTheoryCalculator
        {
            public function managedBalances(int $clientId, int $warehouseId, string $endDate): array
            {
                return DB::connection('sakemaru')->table('review_balances')->pluck('quantity', 'item_id')->all();
            }
            public function unmanagedFlows(int $clientId, int $warehouseId, array $categoryIds, string $fromDate, string $endDate, int $depth = 2): array
            {
                $rows = DB::connection('sakemaru')->table('review_flows')->whereBetween('date', [$fromDate, $endDate])->get();
                return collect($categoryIds)->mapWithKeys(fn ($id) => [$id => ['purchase' => $id === 2 ? (float) $rows->sum('purchase') : 0,
                    'sales_cost' => $id === 2 ? (float) $rows->sum('sales_cost') : 0, 'transfer_in' => 0, 'transfer_out' => 0, 'adjustment' => 0]])->all();
            }
            public function unmanagedOpeningFromStockReport(int $clientId, int $warehouseId, int $category1Id, string $onOrBeforeDate): ?array
            {
                return ['date' => '2026-08-31', 'amount' => (float) DB::connection('sakemaru')->table('review_reference')->value('amount')];
            }
            public function unmanagedCategories(int $clientId, array $category1Ids, array $category2Ids = []): \Illuminate\Support\Collection
            {
                return collect(config('sundry_test.categories', [['category2_id' => 2, 'category2_code' => '2041']]))->map(fn ($r) => (object) $r);
            }
        };
        $ledger = new class extends InventoryCountLedgerBalanceService
        {
            public function balancesBeforeAdjustmentByItem(int $clientId, int $warehouseId, string $date): array
            {
                return DB::connection('sakemaru')->table('review_balances')->pluck('posting_quantity', 'item_id')->all();
            }
        };
        return [$count, new SundryFinalizationService(new SundryFinalizationCalculator($theory, $ledger))];
    }

    public function test_finalization_backdates_and_records_without_changing_inputs_or_stock(): void
    {
        [$count, $service] = $this->fixture();
        $before = $count->items()->get()->toJson();
        $preview = $service->preview($count, '2026-09-30');
        [$managed, $amount] = $preview['snapshot']['lines'];
        $this->assertSame(-3.0, $managed['difference_quantity']);
        $this->assertSame(9.0, $managed['quantity_after']);
        $this->assertSame(-300.0, $managed['amount']);
        $this->assertSame(1120.0, $amount['count_theory']);
        $this->assertSame(280.0, $amount['book_after']);
        $this->assertSame(-720.0, $amount['amount']);
        $id = $service->finalize($count, '2026-09-30', $preview['token'], null);
        $this->assertSame($id, $service->finalize($count, '2026-09-30', $preview['token'], null));
        $this->assertSame($before, $count->items()->get()->toJson());
        $db = DB::connection('sakemaru');
        $this->assertSame(1, $db->table('inventory_adjustment_queue')->count());
        $this->assertSame('record_v3', json_decode($db->table('inventory_adjustment_queue')->value('items'), true)['operation']);
        $this->assertNull($db->table('inventory_adjustment_queue')->value('wms_inventory_count_id'));
        $this->assertSame(0, $db->table('inventory_adjustments')->count());
        $this->assertSame(0, $db->table('wms_sundry_amount_adjustments')->count());
        $this->assertEquals(91, $db->table('real_stocks')->value('quantity'));
        $this->expectException(ValidationException::class);
        (new SundryInventoryCountService)->reopen($count);
    }

    public function test_changed_cost_invalidates_preview(): void
    {
        [$count, $service] = $this->fixture();
        $preview = $service->preview($count, '2026-09-30');
        DB::connection('sakemaru')->table('item_cost_snapshots')->update(['cost_unit_price' => 101]);
        $this->expectException(ValidationException::class);
        $service->finalize($count, '2026-09-30', $preview['token'], null);
    }

    public function test_changed_movement_after_finalization_blocks_application(): void
    {
        [$count, $service] = $this->fixture();
        $preview = $service->preview($count, '2026-09-30');
        $id = $service->finalize($count, '2026-09-30', $preview['token'], null);
        $db = DB::connection('sakemaru');
        $db->table(SundryFinalizationService::TABLE)->where('id', $id)->update(['status' => 'recorded', 'inventory_adjustment_id' => 1]);
        $db->table('review_flows')->update(['purchase' => 300]);
        try {
            $service->requestApplication($count, null);
            $this->fail('Stale source was accepted');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('確定後', $e->getMessage());
        }
        $this->assertSame(1, $db->table('inventory_adjustment_queue')->count());
    }

    public function test_report_opening_mismatch_requires_explicit_unknown_approval(): void
    {
        [$count, $service] = $this->fixture();
        DB::connection('sakemaru')->table('review_reference')->update(['amount' => 1500]);
        $preview = $service->preview($count, '2026-09-30');
        $unknown = collect($preview['snapshot']['lines'])->firstWhere('kind', 'book');
        $this->assertSame(-500.0, $unknown['amount']);
        $this->assertNull($unknown['item_id']);
        $this->assertNull($unknown['category2_id']);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('不明差異金額を確認');
        $service->finalize($count, '2026-09-30', $preview['token'], null);
    }

    public function test_saved_zero_cost_is_not_replaced_by_retail_rate(): void
    {
        [$count, $service] = $this->fixture();
        DB::connection('sakemaru')->table('item_cost_snapshots')->update(['cost_unit_price' => 0]);
        $line = $service->preview($count, '2026-09-30')['snapshot']['lines'][0];
        $this->assertSame(-3.0, $line['difference_quantity']);
        $this->assertSame(0.0, $line['unit_price']);
        $this->assertSame(-0.0, $line['amount']);
    }

    public function test_missing_posting_month_cost_is_not_invented(): void
    {
        [$count, $service] = $this->fixture();
        DB::connection('sakemaru')->table('item_cost_snapshots')->where('item_id', 4)->delete();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('適用月原価がありません');
        $service->preview($count, '2026-09-30');
    }

    public function test_unentered_managed_quantity_is_not_silently_zeroed(): void
    {
        [$count, $service] = $this->fixture();
        $count->items()->update(['counted_quantity' => null]);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('未入力');
        $service->preview($count, '2026-09-30');
    }

    public function test_fractional_counts_are_preserved_but_not_sent_to_integer_stock(): void
    {
        [$count, $service] = $this->fixture();
        $count->items()->update(['counted_quantity' => 9.875]);
        $preview = $service->preview($count, '2026-09-30');
        $this->assertSame(-0.125, $preview['snapshot']['lines'][0]['difference_quantity']);
        $id = $service->finalize($count, '2026-09-30', $preview['token'], null);
        DB::connection('sakemaru')->table(SundryFinalizationService::TABLE)->where('id', $id)->update(['status' => 'recorded', 'inventory_adjustment_id' => 1]);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('整数型');
        $service->requestApplication($count, null);
    }

    public function test_final_workbook_uses_saved_values_and_shows_posting_date(): void
    {
        [$count, $service] = $this->fixture();
        $preview = $service->preview($count, '2026-09-30');
        $service->finalize($count, '2026-09-30', $preview['token'], null);
        DB::connection('sakemaru')->table('review_balances')->update(['quantity' => 999]);
        $book = (new SundryFinalizationWorkbook)->build(SundryFinalizationService::record($count->id));
        $this->assertSame('2026-09-30', $book->getSheet(0)->getCell('B3')->getValue());
        $this->assertEquals(-1020, $book->getSheet(0)->getCell('B8')->getCalculatedValue());
        $this->assertEquals(10, $book->getSheet(1)->getCell('E2')->getValue());
        $this->assertEquals(9, $book->getSheet(1)->getCell('H2')->getValue());
        $this->assertEquals(280, $book->getSheet(2)->getCell('F2')->getValue());
        $this->assertSame('A1:K3', $book->getSheet(1)->getPageSetup()->getPrintArea());
        $book->disconnectWorksheets();
    }

    public function test_unapplied_cancellation_preserves_snapshot_and_allows_refinalization(): void
    {
        [$count, $service] = $this->fixture();
        $preview = $service->preview($count, '2026-09-30');
        $id = $service->finalize($count, '2026-09-30', $preview['token'], null);
        $snapshot = SundryFinalizationService::record($count->id)->snapshot;
        $service->cancelUnapplied($count, null);
        $this->assertNull(SundryFinalizationService::record($count->id));
        $db = DB::connection('sakemaru');
        $this->assertSame('cancelled', $db->table(SundryFinalizationService::TABLE)->where('id', $id)->value('status'));
        $this->assertSame($snapshot, $db->table(SundryFinalizationService::TABLE)->where('id', $id)->value('snapshot'));
        $this->assertSame('FINISHED', $db->table('inventory_adjustment_queue')->value('status'));
        $next = $service->finalize($count, '2026-09-30', $preview['token'], null);
        $this->assertNotSame($id, $next);
        $db->table(SundryFinalizationService::TABLE)->where('id', $next)->update(['status' => 'applied']);
        $this->expectException(ValidationException::class);
        $service->cancelUnapplied($count, null);
    }

    public function test_later_adjustment_is_an_application_guard_not_a_permanent_report_guard(): void
    {
        [$count, $service] = $this->fixture();
        $snapshot = $service->preview($count, '2026-09-30')['snapshot'];
        $db = DB::connection('sakemaru');
        $proof = new SundryCalculationProof($db);
        $historical = $proof->refresh($proof->historical($snapshot['proof']));
        $this->assertLessThan(count($snapshot['proof']), count($historical));
        $db->table('trades')->insert(['id' => 91, 'is_active' => 1, 'is_latest' => 1]);
        $db->table('trade_items')->insert(['id' => 91, 'item_id' => 4]);
        $db->table('inventory_adjustments')->insert(['id' => 91, 'client_id' => 6, 'warehouse_id' => $count->warehouse_id,
            'trade_id' => 91, 'is_active' => 1, 'adjustment_date' => '2026-10-15']);
        $db->table('inventory_adjustment_items')->insert(['inventory_adjustment_id' => 91, 'trade_item_id' => 91]);
        $proof->verify($historical);
        $this->expectException(\RuntimeException::class);
        $proof->verify($snapshot['proof']);
    }

    public function test_backdated_source_change_still_invalidates_historical_proof(): void
    {
        [$count, $service] = $this->fixture();
        $db = DB::connection('sakemaru');
        $proof = new SundryCalculationProof($db);
        $historical = $proof->historical($service->preview($count, '2026-09-30')['snapshot']['proof']);
        $db->table('review_flows')->update(['purchase' => 201]);
        $this->expectException(\RuntimeException::class);
        $proof->verify($historical);
    }

    public function test_partial_department_reconciliation_does_not_add_unselected_count_lines(): void
    {
        [$count, $service] = $this->fixture();
        config(['sundry_test.categories' => [['category2_id' => 2, 'category2_code' => '2041'], ['category2_id' => 47, 'category2_code' => '2047']]]);
        // Warehouse 10's actual legacy 2047 opening, used only to reconcile the department.
        $legacy = (new SundryInventoryTheoryCalculator)->unmanagedLegacyOpening('10', '2047', '2026-08-31');
        DB::connection('sakemaru')->table('review_reference')->update(['amount' => 1000 + $legacy['amount']]);
        $before = $count->amounts()->get()->toJson();
        $lines = $service->preview($count, '2026-09-30')['snapshot']['lines'];
        $this->assertCount(2, $lines);
        $this->assertSame([2, 2], array_column($lines, 'category2_id'));
        $this->assertSame($before, $count->amounts()->get()->toJson());
    }

    public function test_legacy_gift_opening_is_zero_without_rewriting_entered_or_manual_values(): void
    {
        [$count, $service] = $this->fixture();
        $count->amounts()->update(['category2_code' => '2052', 'opening_source' => 'legacy', 'opening_date' => '2026-05-05', 'opening_amount' => -3300]);
        DB::connection('sakemaru')->table('review_reference')->update(['amount' => 0]);
        $before = $count->amounts()->get()->toJson();
        $line = $service->preview($count, '2026-09-30')['snapshot']['lines'][1];
        $this->assertSame(0.0, $line['opening_amount']);
        $this->assertSame(120.0, $line['count_theory']);
        $this->assertSame(280.0, $line['amount']);
        $this->assertSame($before, $count->amounts()->get()->toJson());
        $count->amounts()->update(['opening_source' => 'manual']);
        DB::connection('sakemaru')->table('review_reference')->update(['amount' => -3300]);
        $this->assertSame(-3300.0, $service->preview($count, '2026-09-30')['snapshot']['lines'][1]['opening_amount']);
    }

    public function test_approved_gap_is_included_once_in_current_difference_and_not_in_quantity(): void
    {
        [$count, $service] = $this->fixture();
        config(['sundry_book_reconciliation.unmanaged_gaps' => [10 => [2041 => 2923]]]);
        $count->amounts()->update(['opening_source' => 'legacy', 'opening_date' => '2026-05-05']);
        DB::connection('sakemaru')->table('review_reference')->update(['amount' => 3923]);
        $snapshot = $service->preview($count, '2026-09-30')['snapshot'];
        $line = $snapshot['lines'][1];
        $this->assertSame(-3643.0, $line['amount']);
        $this->assertSame(-720.0, $line['inventory_difference_amount']);
        $this->assertSame(4043.0, $line['count_theory']);
        $this->assertSame(280.0, $line['book_after']);
        $this->assertSame(-300.0, $snapshot['lines'][0]['amount']);
        DB::connection('sakemaru')->table('wms_sundry_book_reconciliations')->insert(['client_id' => 6, 'warehouse_id' => 10,
            'finalization_id' => 100, 'approval_key' => $line['book_reconciliation']['approval_key'], 'category1_code' => '1004',
            'process_date' => '2026-09-30', 'adjustment_amount' => -2923, 'source_snapshot' => '{}']);
        $next = $service->preview($count, '2026-09-30')['snapshot']['lines'][1];
        $this->assertSame(-720.0, $next['amount']);
        $this->assertArrayNotHasKey('book_reconciliation', $next);
    }

    public function test_container_category_cannot_be_finalized_through_sundry(): void
    {
        [$count, $service] = $this->fixture();
        DB::connection('sakemaru')->table('item_categories')->where('id', 1)->update(['code' => '1007']);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('適用対象外');
        $service->preview($count, '2026-09-30');
    }

    public function test_approved_policy_totals_and_cheese_opening_are_preserved(): void
    {
        $policy = require config_path('sundry_book_reconciliation.php');
        $this->assertSame(68580, array_sum(array_map('array_sum', $policy['unmanaged_gaps'])));
        $this->assertSame(2923, array_sum($policy['unmanaged_gaps'][1]));
        $this->assertSame(28000, $policy['managed_gaps'][1][523001]['amount']);
        $theory = new SundryInventoryTheoryCalculator;
        $this->assertSame(-1149283.0, $theory->unmanagedLegacyOpening('2', '2026', '2026-10-06')['amount']);
        $this->assertSame(0.0, $theory->unmanagedLegacyOpening('3', '2052', '2026-10-06')['amount']);
    }

    public function test_confirmed_but_unapplied_manual_amount_is_not_a_book_opening(): void
    {
        [$previous] = $this->fixture();
        $count = $previous->replicate();
        $count->count_no = 'NEXT';
        $count->count_date = '2026-10-10';
        $count->theory_end_date = '2026-10-10';
        $count->status = 'draft';
        $count->save();
        $row = $previous->amounts()->first()->replicate();
        $row->sundry_inventory_count_id = $count->id;
        $service = new SundryInventoryCountService;
        $method = new \ReflectionMethod($service, 'applyOpening');
        $method->invoke($service, $count, $row);
        $this->assertSame('legacy', $row->opening_source);
        $this->assertSame(465828.0, $row->opening_amount);
        $previousAmount = $previous->amounts()->first();
        DB::connection('sakemaru')->table('wms_sundry_amount_adjustments')->insert([
            'client_id' => 6, 'warehouse_id' => 10, 'sundry_inventory_count_id' => $previous->id, 'amount_id' => $previousAmount->id,
            'category1_id' => 1, 'category2_id' => 2, 'category1_code' => '1004', 'category2_code' => '2041',
            'count_date' => '2026-10-06', 'process_date' => '2026-09-30', 'book_amount' => 1000, 'counted_amount' => 280,
            'adjustment_amount' => -720, 'book_reference' => 'TEST', 'source_snapshot' => '{}', 'is_active' => true,
        ]);
        $method->invoke($service, $count, $row);
        $this->assertSame('previous_count', $row->opening_source);
        $this->assertSame(400.0, $row->opening_amount);
        $this->assertSame('2026-10-06', $row->opening_date->toDateString());
    }

    public function test_approved_managed_gap_does_not_change_quantity_or_saved_input(): void
    {
        [$count, $service] = $this->fixture();
        config(['sundry_book_reconciliation.managed_gaps' => [10 => [404 => ['category1_code' => 1004, 'amount' => 28000]]]]);
        DB::connection('sakemaru')->table('review_reference')->update(['amount' => 29000]);
        $before = $count->items()->get()->toJson();
        $line = $service->preview($count, '2026-09-30')['snapshot']['lines'][0];
        $this->assertSame(-3.0, $line['difference_quantity']);
        $this->assertSame(9.0, $line['quantity_after']);
        $this->assertSame(-300.0, $line['inventory_difference_amount']);
        $this->assertSame(-28300.0, $line['amount']);
        $this->assertSame($before, $count->items()->get()->toJson());
    }

    public function test_approved_gap_cannot_be_omitted_by_selecting_a_different_posting_date(): void
    {
        [$count, $service] = $this->fixture();
        config(['sundry_book_reconciliation.unmanaged_gaps' => [10 => [2041 => 2923]]]);
        $count->amounts()->update(['opening_source' => 'legacy', 'opening_date' => '2026-05-05']);
        DB::connection('sakemaru')->table('review_reference')->update(['amount' => 3923]);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('2026/09/30');
        $service->preview($count, '2026-09-29');
    }

    public function test_unexplained_gap_is_not_folded_into_an_approved_correction(): void
    {
        [$count, $service] = $this->fixture();
        config(['sundry_book_reconciliation.unmanaged_gaps' => [10 => [2041 => 2923]]]);
        $count->amounts()->update(['opening_source' => 'legacy', 'opening_date' => '2026-05-05']);
        DB::connection('sakemaru')->table('review_reference')->update(['amount' => 3924]);
        $lines = $service->preview($count, '2026-09-30')['snapshot']['lines'];
        $this->assertSame(-2923.0, $lines[1]['book_reconciliation']['amount']);
        $this->assertSame('book', $lines[2]['kind']);
        $this->assertSame(-1.0, $lines[2]['amount']);
    }

    public function test_unknown_is_saved_separately_exported_and_not_repeated(): void
    {
        [$count, $service] = $this->fixture();
        $db = DB::connection('sakemaru');
        $db->table('review_reference')->update(['amount' => 900.02]);
        $before = [$count->items()->get()->toJson(), $count->amounts()->get()->toJson()];
        $preview = $service->preview($count, '2026-09-30');
        $unknown = collect($preview['snapshot']['lines'])->firstWhere('kind', 'book');
        $this->assertSame(99.98, $unknown['amount']);
        $id = $service->finalize($count, '2026-09-30', $preview['token'], null, true);
        $this->assertSame($id, $service->finalize($count, '2026-09-30', $preview['token'], null, true));
        $record = SundryFinalizationService::record($count->id);
        $saved = json_decode($record->snapshot, true);
        $this->assertTrue($saved['unknown_approval']['accepted']);
        $book = (new SundryFinalizationWorkbook)->build($record);
        $this->assertEquals(-920.02, $book->getSheet(0)->getCell('B8')->getCalculatedValue());
        $this->assertEquals(99.98, $book->getSheetByName('部門不明差異')->getCell('H2')->getValue());
        $book->disconnectWorksheets();
        $this->assertSame($before, [$count->items()->get()->toJson(), $count->amounts()->get()->toJson()]);
        $db->table('wms_sundry_book_reconciliations')->insert(['client_id' => 6, 'warehouse_id' => 10, 'finalization_id' => $id,
            'approval_key' => $unknown['book_reconciliation']['approval_key'], 'category1_code' => '1004',
            'process_date' => '2026-09-30', 'adjustment_amount' => 99.98, 'source_snapshot' => '{}']);
        $next = $service->preview($count, '2026-09-30');
        $this->assertFalse(collect($next['snapshot']['lines'])->contains('kind', 'book'));
    }

    public function test_preview_renders_unknown_without_fake_quantities_and_escapes_names(): void
    {
        [$count, $service] = $this->fixture();
        DB::connection('sakemaru')->table('review_reference')->update(['amount' => 1500]);
        DB::connection('sakemaru')->table('item_categories')->where('id', 1)->update(['name' => '<script>test</script>']);
        $snapshot = $service->preview($count, '2026-09-30')['snapshot'];
        $html = view('filament.resources.wms-sundry-inventory-count.pages.partials.finalization-preview', ['preview' => [
            'date' => '2026-09-30', 'count_date' => $snapshot['count_date'], 'total' => $snapshot['total_amount'], 'lines' => $snapshot['lines'],
        ]])->render();
        $this->assertStringContainsString('部門全体', $html);
        $this->assertStringContainsString('-500.00', $html);
        $this->assertStringContainsString('&lt;script&gt;test&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>test</script>', $html);
    }

    public static function roundingCases(): array
    {
        return [
            'positive' => [[[1, '0.333333', 1], [1, '0.333333', 1], [1, '0.333333', 1]], [0.33, 0.34, 0.33], 1.00],
            'negative' => [[[-1, '0.333333', 1], [-1, '0.333333', 1], [-1, '0.333333', 1]], [-0.33, -0.34, -0.33], -1.00],
            'mixed and zero' => [[[1, '0.333333', 1], [0, '0.333333', 1], [-1, '0.333333', 1]], [0.33, 0.00, -0.33], 0.00],
            'positive half cent' => [[[1, '0.005', 1], [1, '0.005', 1]], [0.00, 0.01], 0.01],
            'negative half cent' => [[[-1, '0.005', 1], [-1, '0.005', 1]], [-0.01, 0.00], -0.01],
            'separate departments' => [[[1, '0.005', 1], [1, '0.005', 10]], [0.00, 0.01], 0.01],
            'interleaved departments' => [[[1, '0.333333', 1], [1, '0.005', 10], [1, '0.333333', 1]], [0.33, 0.01, 0.34], 0.68],
            'two decimal prices unchanged' => [[[-3, '100.25', 1], [2, '0.85', 1]], [-300.75, 1.70], -299.05],
            'fractional quantity' => [[[0.125, '0.333333', 1], [0.125, '0.333333', 1]], [0.04, 0.04], 0.08],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('roundingCases')]
    public function test_managed_amounts_match_department_rounding_without_changing_quantity(array $cases, array $expected, float $total): void
    {
        [$count, $service] = $this->roundingFixture($cases);
        $before = $count->items()->orderBy('id')->get()->toJson();
        $snapshot = $service->preview($count, '2026-09-30')['snapshot'];
        $managed = collect($snapshot['lines'])->where('kind', 'managed')->values();
        $this->assertSame($expected, $managed->pluck('amount')->all());
        $this->assertEquals($total, round($managed->sum('amount'), 2));
        $this->assertEquals($total - 720, $snapshot['total_amount']);
        foreach ($managed as $index => $line) {
            $this->assertEquals($cases[$index][0], $line['difference_quantity']);
            $this->assertEquals($cases[$index][0] + 12, $line['quantity_after']);
            $this->assertEquals($line['amount'], round((float) $line['unrounded_amount'], 2) + $line['rounding_adjustment']);
        }
        $this->assertSame($before, $count->items()->orderBy('id')->get()->toJson());
        $record = (object) ['snapshot' => json_encode($snapshot), 'inventory_adjustment_id' => null, 'status' => 'recorded'];
        $book = (new SundryFinalizationWorkbook)->build($record);
        $last = $managed->count() + 2;
        $this->assertEquals($total, $book->getSheet(1)->getCell('K'.$last)->getCalculatedValue());
        if ($managed->contains(fn ($line) => $line['rounding_adjustment'] != 0)) {
            $this->assertSame('内数：端数配賦', $book->getSheet(1)->getCell('L1')->getValue());
            $this->assertEquals(round($managed->sum('rounding_adjustment'), 2), $book->getSheet(1)->getCell('L'.$last)->getCalculatedValue());
            $this->assertSame('A1:L'.$last, $book->getSheet(1)->getPageSetup()->getPrintArea());
            $html = view('filament.resources.wms-sundry-inventory-count.pages.partials.finalization-preview', ['preview' => [
                'date' => '2026-09-30', 'count_date' => $snapshot['count_date'], 'total' => $snapshot['total_amount'], 'lines' => $snapshot['lines'],
            ]])->render();
            $this->assertStringContainsString('内数：端数配賦', $html);
        }
        $book->disconnectWorksheets();
    }

    private function roundingFixture(array $cases): array
    {
        [$count, $service] = $this->fixture();
        $db = DB::connection('sakemaru');
        $db->table('item_categories')->insert(['id' => 10, 'code' => '1001']);
        $original = $count->items()->firstOrFail();
        $master = (array) $db->table('items')->where('id', 4)->first();
        foreach ($cases as $index => [$delta, $price, $category]) {
            $itemId = 4 + $index;
            if ($index > 0) {
                $db->table('items')->insert(array_replace($master, ['id' => $itemId, 'code' => (string) $itemId, 'item_category1_id' => $category]));
            } else {
                $db->table('items')->where('id', $itemId)->update(['item_category1_id' => $category]);
            }
            $row = $index === 0 ? $original : $original->replicate();
            $row->fill(['item_id' => $itemId, 'item_code' => (string) $itemId, 'counted_quantity' => 10 + $delta])->save();
            $db->table('item_cost_snapshots')->updateOrInsert(['client_id' => 6, 'item_id' => $itemId, 'target_year_month' => '2026-09'], ['cost_unit_price' => $price]);
            $db->table('review_balances')->updateOrInsert(['item_id' => $itemId], ['quantity' => 10, 'posting_quantity' => 12]);
        }
        return [$count, $service];
    }

    public function test_rounding_with_book_correction_keeps_the_quantity_amount_separate(): void
    {
        [$count, $service] = $this->roundingFixture([[1, '0.005', 1]]);
        $count->amounts()->update(['counted_amount' => 1120]);
        config(['sundry_book_reconciliation.managed_gaps' => [10 => [4 => ['category1_code' => 1004, 'amount' => 1]]]]);
        DB::connection('sakemaru')->table('review_reference')->update(['amount' => 1001]);
        $snapshot = $service->preview($count, '2026-09-30')['snapshot'];
        $line = $snapshot['lines'][0];
        $this->assertSame(1.0, $line['difference_quantity']);
        $this->assertSame(13.0, $line['quantity_after']);
        $this->assertSame(0.0, $line['inventory_difference_amount']);
        $this->assertSame(-0.01, $line['rounding_adjustment']);
        $this->assertSame(-1.0, $line['book_reconciliation']['amount']);
        $this->assertSame(-1.0, $line['amount']);
        $this->assertSame(-1.0, $snapshot['total_amount']);
    }

    public function test_department_rounding_invariant_for_many_signed_lines_and_repeated_calculation(): void
    {
        $lines = [];
        $rawTotals = [];
        for ($index = 1; $index <= 300; $index++) {
            $category = $index % 3;
            $raw = \Brick\Math\BigDecimal::of(($index % 31) - 15)->multipliedBy('0.'.str_pad((string) (($index * 137) % 1000000), 6, '0', STR_PAD_LEFT));
            $amount = $raw->toScale(2, \Brick\Math\RoundingMode::HALF_UP)->toFloat();
            $lines[] = ['kind' => 'managed', 'category1_id' => $category, 'unrounded_amount' => (string) $raw, 'amount' => $amount];
            $rawTotals[$category] = ($rawTotals[$category] ?? \Brick\Math\BigDecimal::zero())->plus($raw);
        }
        foreach ($rawTotals as $category => $raw) {
            $lines[] = ['kind' => 'amount', 'category1_id' => $category, 'amount' => -720.25];
            $rawTotals[$category] = $raw->minus('720.25');
        }
        $calculator = new SundryFinalizationCalculator;
        $method = new \ReflectionMethod($calculator, 'allocateManagedRounding');
        $result = $method->invoke($calculator, $lines);
        foreach (collect($result)->groupBy('category1_id') as $category => $rows) {
            $this->assertSame($rawTotals[$category]->toScale(2, \Brick\Math\RoundingMode::HALF_UP)->toFloat(), round($rows->sum('amount'), 2));
        }
        foreach (collect($result)->where('kind', 'managed') as $line) {
            $this->assertLessThanOrEqual(0.01, abs($line['rounding_adjustment']));
            if (\Brick\Math\BigDecimal::of($line['unrounded_amount'])->isZero()) {
                $this->assertSame(0.0, $line['amount']);
                $this->assertSame(0.0, $line['rounding_adjustment']);
            }
        }
        $this->assertSame($result, $method->invoke($calculator, $result));
    }
}
