<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Filament\Resources\ItemContractors\ItemContractorResource;
use App\Filament\Resources\ItemContractors\Pages\CreateItemContractor;
use App\Filament\Resources\ItemContractors\Pages\EditItemContractor;
use App\Filament\Resources\ItemContractors\Tables\ItemContractorsTable;
use App\Models\Sakemaru\ItemContractor;
use App\Services\AutoReplenishment\AutoOrderDisplayStatus;
use App\Services\Export\ExportService;
use App\Services\ItemContractorReorderPoint;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class ItemContractorAutoOrderStatusTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.client_id' => 6, 'replenishment.enabled' => true,
            'database.connections.sakemaru' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('sakemaru');
        $db = DB::connection('sakemaru');
        $db->statement('CREATE TABLE item_contractors (id INTEGER PRIMARY KEY, client_id INTEGER, warehouse_id INTEGER, item_id INTEGER, contractor_id INTEGER, supplier_id INTEGER, safety_stock INTEGER DEFAULT 10, max_stock INTEGER DEFAULT 20, min_stock INTEGER DEFAULT 0, purchase_unit INTEGER DEFAULT 1, auto_order_quantity INTEGER DEFAULT 0, is_auto_order INTEGER DEFAULT 0, use_safety_stock_auto_update INTEGER DEFAULT 0, created_at TEXT, updated_at TEXT)');
        $db->statement('CREATE TABLE warehouses (id INTEGER PRIMARY KEY, client_id INTEGER, is_active INTEGER, is_virtual INTEGER)');
        $db->statement('CREATE TABLE wms_auto_order_controls (client_id INTEGER PRIMARY KEY, enabled INTEGER, reset_state TEXT, release_key TEXT)');
        $db->statement('CREATE TABLE wms_auto_order_events (id INTEGER PRIMARY KEY, client_id INTEGER, event TEXT, payload TEXT)');
        $db->statement('CREATE TABLE wms_warehouse_auto_order_settings (warehouse_id INTEGER PRIMARY KEY, is_auto_order_enabled INTEGER)');
        $db->statement('CREATE TABLE wms_item_reorder_policies (client_id INTEGER, warehouse_id INTEGER, item_id INTEGER, contractor_id INTEGER, supplier_id INTEGER, is_enabled INTEGER, is_configured INTEGER, UNIQUE(client_id, warehouse_id, item_id))');
        $db->table('warehouses')->insert(['id' => 2, 'client_id' => 6, 'is_active' => 1, 'is_virtual' => 0]);
        $db->table('wms_auto_order_controls')->insert(['client_id' => 6, 'enabled' => 1, 'reset_state' => 'COMPLETE', 'release_key' => 'test']);
        $db->table('wms_auto_order_events')->insert(['client_id' => 6, 'event' => 'RESET_SCOPE_FIXED',
            'payload' => json_encode(['release_key' => 'test', 'preserved_warehouse_ids' => []])]);
        $db->table('wms_warehouse_auto_order_settings')->insert(['warehouse_id' => 2, 'is_auto_order_enabled' => 1]);
        $db->table('item_contractors')->insert(array_map(fn ($id) => [
            'id' => $id, 'client_id' => 6, 'warehouse_id' => 2, 'item_id' => $id,
            'contractor_id' => 10, 'supplier_id' => 20, 'is_auto_order' => $id === 2 ? 1 : 0,
        ], range(1, 100)));
        foreach ([1 => [], 3 => ['is_enabled' => 0], 4 => ['is_configured' => 0],
            5 => ['contractor_id' => 99], 6 => ['supplier_id' => 21], 7 => ['supplier_id' => null]] as $id => $overrides) {
            $db->table('wms_item_reorder_policies')->insert(array_replace([
                'client_id' => 6, 'warehouse_id' => 2, 'item_id' => $id, 'contractor_id' => 10,
                'supplier_id' => 20, 'is_enabled' => 1, 'is_configured' => 1,
            ], $overrides));
        }
        foreach ([101 => ['warehouse_id' => 3], 102 => ['client_id' => 7],
            103 => ['contractor_id' => 99], 104 => ['supplier_id' => 21]] as $id => $overrides) {
            $db->table('item_contractors')->insert(array_replace([
                'id' => $id, 'client_id' => 6, 'warehouse_id' => 2, 'item_id' => 1,
                'contractor_id' => 10, 'supplier_id' => 20, 'is_auto_order' => 1,
            ], $overrides));
        }
    }

    public function test_status_matches_the_enabled_route_and_ignores_the_legacy_switch(): void
    {
        $rows = ItemContractorResource::getEloquentQuery()->orderBy('id')->get();
        self::assertCount(104, $rows);
        self::assertSame([1, 7], $rows->filter(fn ($row) => (bool) $row->is_auto_order_active)->values()->modelKeys());
        self::assertFalse($rows->first()->is_auto_order);
        self::assertTrue($rows->find(2)->is_auto_order);
        self::assertFalse((bool) $rows->find(2)->is_auto_order_active);
        self::assertSame([], $rows->first()->getDirty());
    }

    public function test_one_hundred_rows_and_filtering_do_not_issue_per_row_queries(): void
    {
        $db = DB::connection('sakemaru');
        $livewire = Mockery::mock(HasTable::class);
        $livewire->shouldReceive('getTableRecordKey')->andReturnUsing(fn ($record): string => (string) $record->getKey());
        $table = ItemContractorsTable::configure(Table::make($livewire));
        $column = $table->getColumn('is_auto_order_active');
        $point = $table->getColumn('safety_stock');
        $db->enableQueryLog();
        $rows = ItemContractorResource::getEloquentQuery()->where('warehouse_id', 2)->limit(100)->get();
        foreach ($rows as $row) {
            $column->record($row);
            self::assertSame($row->is_auto_order_active ? '自動発注中' : '自動発注OFF', $column->getState());
            $point->record($row);
            self::assertSame((bool) $row->is_auto_order_active, $point->isDisabled());
            self::assertSame($row->is_auto_order_active ? '自動発注中' : 10, $point->getState());
        }
        self::assertCount(1, $db->getQueryLog());
        self::assertStringStartsWith('select ', strtolower($db->getQueryLog()[0]['query']));

        $filter = $table->getFilter('is_auto_order_active');
        self::assertSame([1, 7], $filter->apply(ItemContractorResource::getEloquentQuery(), ['value' => true])->orderBy('id')->get()->modelKeys());
        self::assertSame(102, $filter->apply(ItemContractorResource::getEloquentQuery(), ['value' => false])->count());
        self::assertSame(104, $filter->apply(ItemContractorResource::getEloquentQuery(), ['value' => null])->count());
    }

    public function test_stopping_automatic_ordering_immediately_updates_display_and_filters(): void
    {
        $status = app(AutoOrderDisplayStatus::class);
        DB::connection('sakemaru')->table('wms_warehouse_auto_order_settings')->update(['is_auto_order_enabled' => 0]);
        self::assertSame(0, $status->filterItemContractors(ItemContractorResource::getEloquentQuery(), true)->count());
        self::assertSame(104, $status->filterItemContractors(ItemContractorResource::getEloquentQuery(), false)->count());
        self::assertTrue(ItemContractorResource::getEloquentQuery()->get()->every(fn ($row) => ! $row->is_auto_order_active));

        config(['replenishment.enabled' => false]);
        $query = ItemContractorResource::getEloquentQuery();
        self::assertStringNotContainsString('wms_item_reorder_policies', $query->toSql());
        self::assertSame(0, $status->filterItemContractors(clone $query, true)->count());
        self::assertSame(104, $status->filterItemContractors(clone $query, false)->count());
        self::assertTrue($query->get()->every(fn ($row) => ! $row->is_auto_order_active));
    }

    public function test_background_export_preserves_display_status_filters_and_binding_order(): void
    {
        $export = new ExportService;
        $query = app(AutoOrderDisplayStatus::class)->filterItemContractors(
            ItemContractorResource::getEloquentQuery()->where('warehouse_id', 2)->where('id', '>', 0)->orderBy('id'), true);
        $constraints = unserialize(serialize($export->extractQueryConstraints($query)));
        $rebuilt = $export->rebuildQuery(ItemContractor::class, $constraints);
        self::assertSame($query->toSql(), $rebuilt->toSql());
        self::assertSame($query->getBindings(), $rebuilt->getBindings());
        $rows = $rebuilt->get();
        self::assertSame([1, 7], $rows->modelKeys());
        self::assertTrue($rows->every(fn ($row) => (bool) $row->is_auto_order_active));

        $legacyQuery = ItemContractor::query()->where('id', 2)->orderBy('id');
        $legacyConstraints = $export->extractQueryConstraints($legacyQuery);
        unset($legacyConstraints['columns'], $legacyConstraints['binding_groups']);
        self::assertSame([2], $export->rebuildQuery(ItemContractor::class, $legacyConstraints)->get()->modelKeys());
    }

    public function test_editing_a_manual_reorder_point_changes_only_the_legacy_quantity(): void
    {
        $db = DB::connection('sakemaru');
        $before = $db->table('wms_item_reorder_policies')->get()->toArray();
        $record = ItemContractorResource::getEloquentQuery()->findOrFail(2);
        $page = new EditItemContractor;
        $data = (new \ReflectionMethod($page, 'mutateFormDataBeforeSave'))->invoke($page, [
            'safety_stock' => 37, 'max_stock' => 999, 'auto_order_quantity' => 999,
        ]);
        $result = (new \ReflectionMethod($page, 'handleRecordUpdate'))->invoke($page, $record, $data);
        self::assertSame(37, $result->fresh()->safety_stock);
        self::assertSame(20, $result->fresh()->max_stock);
        self::assertSame(0, $result->fresh()->auto_order_quantity);
        self::assertEquals($before, $db->table('wms_item_reorder_policies')->get()->toArray());

        app(ItemContractorReorderPoint::class)->update($result, ['safety_stock' => '']);
        self::assertSame(0, $result->fresh()->safety_stock);
    }

    public function test_inline_save_rejects_a_product_enabled_after_the_list_was_loaded(): void
    {
        $db = DB::connection('sakemaru');
        $stale = ItemContractorResource::getEloquentQuery()->findOrFail(2);
        self::assertFalse((bool) $stale->is_auto_order_active);
        $db->table('wms_item_reorder_policies')->insert(['client_id' => 6, 'warehouse_id' => 2, 'item_id' => 2,
            'contractor_id' => 10, 'supplier_id' => 20, 'is_enabled' => 1, 'is_configured' => 1]);
        $column = ItemContractorsTable::configure(Table::make(Mockery::mock(HasTable::class)))->getColumn('safety_stock');
        $column->record($stale);
        try {
            $column->updateState(50);
            self::fail('A stale manual row must not update an active automatic product.');
        } catch (ValidationException $e) {
            self::assertStringContainsString('自動発注中', $e->errors()['safety_stock'][0]);
        }
        self::assertSame(10, $stale->fresh()->safety_stock);
    }

    public function test_creating_a_manual_record_preserves_the_entered_reorder_point_after_initialization_defaults(): void
    {
        $page = new CreateItemContractor;
        $data = (new \ReflectionMethod($page, 'mutateFormDataBeforeCreate'))->invoke($page, [
            'client_id' => 6, 'warehouse_id' => 2, 'item_id' => 999, 'contractor_id' => 10, 'supplier_id' => 20,
            'safety_stock' => 29,
        ]);
        $record = (new \ReflectionMethod($page, 'handleRecordCreation'))->invoke($page, $data);
        self::assertSame(29, $record->fresh()->safety_stock);
        self::assertFalse($record->fresh()->is_auto_order);
        self::assertFalse($record->fresh()->use_safety_stock_auto_update);
    }

    public function test_invalid_reorder_points_are_rejected_before_any_update(): void
    {
        $record = ItemContractor::findOrFail(2);
        foreach ([-1, 1.5, 'abc', 4294967296] as $value) {
            try {
                app(ItemContractorReorderPoint::class)->update($record, ['safety_stock' => $value]);
                self::fail('Invalid reorder point must not be saved.');
            } catch (ValidationException $e) {
                self::assertArrayHasKey('safety_stock', $e->errors());
            }
        }
        self::assertSame(10, $record->fresh()->safety_stock);
    }

    public function test_creation_cannot_bypass_the_automatic_product_reorder_point_guard(): void
    {
        $db = DB::connection('sakemaru');
        $count = $db->table('item_contractors')->count();
        $page = new CreateItemContractor;
        try {
            (new \ReflectionMethod($page, 'handleRecordCreation'))->invoke($page, [
                'client_id' => 6, 'warehouse_id' => 2, 'item_id' => 1, 'contractor_id' => 10, 'supplier_id' => 20,
                'safety_stock' => 50,
            ]);
            self::fail('A new duplicate row cannot bypass the automatic product guard.');
        } catch (ValidationException $e) {
            self::assertArrayHasKey('safety_stock', $e->errors());
        }
        self::assertSame($count, $db->table('item_contractors')->count());
        self::assertSame(10, ItemContractor::findOrFail(1)->safety_stock);
    }
}
