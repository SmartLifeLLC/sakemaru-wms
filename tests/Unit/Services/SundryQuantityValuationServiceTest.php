<?php

namespace Tests\Unit\Services;

use App\Models\WmsSundryInventoryCount;
use App\Models\WmsSundryInventoryCountAmount;
use App\Models\WmsSundryInventoryCountItem;
use App\Models\WmsSundryValuationItem;
use App\Services\SundryInventoryCount\SundryAmountAdjustmentService;
use App\Services\SundryInventoryCount\SundryInventoryCountService;
use App\Services\SundryInventoryCount\SundryInventoryTheoryCalculator;
use App\Services\SundryInventoryCount\SundryQuantityValuationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SundryQuantityValuationServiceTest extends TestCase
{
    private WmsSundryInventoryCount $count;

    private WmsSundryInventoryCountAmount $amount;

    private SundryQuantityValuationService $service;

    private array $protectedRows;

    protected function setUp(): void
    {
        parent::setUp();
        DB::purge('sakemaru');
        config(['database.connections.sakemaru' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
            'wms_sundry_inventory.amount_posting_enabled' => true]);
        $this->assertSame(':memory:', DB::connection('sakemaru')->getDatabaseName());
        $schema = Schema::connection('sakemaru');
        foreach (['2026_10_03_000000_create_wms_sundry_inventory_count_tables.php', '2026_10_09_000000_add_weighed_kind_to_wms_sundry_inventory_count_tables.php'] as $file) {
            (require database_path('migrations/'.$file))->up();
        }
        $schema->create('clients', fn (Blueprint $t) => $t->id());
        $schema->create('items', function (Blueprint $t): void {
            $t->id();
            $t->integer('client_id');
            $t->string('code');
            $t->string('name');
            $t->boolean('is_managed_stock');
            $t->integer('item_category1_id');
            $t->integer('item_category2_id');
            $t->integer('item_category3_id');
            $t->integer('item_set_id')->nullable();
        });
        $schema->create('item_categories', function (Blueprint $t): void {
            $t->id();
            $t->decimal('cost_rate', 8, 4)->nullable();
        });
        $schema->create('item_sets', function (Blueprint $t): void {
            $t->id();
            $t->string('set_type');
            $t->boolean('is_active');
        });
        $schema->create('item_prices', function (Blueprint $t): void {
            $t->id();
            $t->integer('client_id');
            $t->integer('item_id');
            $t->date('start_date');
            $t->boolean('is_active');
            $t->decimal('sale_unit_price', 15, 4);
            $t->string('type');
            $t->decimal('tax_exempt_unit_price', 15, 4)->default(0);
        });
        $schema->create('stats_monthly_stock_amount_reports', function (Blueprint $t): void {
            $t->id();
            $t->integer('client_id');
            $t->integer('warehouse_id');
            $t->string('target_year_month');
        });
        $schema->create('stock_report_generation_runs', function (Blueprint $t): void {
            $t->id();
            $t->integer('client_id');
            $t->string('status');
        });
        foreach (['real_stocks', 'real_stock_lots', 'wms_inventory_counts', 'wms_inventory_count_items'] as $name) {
            $schema->create($name, function (Blueprint $t): void {
                $t->id();
                $t->integer('quantity');
            });
            $this->db()->table($name)->insert(['quantity' => 91]);
        }
        $this->db()->table('clients')->insert(['id' => 6]);
        $this->db()->table('item_categories')->insert([['id' => 1, 'cost_rate' => 50], ['id' => 2, 'cost_rate' => 65], ['id' => 3, 'cost_rate' => 80]]);
        $this->db()->table('item_sets')->insert(['id' => 1, 'set_type' => 'OWNED', 'is_active' => 1]);
        foreach ([1, 2, 3, 4] as $id) {
            $this->db()->table('items')->insert(['id' => $id, 'client_id' => 6, 'code' => '40'.$id, 'name' => '商品'.$id,
                'is_managed_stock' => $id === 4, 'item_category1_id' => 1, 'item_category2_id' => 2, 'item_category3_id' => 3,
                'item_set_id' => $id === 3 ? 1 : null]);
            $this->price($id, 100, '2026-09-01');
        }
        $this->count = WmsSundryInventoryCount::create(['count_no' => 'TEST', 'kind' => 'sundry', 'client_id' => 6, 'warehouse_id' => 10,
            'count_date' => '2026-10-06', 'theory_end_date' => '2026-10-06', 'status' => 'counting']);
        $this->amount = WmsSundryInventoryCountAmount::create(['sundry_inventory_count_id' => $this->count->id,
            'category1_id' => 1, 'category1_code' => '1004', 'category1_name' => '雑貨', 'category2_id' => 2, 'category2_code' => '2041',
            'category2_name' => '雑貨', 'opening_date' => '2026-09-30', 'opening_source' => 'manual', 'opening_amount' => 1000, 'system_amount' => 1000]);
        WmsSundryInventoryCountItem::create(['sundry_inventory_count_id' => $this->count->id, 'item_id' => 4,
            'cost_price' => 20, 'system_quantity' => 7, 'counted_quantity' => 3, 'counted_amount' => 60]);
        $this->protectedRows = $this->protectedSnapshot();
        (require database_path('migrations/2026_10_18_000000_create_sundry_valuation_tables.php'))->up();
        $this->service = new SundryQuantityValuationService;
    }

    protected function tearDown(): void
    {
        DB::purge('sakemaru');
        parent::tearDown();
    }

    public function test_new_count_prepares_all_selected_unmanaged_categories_without_manual_actions(): void
    {
        $service = $this->creationService();
        $this->amount->update(['counted_amount' => 850]);
        $existing = $this->amount->fresh()->getAttributes();
        $protected = $this->protectedSnapshot();
        $this->db()->table('item_categories')->insert([
            ['id' => 10, 'client_id' => 6, 'depth' => 1, 'code' => 1005, 'name' => 'Other major', 'cost_rate' => 90],
            ['id' => 11, 'client_id' => 6, 'depth' => 2, 'code' => 2051, 'name' => 'Other middle', 'cost_rate' => 90],
        ]);
        foreach ([5 => [6, 10, 11], 6 => [7, 1, 2], 7 => [6, 20, 21]] as $id => [$client, $major, $middle]) {
            $this->db()->table('items')->insert(['id' => $id, 'client_id' => $client, 'code' => '40'.$id, 'name' => 'Test item',
                'is_managed_stock' => false, 'item_category1_id' => $major, 'item_category2_id' => $middle,
                'item_category3_id' => 3]);
            $this->price($id, 100, '2026-09-01');
        }
        $count = $service->create(['amount_category_ids' => [1, 10]] + $this->creationData());

        $rows = WmsSundryValuationItem::where('sundry_inventory_count_id', $count->id)->orderBy('item_id')->get();
        $this->assertSame([1, 2, 5], $rows->pluck('item_id')->all());
        $this->assertTrue($rows->every(fn ($row) => $row->quantity === null && $row->amount === null));
        $this->assertTrue($rows->every(fn ($row) => $row->valuation_unit_price === '80.000000'));
        $this->assertSame(2, $count->amounts()->count());
        $this->assertSame(0, $count->amounts()->whereNotNull('counted_amount')->count());
        $this->assertSame([4], $count->items()->pluck('item_id')->all());
        $this->assertSame($existing, $this->amount->fresh()->getAttributes());
        foreach ($protected as $table => $before) {
            $after = $this->db()->table($table)->whereIn('id', array_column($before, 'id'))->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
            $this->assertSame($before, $after);
        }
        $this->assertSame(0, $this->service->prepareUnenteredCategories($count)['prepared_items']);
        $page = new \App\Filament\Resources\WmsSundryInventoryCount\Pages\ViewWmsSundryInventoryCount;
        $page->mount($count);
        $this->assertSame('valuation', $page->listTab);
        $this->assertSame([1, 2, 5], $page->valuationRows()->pluck('item_id')->all());
    }

    public function test_new_count_preserves_manually_prepared_rows_and_inputs(): void
    {
        $service = $this->creationService();
        $this->service->prepareUnenteredCategories($this->count);
        $this->save(1, 2);

        foreach ([false, true] as $complete) {
            if ($complete) {
                $this->save(2, 3);
            }
            $header = $this->count->fresh()->getAttributes();
            $amount = $this->amount->fresh()->getAttributes();
            $rows = WmsSundryValuationItem::where('sundry_inventory_count_id', $this->count->id)
                ->orderBy('id')->get()->map->getAttributes()->all();

            $service->create($this->creationData());
            $this->assertSame(0, $this->service->prepareUnenteredCategories($this->count)['prepared_items']);

            $this->assertSame($header, $this->count->fresh()->getAttributes());
            $this->assertSame($amount, $this->amount->fresh()->getAttributes());
            $this->assertSame($rows, WmsSundryValuationItem::where('sundry_inventory_count_id', $this->count->id)
                ->orderBy('id')->get()->map->getAttributes()->all());
        }
    }

    public function test_new_count_quantity_input_updates_category_and_summary_once(): void
    {
        $service = $this->creationService();
        $count = $service->create($this->creationData());
        $rows = WmsSundryValuationItem::where('sundry_inventory_count_id', $count->id)->get()->keyBy('item_id');
        $this->service->save($count, [
            $rows[1]->id => ['quantity' => 2, 'revision' => 0],
            $rows[2]->id => ['quantity' => 3, 'revision' => 0],
        ], 'test');
        $service->saveChanges($count, [$count->items()->sole()->id => 3], [], 'test');

        $this->assertSame(400.0, $count->amounts()->sole()->counted_amount);
        $this->assertSame(-600.0, $count->amounts()->sole()->difference_amount);
        $summary = $service->summary($count);
        $this->assertSame(400.0, $summary['totals']['unmanaged']['counted']);
        $this->assertSame(460.0, $summary['totals']['total']['counted']);
        $this->assertSame(-680.0, $summary['totals']['total']['difference']);
        $this->assertNull($this->amount->fresh()->counted_amount);
    }

    public function test_new_count_prepares_middle_category_only_and_keeps_missing_price_unvalued(): void
    {
        $service = $this->creationService();
        $this->db()->table('item_prices')->where('item_id', 2)->update(['start_date' => '2026-10-07']);
        $count = $service->create(['amount_category_ids' => []] + $this->creationData());
        $rows = WmsSundryValuationItem::where('sundry_inventory_count_id', $count->id)->get()->keyBy('item_id');
        $this->assertCount(2, $rows);
        $this->assertNull($rows[2]->valuation_unit_price);
        $this->assertNull($rows[2]->quantity);
        $this->assertNull($rows[2]->amount);
        $this->assertRejected(fn () => $this->service->save($count, [$rows[2]->id => ['quantity' => 1, 'revision' => 0]], 'test'));
    }

    public function test_new_count_rolls_back_header_and_all_details_when_quantity_preparation_fails(): void
    {
        $service = $this->creationService();
        $protected = $this->protectedSnapshot();
        WmsSundryValuationItem::creating(function ($row): void {
            if ($row->item_id === 2) {
                throw new \RuntimeException('Preparation failed');
            }
        });
        try {
            $service->create($this->creationData());
            $this->fail('An incomplete count was created.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Preparation failed', $e->getMessage());
        } finally {
            WmsSundryValuationItem::flushEventListeners();
        }
        $this->assertSame(1, WmsSundryInventoryCount::count());
        $this->assertSame(1, WmsSundryInventoryCountAmount::count());
        $this->assertSame(0, WmsSundryValuationItem::count());
        $this->assertSame($protected, $this->protectedSnapshot());
    }

    public function test_new_managed_only_count_does_not_create_nonmanaged_rows(): void
    {
        $service = $this->creationService();
        $this->db()->table('items')->update(['is_managed_stock' => true]);
        $count = $service->create($this->creationData());
        $this->assertSame([4], $count->items()->pluck('item_id')->all());
        $this->assertSame(0, $count->amounts()->count());
        $this->assertSame(0, WmsSundryValuationItem::count());
    }

    public function test_new_weighed_count_does_not_prepare_nonmanaged_quantities(): void
    {
        $service = $this->creationService();
        $this->db()->table('items')->where('id', 4)->update(['code' => '129002']);
        $count = $service->create(['kind' => 'weighed', 'sales_from_date' => '2026-09-01'] + $this->creationData());
        $this->assertTrue($count->isWeighed());
        $this->assertSame([4], $count->items()->pluck('item_id')->all());
        $this->assertSame(0, $count->amounts()->count());
        $this->assertSame(0, WmsSundryValuationItem::count());
    }

    public function test_new_weighed_count_excludes_active_owned_parent_without_changing_existing_inputs(): void
    {
        $service = $this->creationService();
        $this->db()->table('items')->where('id', 4)->update(['code' => '129002']);
        $this->db()->table('items')->where('id', 3)->update(['code' => '129011', 'is_managed_stock' => true]);
        $this->db()->table('real_stocks')->insert(['client_id' => 6, 'warehouse_id' => 10, 'item_id' => 3, 'quantity' => 0]);
        $before = $this->count->items()->get()->toJson();
        $count = $service->create(['kind' => 'weighed', 'sales_from_date' => '2026-09-01'] + $this->creationData());
        $this->assertSame([4], $count->items()->pluck('item_id')->all());
        $this->assertRejected(fn () => $service->addItemByCode($count, '129011'));
        $service->refreshTheory($count, '2026-10-06');
        $this->assertSame([4], $count->items()->pluck('item_id')->all());
        $this->assertSame($before, $this->count->items()->get()->toJson());

        $this->db()->table('item_sets')->where('id', 1)->update(['is_active' => false]);
        $other = $service->create(['kind' => 'weighed', 'sales_from_date' => '2026-09-01'] + $this->creationData());
        $this->assertSame([3, 4], $other->items()->orderBy('item_id')->pluck('item_id')->all());
    }

    private function creationData(): array
    {
        return ['warehouse_id' => 10, 'count_date' => '2026-10-06', 'category_ids' => [2], 'amount_category_ids' => [1]];
    }

    private function creationService(): SundryInventoryCountService
    {
        $schema = Schema::connection('sakemaru');
        $schema->create('warehouses', function (Blueprint $t): void {
            $t->id();
            $t->integer('client_id');
            $t->string('code');
            $t->string('name');
            $t->boolean('is_active')->default(true);
        });
        $this->db()->table('warehouses')->insert(['id' => 10, 'client_id' => 6, 'code' => '10', 'name' => 'Test warehouse']);
        $schema->table('item_categories', function (Blueprint $t): void {
            $t->integer('client_id')->default(6);
            $t->integer('depth')->default(3);
            $t->integer('code')->default(0);
            $t->string('name')->default('Test category');
        });
        $this->db()->table('item_categories')->where('id', 1)->update(['depth' => 1, 'code' => 1004]);
        $this->db()->table('item_categories')->where('id', 2)->update(['depth' => 2, 'code' => 2041]);
        $schema->table('items', fn (Blueprint $t) => $t->string('type')->default('NOT_ALCOHOL'));
        $schema->table('real_stocks', function (Blueprint $t): void {
            $t->integer('client_id')->default(6);
            $t->integer('warehouse_id')->default(10);
            $t->integer('item_id')->default(4);
        });

        return new SundryInventoryCountService(new class extends SundryInventoryTheoryCalculator
        {
            public function managedBalances(int $clientId, int $warehouseId, string $endDate): array
            {
                return [4 => 7.0];
            }

            public function costPrices(int $clientId, array $itemIds, string $date): array
            {
                return array_fill_keys($itemIds, 20.0);
            }

            public function unmanagedLegacyOpening(string $warehouseCode, string $category2Code, string $onOrBeforeDate): ?array
            {
                return ['date' => '2026-09-30', 'amount' => 1000.0];
            }

            public function unmanagedOpeningFromStockReport(int $clientId, int $warehouseId, int $category1Id, string $onOrBeforeDate): ?array
            {
                return null;
            }

            public function unmanagedFlows(int $clientId, int $warehouseId, array $categoryIds, string $fromDate, string $endDate, int $depth = 2): array
            {
                return [];
            }

            public function periodSalesQuantities(int $clientId, int $warehouseId, array $itemIds, string $fromDate, string $endDate): array
            {
                return array_fill_keys($itemIds, 0.0);
            }
        });
    }

    public function test_preparation_preserves_existing_inputs_and_selects_only_unmanaged_non_owned_items(): void
    {
        $header = $this->count->fresh()->getAttributes();
        $amount = $this->amount->fresh()->getAttributes();
        $this->price(1, 200, '2026-09-01'); // Same date, higher ID wins.
        $this->price(1, 999, '2026-10-07'); // Future price must not leak in.
        $this->assertSame(2, $this->service->prepare($this->count, $this->amount->id));
        $this->assertSame([1, 2], WmsSundryValuationItem::orderBy('item_id')->pluck('item_id')->all());
        $this->assertSame('160.000000', WmsSundryValuationItem::where('item_id', 1)->value('valuation_unit_price'));
        $this->assertSame($header, $this->count->fresh()->getAttributes());
        $this->assertSame($amount, $this->amount->fresh()->getAttributes());
        $this->assertSame($this->protectedRows, $this->protectedSnapshot());
        $this->assertSame(0, $this->service->prepare($this->count, $this->amount->id));
    }

    public function test_partial_valuation_is_incomplete_and_complete_category_is_aggregated_once(): void
    {
        $this->prepare();
        $this->save(1, '２.５');
        $this->assertNull($this->amount->fresh()->counted_amount);
        $this->assertRejected(fn () => $this->service->assertComplete($this->count));
        $this->save(2, 0);
        $this->assertSame(200.0, $this->amount->fresh()->counted_amount);
        $this->assertSame(-800.0, $this->amount->fresh()->difference_amount);
        $summary = (new SundryInventoryCountService)->summary($this->count);
        $this->assertEquals(200, $summary['totals']['unmanaged']['counted']);
        $this->assertRejected(fn () => (new SundryInventoryCountService)->saveChanges($this->count, [], [$this->amount->id => ['counted_amount' => 9]], 'test'));
        $this->save(2, null);
        $this->assertNull($this->amount->fresh()->difference_amount);
        $this->assertSame($this->protectedRows, $this->protectedSnapshot());
    }

    public function test_input_confirmation_preserves_incomplete_nonmanaged_inputs_without_applying_stock(): void
    {
        $this->prepare();
        $this->save(1, 2);
        $before = [$this->count->items()->get()->toJson(), $this->count->amounts()->get()->toJson(),
            WmsSundryValuationItem::orderBy('id')->get()->toJson()];
        (new SundryInventoryCountService)->confirm($this->count, null);
        $this->assertSame('confirmed', $this->count->fresh()->status);
        $this->assertSame($before, [$this->count->items()->get()->toJson(), $this->count->amounts()->get()->toJson(),
            WmsSundryValuationItem::orderBy('id')->get()->toJson()]);
        $this->assertRejected(fn () => $this->service->assertComplete($this->count));
        $this->assertSame($this->protectedRows, $this->protectedSnapshot());
    }

    public function test_stale_revision_and_invalid_input_are_atomic(): void
    {
        $this->prepare();
        $row = WmsSundryValuationItem::where('item_id', 1)->firstOrFail();
        $this->save(1, 3);
        $this->assertRejected(fn () => $this->service->save($this->count, [$row->id => ['quantity' => 99, 'revision' => 0]], 'other'));
        foreach (['-1', '1.1234', '1e3', 'x', ['bad']] as $bad) {
            $this->assertRejected(fn () => $this->save(1, $bad));
        }
        $this->assertSame('3.000', $row->fresh()->quantity);
        $other = WmsSundryValuationItem::where('item_id', 2)->firstOrFail();
        $this->assertRejected(fn () => $this->service->save($this->count, [$other->id => ['quantity' => 10, 'revision' => 0], $row->id => ['quantity' => 5, 'revision' => 0]], 'other'));
        $this->assertNull($other->fresh()->quantity);
    }

    public function test_existing_manual_amount_cannot_be_replaced(): void
    {
        $this->amount->update(['counted_amount' => 850]);
        $this->assertRejected(fn () => $this->prepare());
        $this->assertSame(850.0, $this->amount->fresh()->counted_amount);
        $this->assertSame(0, WmsSundryValuationItem::count());
    }

    public function test_bulk_preparation_preserves_manual_amounts_and_does_not_treat_empty_categories_as_zero(): void
    {
        $manual = $this->amount->replicate();
        $manual->fill(['category2_id' => 99, 'category2_code' => '2047', 'counted_amount' => 850])->save();
        $empty = $this->amount->replicate();
        $empty->fill(['category2_id' => 98, 'category2_code' => '2052'])->save();
        $before = $this->count->amounts()->orderBy('id')->get()->map->getAttributes()->all();
        $result = $this->service->prepareUnenteredCategories($this->count);
        $this->assertSame(['prepared_items' => 2, 'preserved_categories' => 1, 'empty_categories' => 1], $result);
        $this->assertSame($before, $this->count->amounts()->orderBy('id')->get()->map->getAttributes()->all());
        $this->assertSame([1, 2], WmsSundryValuationItem::orderBy('item_id')->pluck('item_id')->all());
        $this->assertSame(0, WmsSundryValuationItem::whereNotNull('quantity')->count());
        $this->assertSame(0, $this->service->prepareUnenteredCategories($this->count)['prepared_items']);
        $this->assertSame($this->protectedRows, $this->protectedSnapshot());
    }

    public function test_bulk_refresh_keeps_entered_quantity_price_and_category_total(): void
    {
        $this->service->prepareUnenteredCategories($this->count);
        $this->save(1, 2);
        $entered = WmsSundryValuationItem::where('item_id', 1)->firstOrFail()->getAttributes();
        $this->price(1, 900, '2026-10-01');
        $this->price(2, 200, '2026-10-01');
        $this->assertSame(1, $this->service->prepareUnenteredCategories($this->count, true)['prepared_items']);
        $this->assertSame($entered, WmsSundryValuationItem::where('item_id', 1)->firstOrFail()->getAttributes());
        $this->save(2, 3);
        $this->assertSame(640.0, $this->amount->fresh()->counted_amount);
        $this->assertSame(0, $this->service->prepareUnenteredCategories($this->count, true)['prepared_items']);
        $this->assertSame(640.0, $this->amount->fresh()->counted_amount);
    }

    public function test_bulk_preparation_rejects_confirmed_or_weighed_counts(): void
    {
        $this->count->update(['status' => 'confirmed']);
        $this->assertRejected(fn () => $this->service->prepareUnenteredCategories($this->count));
        $this->count->update(['status' => 'counting', 'kind' => 'weighed']);
        $this->assertRejected(fn () => $this->service->prepareUnenteredCategories($this->count));
        $this->assertSame(0, WmsSundryValuationItem::count());
        $this->assertSame($this->protectedRows, $this->protectedSnapshot());
    }

    public function test_missing_price_is_not_assumed_zero_and_only_unentered_prices_can_be_refreshed(): void
    {
        $this->db()->table('item_prices')->where('item_id', 2)->update(['start_date' => '2026-10-07']);
        $this->prepare();
        $this->assertRejected(fn () => $this->save(2, 1));
        $this->save(1, 1);
        $this->price(1, 500, '2026-10-01');
        $this->price(2, 300, '2026-10-01');
        $this->service->prepare($this->count, $this->amount->id, true);
        $this->assertSame('80.000000', WmsSundryValuationItem::where('item_id', 1)->first()->valuation_unit_price);
        $this->save(2, 1);
        $this->assertSame(320.0, $this->amount->fresh()->counted_amount);
        $this->assertSame(100.0, SundryQuantityValuationService::netSalePrice(110, 'PRE_TAX_PERCENT_10'));
        $this->assertSame(100.0, SundryQuantityValuationService::netSalePrice(108, 'PRE_TAX_PERCENT_8'));
        $this->assertNull(SundryQuantityValuationService::netSalePrice(100, 'UNKNOWN'));
    }

    public function test_applies_amount_only_once_and_freezes_source_without_stock_writes(): void
    {
        $this->complete();
        $this->db()->table('stats_monthly_stock_amount_reports')->insert(['client_id' => 6, 'warehouse_id' => 10, 'target_year_month' => '2026-10']);
        $service = new SundryAmountAdjustmentService;
        $this->assertSame(1, $service->apply($this->count, '2026-10-06', '帳簿確認2026-10-06', null));
        $this->assertSame(0, $service->apply($this->count, '2026-10-06', '帳簿確認2026-10-06', null));
        $journal = $this->db()->table(SundryAmountAdjustmentService::TABLE)->sole();
        $this->assertEquals(-760, $journal->adjustment_amount);
        $this->assertCount(2, json_decode($journal->source_snapshot, true)['items']);
        $this->assertSame($this->protectedRows, $this->protectedSnapshot());
        $this->assertRejected(fn () => (new SundryInventoryCountService)->reopen($this->count));
        $this->assertRejected(fn () => $this->save(1, 999));
    }

    public function test_backdating_later_reports_running_generation_and_flag_block_posting(): void
    {
        $this->complete();
        $service = new SundryAmountAdjustmentService;
        $this->assertRejected(fn () => $service->apply($this->count, '2026-09-30', 'September approval', null));
        $this->db()->table('stats_monthly_stock_amount_reports')->insert(['client_id' => 6, 'warehouse_id' => 10, 'target_year_month' => '2026-11']);
        $this->assertRejected(fn () => $service->apply($this->count, '2026-10-06', 'October approval', null));
        $this->db()->table('stats_monthly_stock_amount_reports')->where('warehouse_id', 10)->update(['target_year_month' => '2026-09']);
        $this->db()->table('stock_report_generation_runs')->insert(['client_id' => 6, 'status' => 'queued']);
        $this->assertRejected(fn () => $service->apply($this->count, '2026-10-06', 'October approval', null));
        config(['wms_sundry_inventory.amount_posting_enabled' => false]);
        $this->assertRejected(fn () => $service->apply($this->count, '2026-10-06', 'October approval', null));
        $this->assertSame(0, $this->db()->table(SundryAmountAdjustmentService::TABLE)->count());
    }

    public function test_weighed_counts_cannot_generate_nonmanaged_rows(): void
    {
        $this->count->update(['kind' => 'weighed']);
        $this->assertRejected(fn () => $this->prepare());
        $this->assertSame(0, WmsSundryValuationItem::count());
    }

    public function test_next_count_uses_appraisal_as_opening_only_after_amount_application(): void
    {
        $this->complete();
        $next = $this->count->replicate();
        $next->fill(['count_no' => 'NEXT', 'count_date' => '2026-10-07', 'theory_end_date' => '2026-10-07', 'status' => 'counting'])->save();
        $row = new WmsSundryInventoryCountAmount(['category2_id' => 2, 'category2_code' => '2041']);
        $calculator = new class extends SundryInventoryTheoryCalculator
        {
            public function unmanagedLegacyOpening(string $warehouseCode, string $category2Code, string $onOrBeforeDate): ?array
            {
                return ['date' => '2026-09-30', 'amount' => 1000.0];
            }
        };
        $method = new \ReflectionMethod(SundryInventoryCountService::class, 'applyOpening');
        $service = new SundryInventoryCountService($calculator);
        $method->invoke($service, $next, $row);
        $this->assertSame(1000.0, $row->opening_amount);
        (new SundryAmountAdjustmentService)->apply($this->count, '2026-10-06', '帳簿確認2026-10-06', null);
        $method->invoke($service, $next, $row);
        $this->assertSame(240.0, $row->opening_amount);
        $this->assertSame('2026-10-06', $row->opening_date->toDateString());
    }

    private function complete(): void
    {
        $this->prepare();
        $this->save(1, 1);
        $this->save(2, 2);
        (new SundryInventoryCountService)->confirm($this->count, null);
    }

    private function prepare(): void
    {
        $this->service->prepare($this->count, $this->amount->id);
    }

    private function save(int $item, mixed $quantity): void
    {
        $row = WmsSundryValuationItem::where('item_id', $item)->firstOrFail();
        $this->service->save($this->count, [$row->id => ['quantity' => $quantity, 'revision' => $row->revision]], 'test');
    }

    private function price(int $item, int $price, string $date): void
    {
        $this->db()->table('item_prices')->insert(['client_id' => 6, 'item_id' => $item, 'start_date' => $date,
            'is_active' => 1, 'sale_unit_price' => $price, 'type' => 'POST_TAX_PERCENT_10']);
    }

    private function protectedSnapshot(): array
    {
        $data = [];
        foreach (['real_stocks', 'real_stock_lots', 'wms_inventory_counts', 'wms_inventory_count_items', 'wms_sundry_inventory_count_items'] as $name) {
            $data[$name] = $this->db()->table($name)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        }

        return $data;
    }

    private function db(): \Illuminate\Database\Connection
    {
        return DB::connection('sakemaru');
    }

    private function assertRejected(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Unsafe operation was accepted.');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }
}
