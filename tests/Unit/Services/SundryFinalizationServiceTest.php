<?php

namespace Tests\Unit\Services;

use App\Models\WmsSundryInventoryCount;
use App\Services\InventoryCount\InventoryCountLedgerBalanceService;
use App\Services\SundryInventoryCount\SundryFinalizationCalculator;
use App\Services\SundryInventoryCount\SundryFinalizationScope;
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
    public function test_customer_submission_is_amount_only_keeps_inputs_and_requires_approval(): void
    {
        [$count, $service] = $this->fixture();
        $db = DB::connection('sakemaru');
        Schema::connection('sakemaru')->table('item_categories', function (Blueprint $t): void {
            $t->integer('client_id')->nullable(); $t->integer('depth')->nullable();
        });
        $db->table('item_categories')->where('id', 1)->update(['client_id' => 6, 'depth' => 1, 'name' => '雑貨']);
        config(['sundry_submitted_difference' => array_replace(require config_path('sundry_submitted_difference.php'), [
            'counts' => [$count->id => ['warehouse_code' => '10', 'amount' => -20030]],
        ])]);
        $before = $count->amounts()->get()->toJson();
        $scope = SundryFinalizationScope::SUBMITTED_SEPTEMBER;
        $preview = $service->preview($count, '2026-09-30', $scope);
        $this->assertSame(['managed', 'book'], array_column($preview['snapshot']['lines'], 'kind'));
        $this->assertSame(-20330.0, $preview['snapshot']['total_amount']);
        $line = $preview['snapshot']['lines'][1];
        $this->assertNull($line['item_id']);
        $this->assertNull($line['difference_quantity']);
        $this->assertValidationRejected(fn () => $service->preview($count, '2026-10-01', $scope));
        $this->assertValidationRejected(fn () => $service->finalize($count, '2026-09-30', $preview['token'], null, false, $scope));
        $service->finalize($count, '2026-09-30', $preview['token'], null, true, $scope);
        $this->assertSame($before, $count->amounts()->get()->toJson());
        $book = (new SundryFinalizationWorkbook)->build(SundryFinalizationService::record($count->id));
        $this->assertSame(-20330.0, $book->getSheetByName('確定差異')->getCell('B8')->getCalculatedValue());
        $this->assertStringContainsString('顧客提出', $book->getSheetByName('部門不明差異')->getCell('I2')->getValue());
        $book->disconnectWorksheets();
    }

    public function test_submitted_policy_is_bound_to_thirteen_counts_and_assigns_91_92_only_to_91(): void
    {
        $policy = require config_path('sundry_submitted_difference.php');
        $this->assertCount(13, $policy['counts']);
        $this->assertSame(-6787406, array_sum(array_column($policy['counts'], 'amount')));
        $this->assertSame(['91', '92'], $policy['counts'][26]['includes_warehouse_codes']);
        $this->assertArrayNotHasKey(12, $policy['counts']);
        $this->assertArrayNotHasKey(28, $policy['counts']);
        $this->assertArrayNotHasKey(30, $policy['counts']);
    }

    public function test_submitted_history_uses_confirmed_department_opening_and_requires_full_recount(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-11-06'));
        [$count, $service] = $this->fixture('2026-09-30');
        $count->update(['count_date' => '2026-11-06']);
        $db = DB::connection('sakemaru');
        $db->table('review_reference')->update(['amount' => 850]);
        $db->table('item_cost_snapshots')->insert(['client_id' => 6, 'item_id' => 4, 'target_year_month' => '2026-10', 'cost_unit_price' => 100]);
        $db->table('wms_sundry_book_reconciliations')->insert(['client_id' => 6, 'warehouse_id' => 10, 'finalization_id' => 100,
            'approval_key' => 'submitted-2026-09-unmanaged-41-47', 'category1_code' => '1004',
            'process_date' => '2026-09-30', 'adjustment_amount' => -200, 'source_snapshot' => '{}']);
        $snapshot = $service->preview($count, '2026-10-31')['snapshot'];
        $book = collect($snapshot['lines'])->firstWhere('kind', 'book');
        $this->assertSame(50.0, $snapshot['report_reconciliations'][1]['department_reference_gap']);
        $this->assertSame(0.0, $snapshot['report_reconciliations'][1]['unknown_gap']);
        $this->assertSame(150.0, $book['amount']);
        $this->assertSame(-870.0, $snapshot['total_amount']);
        config(['sundry_test.categories' => [['category2_id' => 2, 'category2_code' => '2041'], ['category2_id' => 47, 'category2_code' => '2047']]]);
        // The missing middle category must not silently become a zero appraisal.
        $this->assertValidationRejected(fn () => $service->preview($count, '2026-10-31'));
        $this->assertSame(0, $db->table('inventory_adjustment_queue')->count());
    }

    public function test_next_full_inventory_absorbs_major_adjustment_without_repeating_unknown_difference(): void
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-11-06'));
        [$count, $service] = $this->fixture('2026-09-30');
        $count->update(['count_date' => '2026-11-06']);
        $db = DB::connection('sakemaru');
        $db->table('review_reference')->update(['amount' => 800]);
        $db->table('wms_sundry_book_reconciliations')->insert([
            'client_id' => 6, 'warehouse_id' => 10, 'finalization_id' => 100,
            'approval_key' => 'submitted-2026-09-unmanaged-41-47', 'category1_code' => '1004',
            'process_date' => '2026-09-30', 'adjustment_amount' => -200, 'source_snapshot' => '{}',
        ]);
        $db->table('item_cost_snapshots')->insert(['client_id' => 6, 'item_id' => 4, 'target_year_month' => '2026-10', 'cost_unit_price' => 100]);
        $preview = $service->preview($count, '2026-10-31');
        $books = collect($preview['snapshot']['lines'])->where('kind', 'book')->values();
        $this->assertCount(1, $books);
        $this->assertSame('absorption', $books[0]['department_adjustment']['type']);
        $this->assertSame(200.0, $books[0]['amount']);
        $this->assertSame(-820.0, $preview['snapshot']['total_amount']);
        $this->assertSame(0.0, $preview['snapshot']['report_reconciliations'][1]['unknown_gap']);
        $db->table('wms_sundry_book_reconciliations')->insert([
            'client_id' => 6, 'warehouse_id' => 10, 'finalization_id' => 101,
            'approval_key' => $books[0]['book_reconciliation']['approval_key'], 'category1_code' => '1004',
            'process_date' => '2026-10-31', 'adjustment_amount' => 200, 'source_snapshot' => json_encode($books[0]),
        ]);
        $policy = new \App\Services\SundryInventoryCount\SundrySubmittedDifference;
        $this->assertSame(0.0, $policy->balance($count, '1004', '2026-10-31'));
        $this->assertSame(-200.0, $policy->balance($count, '1004', '2026-09-30'));
    }

    public function test_managed_scope_skips_null_and_all_amount_inputs_but_keeps_explicit_zero(): void
    {
        [$count, $service] = $this->fixture();
        $item = $count->items()->sole();
        $item->update(['counted_quantity' => 0]);
        $missing = $item->replicate();
        $missing->fill(['item_id' => 999, 'item_code' => '999', 'item_name' => '=UNENTERED', 'counted_quantity' => null])->save();
        $count->amounts()->update(['counted_amount' => null]);
        DB::connection('sakemaru')->table('review_reference')->update(['amount' => 1500]);
        $inputs = [$count->items()->get()->toJson(), $count->amounts()->get()->toJson()];

        $preview = $service->preview($count, '2026-09-30', SundryFinalizationScope::ENTERED_MANAGED);
        $snapshot = $preview['snapshot'];
        $this->assertCount(1, $snapshot['lines']);
        $this->assertCount(2, $snapshot['excluded_lines']);
        $this->assertCount(2, $snapshot['inputs']['items']);
        $this->assertCount(1, $snapshot['inputs']['amounts']);
        $this->assertSame([], $snapshot['report_reconciliations']);
        $this->assertSame(-10.0, $snapshot['lines'][0]['difference_quantity']);
        $this->assertSame(2.0, $snapshot['lines'][0]['quantity_after']);
        $this->assertSame(-1000.0, $snapshot['total_amount']);
        $id = $service->finalize($count, '2026-09-30', $preview['token'], null, false, SundryFinalizationScope::ENTERED_MANAGED);
        $this->assertSame($id, $service->finalize($count, '2026-09-30', $preview['token'], null, false, SundryFinalizationScope::ENTERED_MANAGED));
        $this->assertSame($inputs, [$count->items()->get()->toJson(), $count->amounts()->get()->toJson()]);
        $this->assertEquals(91, DB::connection('sakemaru')->table('real_stocks')->value('quantity'));
        $this->assertSame(1, DB::connection('sakemaru')->table('inventory_adjustment_queue')->count());
        $book = (new SundryFinalizationWorkbook)->build(SundryFinalizationService::record($count->id));
        $this->assertSame(-1000.0, $book->getSheetByName('確定差異')->getCell('B8')->getCalculatedValue());
        $this->assertSame(2, $book->getSheetByName('確定差異')->getCell('B13')->getValue());
        $excluded = $book->getSheetByName('適用対象外');
        $this->assertSame('s', $excluded->getCell('D2')->getDataType());
        $this->assertSame('=UNENTERED', $excluded->getCell('D2')->getValue());
        $this->assertSame('A1:E3', $excluded->getPageSetup()->getPrintArea());
        $book->disconnectWorksheets();
    }

    public function test_managed_scope_ignores_entered_nonmanaged_amount_without_overwriting_it(): void
    {
        [$count, $service] = $this->fixture();
        $count->amounts()->update(['counted_amount' => 999999]);
        $preview = $service->preview($count, '2026-09-30', SundryFinalizationScope::ENTERED_MANAGED);
        $this->assertSame(-300.0, $preview['snapshot']['total_amount']);
        $this->assertSame(['managed'], array_column($preview['snapshot']['lines'], 'kind'));
        $this->assertSame(999999.0, $count->amounts()->sole()->counted_amount);
    }

    public function test_all_scope_still_rejects_unentered_managed_and_nonmanaged_rows(): void
    {
        [$count, $service] = $this->fixture();
        $count->items()->update(['counted_quantity' => null]);
        $this->assertValidationRejected(fn () => $service->preview($count, '2026-09-30'));
        $count->items()->update(['counted_quantity' => 7]);
        $count->amounts()->update(['counted_amount' => null]);
        $this->assertValidationRejected(fn () => $service->preview($count, '2026-09-30'));
    }

    public function test_managed_scope_rejects_empty_selection_and_unknown_scope(): void
    {
        [$count, $service] = $this->fixture();
        $count->items()->update(['counted_quantity' => null]);
        $this->assertValidationRejected(fn () => $service->preview($count, '2026-09-30', SundryFinalizationScope::ENTERED_MANAGED));
        $this->assertValidationRejected(fn () => $service->preview($count, '2026-09-30', 'invalid'));
        $this->assertSame(0, DB::connection('sakemaru')->table('inventory_adjustment_queue')->count());
    }

    public function test_scope_changes_cannot_reuse_preview_or_finalization_token(): void
    {
        [$count, $service] = $this->fixture();
        $preview = $service->preview($count, '2026-09-30', SundryFinalizationScope::ENTERED_MANAGED);
        $this->assertValidationRejected(fn () => $service->finalize($count, '2026-09-30', $preview['token'], null));
        $service->finalize($count, '2026-09-30', $preview['token'], null, false, SundryFinalizationScope::ENTERED_MANAGED);
        $this->assertValidationRejected(fn () => $service->finalize($count, '2026-09-30', $preview['token'], null));
        $this->assertSame(1, DB::connection('sakemaru')->table('inventory_adjustment_queue')->count());
    }

    public function test_changes_to_excluded_inputs_invalidate_the_preview(): void
    {
        [$count, $service] = $this->fixture();
        $preview = $service->preview($count, '2026-09-30', SundryFinalizationScope::ENTERED_MANAGED);
        $count->amounts()->update(['counted_amount' => 500]);
        $this->assertValidationRejected(fn () => $service->finalize($count, '2026-09-30', $preview['token'], null, false, SundryFinalizationScope::ENTERED_MANAGED));
        $this->assertSame(0, DB::connection('sakemaru')->table('inventory_adjustment_queue')->count());
    }

    public function test_zero_owned_parent_is_preserved_and_excluded_from_weighed_finalization(): void
    {
        [$count, $service] = $this->fixture();
        $count->update(['kind' => 'weighed']);
        $parent = $count->items()->sole()->replicate();
        $parent->fill(['item_id' => 3, 'item_code' => '129011', 'system_quantity' => 0, 'counted_quantity' => 0,
            'counted_quantity_jar' => 0, 'counted_quantity_reserve' => 0])->recalculate()->save();
        $before = $parent->fresh()->getAttributes();
        foreach ([SundryFinalizationScope::ALL, SundryFinalizationScope::ENTERED_MANAGED] as $scope) {
            $preview = $service->preview($count, '2026-09-30', $scope);
            $this->assertNotContains(3, array_column($preview['snapshot']['lines'], 'item_id'));
            $excluded = collect($preview['snapshot']['excluded_lines'])->firstWhere('item_code', '129011');
            $this->assertSame($parent->id, $excluded['source_id']);
            $this->assertStringContainsString('自社セット親', $excluded['reason']);
        }
        $service->finalize($count, '2026-09-30', $preview['token'], null, false, SundryFinalizationScope::ENTERED_MANAGED);
        $this->assertSame($before, $parent->fresh()->getAttributes());
    }

    public function test_owned_parent_is_not_silently_excluded_when_saved_values_or_current_ledger_are_nonzero(): void
    {
        [$count, $service] = $this->fixture();
        $count->update(['kind' => 'weighed']);
        $parent = $count->items()->sole()->replicate();
        $parent->fill(['item_id' => 3, 'item_code' => '129011', 'system_quantity' => 0, 'counted_quantity' => 0])->recalculate()->save();
        foreach (['system_quantity', 'counted_quantity', 'difference_quantity', 'counted_quantity_jar', 'counted_quantity_reserve', 'difference_amount'] as $field) {
            $parent->update([$field => 1]);
            $this->assertValidationRejected(fn () => $service->preview($count, '2026-09-30'));
            $parent->update([$field => 0]);
        }
        DB::connection('sakemaru')->table('review_balances')->insert(['item_id' => 3, 'quantity' => 1, 'posting_quantity' => 0]);
        $this->assertValidationRejected(fn () => $service->preview($count, '2026-09-30'));
        DB::connection('sakemaru')->table('review_balances')->where('item_id', 3)->update(['quantity' => 0]);
        $count->update(['kind' => 'sundry']);
        $this->assertValidationRejected(fn () => $service->preview($count, '2026-09-30'));
        $this->assertSame(0, DB::connection('sakemaru')->table('inventory_adjustment_queue')->count());
    }

    public function test_scope_and_exclusions_are_visible_and_html_escaped_in_confirmation(): void
    {
        [$count, $service] = $this->fixture();
        $count->amounts()->update(['category2_name' => '<script>unsafe</script>']);
        $snapshot = $service->preview($count, '2026-09-30', SundryFinalizationScope::ENTERED_MANAGED)['snapshot'];
        $html = view('filament.resources.wms-sundry-inventory-count.pages.partials.finalization-preview', [
            'preview' => $snapshot + ['date' => '2026-09-30', 'total' => $snapshot['total_amount']],
        ])->render();
        $this->assertStringContainsString('入力済み管理品のみ', $html);
        $this->assertStringContainsString('対象外 1件', $html);
        $this->assertStringContainsString('&lt;script&gt;unsafe&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>unsafe</script>', $html);
    }

    public function test_confirmation_actions_pass_the_selected_scope_through_both_steps(): void
    {
        [$count, $service] = $this->fixture();
        $ids = [$count->items()->sole()->id];
        $preview = $service->preview($count, '2026-09-30', SundryFinalizationScope::ENTERED_MANAGED, $ids);
        $mock = \Mockery::mock(SundryFinalizationService::class);
        $mock->shouldReceive('preview')->once()->with($count, '2026-09-30', SundryFinalizationScope::ENTERED_MANAGED, $ids)->andReturn($preview);
        $mock->shouldReceive('finalize')->once()->with($count, '2026-09-30', $preview['token'], null, false, SundryFinalizationScope::ENTERED_MANAGED, $ids)->andReturn(1);
        $mock->shouldNotReceive('requestApplication');
        $this->app->instance(SundryFinalizationService::class, $mock);
        $class = \App\Filament\Resources\WmsSundryInventoryCount\Pages\ViewWmsSundryInventoryCount::class;
        $page = \Mockery::mock($class)->makePartial();
        $page->record = $count;
        $page->shouldReceive('replaceMountedAction')->once()->with('finalizeSundryConfirmation')->andReturnNull();
        $actions = (new \ReflectionMethod($class, 'getHeaderActions'))->invoke($page);
        $action = collect($actions)->first(fn ($action) => $action->getName() === 'finalizeSundry');
        ($action->getActionFunction())(['date' => '2026-09-30', 'application_scope' => SundryFinalizationScope::ENTERED_MANAGED, 'zero_difference_row_ids' => $ids]);
        $this->assertSame(SundryFinalizationScope::ENTERED_MANAGED, $page->finalizationPreview['application_scope']);
        $this->assertSame($ids, $page->finalizationPreview['zero_difference_row_ids']);
        $this->assertCount(2, $page->finalizationPreview['excluded_lines']);
        ($page->finalizeSundryConfirmationAction()->getActionFunction())([]);
        $this->assertSame([], $page->finalizationPreview);
    }

    public function test_zero_difference_picker_is_opt_in_and_only_lists_entered_managed_rows_in_this_count(): void
    {
        [$count] = $this->fixture();
        $entered = $count->items()->sole();
        DB::connection('sakemaru')->table('items')->where('id', 2)->update(['is_managed_stock' => true]);
        foreach (['null', 'foreign_count', 'unmanaged', 'owned'] as $kind) {
            $row = $entered->replicate();
            match ($kind) {
                'null' => $row->fill(['item_id' => 2, 'counted_quantity' => null]),
                'foreign_count' => $row->sundry_inventory_count_id = $count->id + 1,
                'unmanaged' => $row->item_id = 1,
                'owned' => $row->item_id = 3,
            };
            $row->save();
        }
        DB::connection('sakemaru')->table('items')->where('id', 3)->update(['is_managed_stock' => true]);
        $class = \App\Filament\Resources\WmsSundryInventoryCount\Pages\ViewWmsSundryInventoryCount::class;
        $page = new $class;
        $page->record = $count;
        $options = $page->zeroDifferenceOptions();
        $this->assertSame([$entered->id], array_column($options, 'id'));
        $actions = (new \ReflectionMethod($class, 'getHeaderActions'))->invoke($page);
        $action = collect($actions)->first(fn ($action) => $action->getName() === 'finalizeSundry');
        $schema = $action->getSchema(\Filament\Schemas\Schema::make($page));
        $field = collect($schema->getComponents())->first(fn ($field) => $field->getName() === 'zero_difference_row_ids');
        $this->assertSame([], $field->getDefaultState());
        view()->share('errors', new \Illuminate\Support\ViewErrorBag);
        $this->assertStringContainsString('差異0指定', $field->toHtml());
        $this->assertStringContainsString('入力実棚', $field->toHtml());
    }

    private function assertValidationRejected(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Invalid finalization was accepted.');
        } catch (ValidationException $exception) {
            $this->assertNotEmpty($exception->errors());
        }
    }

    private function fixture(string $referenceDate = '2026-08-31'): array
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
        $theory = new class($referenceDate) extends SundryInventoryTheoryCalculator
        {
            public function __construct(private readonly string $referenceDate) {}

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
                return ['date' => $this->referenceDate, 'amount' => (float) DB::connection('sakemaru')->table('review_reference')->value('amount')];
            }
            public function unmanagedCategories(int $clientId, array $category1Ids, array $category2Ids = []): \Illuminate\Support\Collection
            {
                return collect(config('sundry_test.categories', [['category2_id' => 2, 'category2_code' => '2041']]))->map(fn ($r) => (object) $r);
            }
        };
        $ledger = new class extends InventoryCountLedgerBalanceService
        {
            public function countBalancesBeforeEligibilityByItem(int $clientId, int $warehouseId, string $endDate): array
            {
                return DB::connection('sakemaru')->table('review_balances')->pluck('quantity', 'item_id')->all();
            }

            public function balancesBeforeAdjustmentByItem(int $clientId, int $warehouseId, string $date): array
            {
                return DB::connection('sakemaru')->table('review_balances')->pluck('posting_quantity', 'item_id')->all();
            }
        };
        return [$count, new SundryFinalizationService(new SundryFinalizationCalculator($theory, $ledger))];
    }

    public function test_explicit_zero_difference_survives_theory_changes_without_rewriting_input(): void
    {
        [$count, $service] = $this->fixture();
        $item = $count->items()->sole();
        $item->update(['system_quantity' => -5, 'counted_quantity' => -5, 'difference_quantity' => 0]);
        $before = $item->fresh()->getAttributes();
        $ids = [$item->id];
        DB::connection('sakemaru')->table('review_balances')->where('item_id', 4)->update(['quantity' => -1]);
        $ordinary = $service->preview($count, '2026-09-30', SundryFinalizationScope::ENTERED_MANAGED);
        $this->assertSame(-4.0, $ordinary['snapshot']['lines'][0]['difference_quantity']);
        $preview = $service->preview($count, '2026-09-30', SundryFinalizationScope::ENTERED_MANAGED, $ids);
        $this->assertSame([], $preview['snapshot']['lines']);
        $this->assertSame(0.0, $preview['snapshot']['total_amount']);
        $excluded = collect($preview['snapshot']['excluded_lines'])->firstWhere('quantity_policy', 'no_adjustment');
        $this->assertSame(-5.0, $excluded['count_actual']);
        $this->assertSame(-1.0, $excluded['count_theory']);
        $this->assertSame(0.0, $excluded['difference_quantity']);
        $this->assertSame($before, $excluded['original_count_row']);
        $this->assertSame($preview['token'], $service->preview($count, '2026-09-30', SundryFinalizationScope::ENTERED_MANAGED, [(string) $item->id, $item->id])['token']);
        $id = $service->finalize($count, '2026-09-30', $preview['token'], 77, false, SundryFinalizationScope::ENTERED_MANAGED, $ids);
        $snapshot = json_decode(SundryFinalizationService::record($count->id)->snapshot, true);
        $this->assertSame($ids, $snapshot['zero_difference_approval']['row_ids']);
        $this->assertSame(77, $snapshot['zero_difference_approval']['user_id']);
        $this->assertSame($id, $service->finalize($count, '2026-09-30', $preview['token'], 77, false, SundryFinalizationScope::ENTERED_MANAGED, $ids));
        $this->assertValidationRejected(fn () => $service->finalize($count, '2026-09-30', $preview['token'], 77, false, SundryFinalizationScope::ENTERED_MANAGED));
        $this->assertSame($before, $item->fresh()->getAttributes());
        $this->assertEquals(91, DB::connection('sakemaru')->table('real_stocks')->value('quantity'));
        $this->assertSame(1, DB::connection('sakemaru')->table('inventory_adjustment_queue')->count());
        $book = (new SundryFinalizationWorkbook)->build(SundryFinalizationService::record($count->id));
        $this->assertSame(1, $book->getSheetByName('確定差異')->getCell('B14')->getValue());
        $sheet = $book->getSheetByName('適用対象外');
        $this->assertSame(-5.0, $sheet->getCell('G3')->getValue());
        $this->assertSame(0.0, $sheet->getCell('H3')->getValue());
        $html = view('filament.resources.wms-sundry-inventory-count.pages.partials.finalization-preview', [
            'preview' => $preview['snapshot'] + ['date' => '2026-09-30', 'total' => 0],
        ])->render();
        $this->assertStringContainsString('差異0指定', $html);
        $this->assertStringContainsString('再計算理論', $html);
        $this->assertStringContainsString('-5.000', $html);
    }

    public function test_zero_difference_selection_cannot_reuse_a_token_or_ignore_other_inputs(): void
    {
        [$count, $service] = $this->fixture();
        $item = $count->items()->sole();
        $selected = [$item->id];
        $preview = $service->preview($count, '2026-09-30', SundryFinalizationScope::ENTERED_MANAGED, $selected);
        $this->assertValidationRejected(fn () => $service->finalize($count, '2026-09-30', $preview['token'], null, false, SundryFinalizationScope::ENTERED_MANAGED));
        $item->update(['counted_quantity' => 8]);
        $this->assertValidationRejected(fn () => $service->finalize($count, '2026-09-30', $preview['token'], null, false, SundryFinalizationScope::ENTERED_MANAGED, $selected));
        $this->assertSame(0, DB::connection('sakemaru')->table('inventory_adjustment_queue')->count());
        $count->amounts()->update(['counted_amount' => 400]);
        $all = $service->preview($count, '2026-09-30', SundryFinalizationScope::ALL, $selected)['snapshot'];
        $this->assertCount(1, $all['lines']);
        $this->assertSame('amount', $all['lines'][0]['kind']);
        $this->assertSame(-720.0, $all['lines'][0]['amount']);
    }

    public function test_zero_difference_leaves_other_managed_rows_and_their_differences_unchanged(): void
    {
        [$count, $service] = $this->fixture();
        $db = DB::connection('sakemaru');
        $master = (array) $db->table('items')->where('id', 4)->first();
        $master['id'] = 5;
        $master['code'] = '405';
        $db->table('items')->insert($master);
        $second = $count->items()->sole()->replicate();
        $second->fill(['item_id' => 5, 'item_code' => '405', 'counted_quantity' => 0])->save();
        $db->table('review_balances')->insert(['item_id' => 5, 'quantity' => 2, 'posting_quantity' => 4]);
        $db->table('item_cost_snapshots')->insert(['client_id' => 6, 'item_id' => 5, 'target_year_month' => '2026-09', 'cost_unit_price' => 100]);
        $before = $count->items()->orderBy('id')->get()->map->getAttributes()->all();
        $snapshot = $service->preview($count, '2026-09-30', SundryFinalizationScope::ENTERED_MANAGED, [$before[0]['id']])['snapshot'];
        $this->assertCount(1, $snapshot['lines']);
        $this->assertSame(5, $snapshot['lines'][0]['item_id']);
        $this->assertSame(-2.0, $snapshot['lines'][0]['difference_quantity']);
        $this->assertSame(2.0, $snapshot['lines'][0]['quantity_after']);
        $this->assertSame(-200.0, $snapshot['total_amount']);
        $this->assertSame($before, $count->items()->orderBy('id')->get()->map->getAttributes()->all());
    }

    public function test_zero_difference_selection_rejects_null_foreign_owned_and_malformed_rows(): void
    {
        [$count, $service] = $this->fixture();
        foreach ([[0], [-1], [true], [1.2], ['invalid'], [[]], [99999]] as $ids) {
            $this->assertValidationRejected(fn () => $service->preview($count, '2026-09-30', SundryFinalizationScope::ENTERED_MANAGED, $ids));
        }
        $item = $count->items()->sole();
        $item->update(['counted_quantity' => null]);
        $this->assertValidationRejected(fn () => $service->preview($count, '2026-09-30', SundryFinalizationScope::ENTERED_MANAGED, [$item->id]));
        $count->update(['kind' => 'weighed']);
        $item->update(['item_id' => 3, 'item_code' => '129011', 'system_quantity' => 0, 'counted_quantity' => 0, 'difference_quantity' => 0, 'difference_amount' => 0]);
        $this->assertValidationRejected(fn () => $service->preview($count, '2026-09-30', SundryFinalizationScope::ENTERED_MANAGED, [$item->id]));
    }

    private function actualLedgerFixture(): array
    {
        [$count] = $this->fixture();
        $count->update(['kind' => 'weighed']);
        $db = DB::connection('sakemaru');
        $schema = Schema::connection('sakemaru');
        $db->table('items')->where('id', 3)->update(['is_managed_stock' => true, 'code' => '129011']);
        $parent = $count->items()->sole()->replicate();
        $parent->fill(['item_id' => 3, 'item_code' => '129011', 'system_quantity' => 0, 'counted_quantity' => 0,
            'difference_quantity' => 0, 'difference_amount' => 0, 'counted_quantity_jar' => 0])->save();
        foreach ([
            'stats_item_stock_opening_balances' => ['client_id', 'warehouse_id', 'opening_date', 'item_id', 'opening_quantity'],
            'purchases' => ['trade_id', 'warehouse_id', 'is_active'],
            'earnings' => ['trade_id', 'warehouse_id', 'is_active', 'delivered_date'],
            'stock_transfers' => ['trade_id', 'from_warehouse_id', 'to_warehouse_id', 'is_active', 'is_delivered', 'picking_date', 'delivered_date'],
        ] as $table => $columns) {
            $schema->create($table, function (Blueprint $t) use ($columns): void {
                $t->id();
                foreach ($columns as $column) { $t->string($column)->nullable(); }
            });
        }
        foreach ([
            'trades' => ['client_id', 'process_date', 'trade_category', 'is_returned', 'trade_direction'],
            'trade_items' => ['trade_id', 'is_active', 'total_piece_quantity', 'quantity_type', 'quantity', 'capacity_case', 'capacity_carton'],
            'inventory_adjustment_items' => ['stock_quantity_before', 'stock_quantity_after', 'applied_stock_quantity_after', 'applied_stock_quantity_before', 'inventory_adjustment_quantity'],
        ] as $table => $columns) {
            $schema->table($table, function (Blueprint $t) use ($columns): void {
                foreach ($columns as $column) { $t->string($column)->nullable(); }
            });
        }
        foreach ([3 => 0, 4 => 10] as $item => $quantity) {
            $db->table('stats_item_stock_opening_balances')->insert(['client_id' => 6, 'warehouse_id' => $count->warehouse_id,
                'opening_date' => '2026-05-06', 'item_id' => $item, 'opening_quantity' => $quantity]);
        }
        $ledger = new InventoryCountLedgerBalanceService;
        return [$count, new SundryFinalizationService(new SundryFinalizationCalculator(new SundryInventoryTheoryCalculator, $ledger)), $ledger];
    }

    public function test_owned_guard_checks_real_unfiltered_count_ledger_instead_of_filtered_theory(): void
    {
        [$count, $service, $ledger] = $this->actualLedgerFixture();
        $db = DB::connection('sakemaru');
        foreach ([1, -1] as $quantity) {
            $db->table('stats_item_stock_opening_balances')->where('item_id', 3)->update(['opening_quantity' => $quantity]);
            $this->assertArrayNotHasKey(3, $ledger->balancesByItem(6, $count->warehouse_id, '2026-10-06'));
            $this->assertSame((float) $quantity, $ledger->countBalancesBeforeEligibilityByItem(6, $count->warehouse_id, '2026-10-06')[3]);
            $this->assertValidationRejected(fn () => $db->transaction(fn () => $service->preview($count, '2026-09-30', SundryFinalizationScope::ENTERED_MANAGED)));
        }
        $db->table('stats_item_stock_opening_balances')->where('item_id', 3)->update(['opening_quantity' => 0]);
        $preview = $db->transaction(fn () => $service->preview($count, '2026-09-30', SundryFinalizationScope::ENTERED_MANAGED));
        $this->assertNotContains(3, array_column($preview['snapshot']['lines'], 'item_id'));
        $this->assertSame(0.0, $count->items()->where('item_id', 3)->sole()->counted_quantity);
    }

    public function test_unfiltered_count_ledger_keeps_receipt_completion_and_date_rules(): void
    {
        [$count, $service, $ledger] = $this->actualLedgerFixture();
        $db = DB::connection('sakemaru');
        $db->table('trades')->insert(['id' => 1, 'client_id' => 6, 'trade_category' => 'STOCK_TRANSFER', 'process_date' => '2026-10-08', 'is_active' => 1, 'is_latest' => 1]);
        $db->table('trade_items')->insert(['id' => 1, 'trade_id' => 1, 'item_id' => 3, 'is_active' => 1, 'total_piece_quantity' => 5, 'quantity' => 5, 'quantity_type' => 'PIECE']);
        $db->table('stock_transfers')->insert(['id' => 1, 'trade_id' => 1, 'from_warehouse_id' => 91, 'to_warehouse_id' => $count->warehouse_id,
            'is_active' => 1, 'is_delivered' => 0, 'picking_date' => '2026-10-06', 'delivered_date' => '2026-10-06']);
        $this->assertSame(0.0, $ledger->countBalancesBeforeEligibilityByItem(6, $count->warehouse_id, '2026-10-06')[3]);
        $this->assertSame(5.0, $ledger->balancesBeforeAdjustmentByItem(6, $count->warehouse_id, '2026-10-06')[3]);
        $db->transaction(fn () => $service->preview($count, '2026-09-30', SundryFinalizationScope::ENTERED_MANAGED));
        $db->table('stock_transfers')->where('id', 1)->update(['is_delivered' => 1, 'delivered_date' => '2026-10-07']);
        $this->assertSame(0.0, $ledger->countBalancesBeforeEligibilityByItem(6, $count->warehouse_id, '2026-10-06')[3]);
        $db->table('stock_transfers')->where('id', 1)->update(['delivered_date' => '2026-10-06']);
        $this->assertSame(5.0, $ledger->countBalancesBeforeEligibilityByItem(6, $count->warehouse_id, '2026-10-06')[3]);
        $this->assertValidationRejected(fn () => $db->transaction(fn () => $service->preview($count, '2026-09-30', SundryFinalizationScope::ENTERED_MANAGED)));
        $this->assertSame(-5.0, $ledger->countBalancesBeforeEligibilityByItem(6, 91, '2026-10-06')[3]);
    }

    private function nextMonthFixture(): array
    {
        $this->travelTo(\Carbon\Carbon::parse('2026-11-07 12:00:00'));
        [$count, $service] = $this->fixture('2026-09-30');
        $db = DB::connection('sakemaru');
        $count->update(['count_date' => '2026-11-06', 'theory_end_date' => '2026-11-06']);
        $count->amounts()->update(['opening_date' => '2026-10-06', 'opening_source' => 'previous_count',
            'opening_amount' => 400, 'counted_amount' => 500]);
        $db->table('item_cost_snapshots')->update(['target_year_month' => '2026-10']);
        $db->table('review_reference')->update(['amount' => 280]);
        $db->table('review_flows')->insert([
            ['date' => '2026-10-20', 'purchase' => 200, 'sales_cost' => 80],
            ['date' => '2026-11-02', 'purchase' => 100, 'sales_cost' => 40],
        ]);
        $db->table('wms_sundry_amount_adjustments')->insert([
            'client_id' => 6, 'warehouse_id' => 10, 'sundry_inventory_count_id' => 99, 'amount_id' => 99,
            'category1_id' => 1, 'category1_code' => '1004', 'category2_id' => 2, 'category2_code' => '2041',
            'count_date' => '2026-10-06', 'process_date' => '2026-09-30', 'book_amount' => 1000,
            'counted_amount' => 280, 'adjustment_amount' => -720, 'book_reference' => 'Prior applied count',
            'source_snapshot' => '{}', 'is_active' => true,
        ]);
        foreach (['2026-09', '2026-10'] as $month) {
            $db->table('stats_monthly_stock_amount_reports')->insert(['client_id' => 6, 'warehouse_id' => 10, 'target_year_month' => $month]);
        }
        return [$count, $service];
    }

    public function test_november_count_can_be_posted_on_october_30_after_october_report_exists(): void
    {
        [$count, $service] = $this->nextMonthFixture();
        $db = DB::connection('sakemaru');
        $reports = $db->table('stats_monthly_stock_amount_reports')->get()->toJson();
        $inputs = [$count->items()->get()->toJson(), $count->amounts()->get()->toJson()];
        $preview = $service->preview($count, '2026-10-30');
        $snapshot = $preview['snapshot'];
        $amount = collect($snapshot['lines'])->firstWhere('kind', 'amount');
        $this->assertSame(580.0, $amount['count_theory']);
        $this->assertSame(520.0, $amount['book_before']);
        $this->assertSame(440.0, $amount['book_after']);
        $this->assertSame(-80.0, $amount['amount']);
        $this->assertSame(280.0, $snapshot['report_reconciliations'][1]['unmanaged_book_amount']);
        $this->assertEquals(0, $snapshot['report_reconciliations'][1]['unknown_gap']);
        $id = $service->finalize($count, '2026-10-30', $preview['token'], null);
        $db->table(SundryFinalizationService::TABLE)->where('id', $id)->update(['status' => 'recorded', 'inventory_adjustment_id' => 1]);
        $service->requestApplication($count, null);
        $this->assertSame('applying', SundryFinalizationService::record($count->id)->status);
        $this->assertSame($reports, $db->table('stats_monthly_stock_amount_reports')->get()->toJson());
        $this->assertSame($inputs, [$count->items()->get()->toJson(), $count->amounts()->get()->toJson()]);
        $this->assertSame(2, $db->table('inventory_adjustment_queue')->count());
        $this->assertSame(['2026-10-30'], $db->table('inventory_adjustment_queue')->distinct()->pluck('process_date')->all());
    }

    public function test_month_lock_is_client_wide_and_allows_only_the_latest_report_month(): void
    {
        [$count] = $this->fixture();
        $db = DB::connection('sakemaru');
        $db->table('stats_monthly_stock_amount_reports')->insert([
            ['client_id' => 6, 'warehouse_id' => 11, 'target_year_month' => '2026-10'],
            ['client_id' => 7, 'warehouse_id' => 10, 'target_year_month' => '2026-11'],
        ]);
        $calculator = new SundryFinalizationCalculator;
        $calculator->assertPeriodOpen($count, '2026-10-30');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('翌月以降');
        $calculator->assertPeriodOpen($count, '2026-09-30');
    }

    public function test_newer_report_after_preview_blocks_finalization(): void
    {
        [$count, $service] = $this->nextMonthFixture();
        $preview = $service->preview($count, '2026-10-30');
        DB::connection('sakemaru')->table('stats_monthly_stock_amount_reports')
            ->insert(['client_id' => 6, 'warehouse_id' => 11, 'target_year_month' => '2026-11']);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('翌月以降');
        $service->finalize($count, '2026-10-30', $preview['token'], null);
    }

    public function test_newer_report_after_finalization_blocks_application_request(): void
    {
        [$count, $service] = $this->nextMonthFixture();
        $preview = $service->preview($count, '2026-10-30');
        $id = $service->finalize($count, '2026-10-30', $preview['token'], null);
        $db = DB::connection('sakemaru');
        $db->table(SundryFinalizationService::TABLE)->where('id', $id)->update(['status' => 'recorded', 'inventory_adjustment_id' => 1]);
        $db->table('stats_monthly_stock_amount_reports')->insert(['client_id' => 6, 'warehouse_id' => 11, 'target_year_month' => '2026-11']);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('翌月以降');
        $service->requestApplication($count, null);
    }

    public function test_next_count_requires_the_matching_applied_opening_not_an_unrelated_journal(): void
    {
        [$count, $service] = $this->nextMonthFixture();
        DB::connection('sakemaru')->table('wms_sundry_amount_adjustments')->update(['count_date' => '2026-09-06']);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('前回棚卸しに対応する適用済み伝票がありません');
        $service->preview($count, '2026-10-30');
    }

    public function test_manual_opening_after_report_reference_is_not_silently_replaced(): void
    {
        [$count, $service] = $this->nextMonthFixture();
        $count->amounts()->update(['opening_source' => 'manual']);
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('前残基準日が報告書の照合日より後');
        $service->preview($count, '2026-10-30');
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
