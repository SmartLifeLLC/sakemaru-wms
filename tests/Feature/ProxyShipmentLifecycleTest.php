<?php

namespace Tests\Feature;

use App\Models\WmsShortageAllocation;
use App\Services\QuantityUpdate\AllocationSyncService;
use App\Services\Shortage\ProxyShipmentLifecycleService;
use App\Services\Shortage\ProxyShipmentPickingService;
use App\Services\Shortage\ProxyShipmentPrintRequestService;
use App\Services\Shortage\ProxyShipmentTransferResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\ProxyShipmentDatabase;
use Tests\TestCase;

class ProxyShipmentLifecycleTest extends TestCase
{
    use ProxyShipmentDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createProxyShipmentDatabase('sakemaru');
    }

    public function test_new_page_renders_completed_rows_and_keeps_cancellation_filter_reversible(): void
    {
        $this->makePrintable();
        $user = new \App\Models\User;
        $user->setRawAttributes(['id' => 9, 'client_id' => 6, 'default_warehouse_id' => 1, 'wms_selected_warehouse_id' => 2]);
        $this->actingAs($user);
        $this->mock(\Sakemaru\Auth\Services\PermissionService::class)->shouldReceive('check')->andReturnTrue();
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
        $component = \Livewire\Livewire::test(\App\Filament\Resources\WmsShortageAllocations\Pages\ListNewWmsShortageAllocations::class)
            ->assertSuccessful()->assertSee('新横持ち出荷依頼')->assertSee('2000');
        $this->assertTrue($component->instance()->getTable()->isSelectionEnabled());
        $component->set('selectedTableRecords', [1])->call('prepareTransferPrint', (string) \Illuminate\Support\Str::uuid());
        $this->assertSame(1, DB::table('wms_proxy_shipment_print_request_items')->count());
        DB::table('wms_shortage_allocations')->where('id', 1)->update(['status' => 'CANCELLED', 'is_finished' => false]);
        $component->set('tableFilters.cancellation.value', 'only')->assertSee('取消（無効）');
    }

    public function test_completed_transfer_button_and_column_use_the_series_menu_origin_and_open_a_new_tab(): void
    {
        config(['app.url' => 'https://wms.lw-hana.net', 'app.core_url' => 'https://wrong-environment.invalid']);
        $this->makePrintable();
        $page = $this->newPage();
        $record = $page->instance()->getTableRecords()->first();
        $action = $page->instance()->getTable()->getAction('viewTransfer')->record($record);
        $this->assertTrue($action->isVisible());
        $this->assertTrue($action->shouldOpenUrlInNewTab());
        $this->assertSame('https://lw-hana.net/stocks/inventory/transfer/form/50', $action->getUrl());
        $this->assertSame($action->getUrl(), $page->instance()->getTable()->getColumn('transfer_status')->record($record)->getUrl());
    }

    public function test_transfer_button_is_hidden_for_unfinished_or_unresolved_completed_requests(): void
    {
        $page = $this->newPage();
        $action = $page->instance()->getTable()->getAction('viewTransfer');
        $this->assertFalse($action->record(WmsShortageAllocation::findOrFail(1))->isVisible());
        $this->makePrintable();
        DB::table('stock_transfer_queue')->update(['status' => 'BEFORE']);
        $page = $this->newPage();
        $this->assertFalse($page->instance()->getTable()->getAction('viewTransfer')->record($page->instance()->getTableRecords()->first())->isVisible());
    }

    public function test_foreign_company_transfer_trade_is_not_linked_or_selectable(): void
    {
        $this->makePrintable();
        DB::table('trades')->where('id', 20)->update(['client_id' => 7]);
        $component = $this->newPage();
        $record = $component->instance()->getTableRecords()->first();
        $this->assertFalse($component->instance()->getTable()->isRecordSelectable($record));
        $this->assertNull($component->instance()->getTable()->getColumn('transfer_status')->record($record)->getUrl());
        $this->assertFalse($component->instance()->getTable()->getAction('viewTransfer')->record($record)->isVisible());
    }

    private function newPage(): \Livewire\Features\SupportTesting\Testable
    {
        $user = new \App\Models\User;
        $user->setRawAttributes(['id' => 9, 'client_id' => 6, 'default_warehouse_id' => 1, 'wms_selected_warehouse_id' => 2]);
        $this->actingAs($user);
        $this->mock(\Sakemaru\Auth\Services\PermissionService::class)->shouldReceive('check')->andReturnTrue();
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));

        return \Livewire\Livewire::test(\App\Filament\Resources\WmsShortageAllocations\Pages\ListNewWmsShortageAllocations::class);
    }

    public function test_default_completed_filter_uses_business_shipment_date_and_keeps_unfinished_rows(): void
    {
        DB::table('wms_shortage_allocations')->where('id', 1)->update(['is_finished' => true, 'status' => 'FULFILLED']);
        foreach ([2 => ['2026-10-13', true], 3 => ['2026-10-15', true], 4 => ['2026-10-13', false]] as $id => [$date, $finished]) {
            DB::table('wms_shortage_allocations')->insert(['id' => $id, 'shortage_id' => 1, 'shipment_date' => $date, 'is_finished' => $finished, 'status' => $finished ? 'FULFILLED' : 'RESERVED']);
        }
        $page = $this->newPage();
        $page->assertCanSeeTableRecords(WmsShortageAllocation::whereIn('id', [1, 4])->get())
            ->assertCanNotSeeTableRecords(WmsShortageAllocation::whereIn('id', [2, 3])->get());
        $page->set('tableFilters.completed_visibility.value', 'all')->assertCanSeeTableRecords(WmsShortageAllocation::all());
        $page->set('tableFilters.completed_visibility.value', 'none')
            ->assertCanSeeTableRecords(WmsShortageAllocation::whereKey(4)->get())
            ->assertCanNotSeeTableRecords(WmsShortageAllocation::whereIn('id', [1, 2, 3])->get());
    }

    public function test_removing_today_filter_shows_past_completed_requests_without_changing_warehouse_scope(): void
    {
        DB::table('wms_shortage_allocations')->where('id', 1)->update(['is_finished' => true, 'status' => 'FULFILLED', 'shipment_date' => '2026-10-13']);
        DB::table('wms_shortage_allocations')->insert([
            ['id' => 2, 'shortage_id' => 1, 'is_finished' => true, 'status' => 'FULFILLED', 'shipment_date' => '2026-10-13', 'target_warehouse_id' => 1],
            ['id' => 3, 'shortage_id' => 1, 'is_finished' => false, 'status' => 'CANCELLED', 'shipment_date' => '2026-10-13', 'target_warehouse_id' => 2],
        ]);
        $page = $this->newPage()->assertCanNotSeeTableRecords(WmsShortageAllocation::all());
        $page->call('removeTableFilter', 'completed_visibility')
            ->assertSet('tableFilters.completed_visibility.value', null)
            ->assertCanSeeTableRecords(WmsShortageAllocation::whereKey(1)->get())
            ->assertCanNotSeeTableRecords(WmsShortageAllocation::whereIn('id', [2, 3])->get());
        $page->set('tableFilters.completed_visibility.value', 'today')
            ->assertCanNotSeeTableRecords(WmsShortageAllocation::whereKey(1)->get());
    }

    public function test_header_selected_warehouse_overrides_default_and_cannot_be_bypassed_by_old_tabs(): void
    {
        DB::table('wms_shortage_allocations')->insert(['id' => 2, 'shortage_id' => 1, 'target_warehouse_id' => 1]);
        $page = $this->newPage()->assertCanSeeTableRecords(WmsShortageAllocation::whereKey(1)->get())
            ->assertCanNotSeeTableRecords(WmsShortageAllocation::whereKey(2)->get());
        $this->assertSame([], $page->instance()->getTabs());
        $page->set('activeTab', 'all')->assertCanNotSeeTableRecords(WmsShortageAllocation::whereKey(2)->get());
        $this->assertArrayNotHasKey('target_warehouse_id', $page->instance()->getTable()->getFilters());
    }

    public function test_compact_page_keeps_download_and_existing_picking_list_modal(): void
    {
        $page = $this->newPage()->assertSee('ダウンロード')->assertSee('ピッキングリスト出力');
        $columns = array_keys($page->instance()->getTable()->getVisibleColumns());
        foreach (['id', 'targetWarehouse.code', 'targetWarehouse.name', 'sourceWarehouse.code', 'finished_at'] as $name) {
            $this->assertNotContains($name, $columns);
        }
        $page->mountTableAction('printProxyShipmentPickingList')->assertTableActionDataSet(['warehouse_id' => 2, 'shipment_date' => '2026-10-14']);
    }

    public function test_picking_output_uses_header_warehouse_even_when_hidden_value_is_changed(): void
    {
        $this->mock(\App\Services\PickingList\ProxyShipmentPickingListService::class)->shouldReceive('generateList')
            ->once()->with(2, '2026-10-14', null)->andReturn(['courses' => [['header' => []]]]);
        $this->mock(\App\Services\PickingList\PickingListPdfService::class)->shouldReceive('renderProxyShipmentPdf')
            ->once()->andReturn('%PDF-1.4 fixture');
        $this->newPage()->callTableAction('printProxyShipmentPickingList', data: ['warehouse_id' => 1, 'shipment_date' => '2026-10-14'])
            ->assertHasNoTableActionErrors();
    }

    public function test_download_uses_the_same_filtered_warehouse_query(): void
    {
        DB::table('wms_shortage_allocations')->insert(['id' => 2, 'shortage_id' => 1, 'target_warehouse_id' => 1]);
        $this->mock(\App\Services\Export\ExportService::class)->shouldReceive('export')->once()
            ->withArgs(function ($query, $columns, $format, $resource, $userId) {
                $this->assertSame([1], $query->pluck('id')->all());
                $this->assertArrayHasKey('商品CD', $columns);

                return $format === \App\Enums\ExportFormat::CSV && $userId === 9;
            })->andReturn(response()->streamDownload(fn () => print ('fixture'), 'requests.csv'));
        $this->newPage()->callTableAction('export', data: ['format' => 'csv', 'file_name' => 'requests'])
            ->assertHasNoTableActionErrors();
    }

    public function test_cancellation_action_uses_the_invalidation_service_and_default_filter_hides_the_row(): void
    {
        $component = $this->newPage();
        $component->callTableAction('cancel', 1, ['cancel_reason' => '横持ち不要'])->assertHasNoTableActionErrors();
        $this->assertSame('CANCELLED', WmsShortageAllocation::findOrFail(1)->status);
        $this->assertSame(2, WmsShortageAllocation::findOrFail(1)->picked_qty);
        $this->assertSame(0, DB::table('stock_transfer_queue')->count());
        $this->assertSame(0, DB::table('quantity_update_queue')->count());
        $component->assertCanNotSeeTableRecords([WmsShortageAllocation::findOrFail(1)]);
        $component->set('tableFilters.cancellation.value', 'include')->assertCanSeeTableRecords([WmsShortageAllocation::findOrFail(1)]);
    }

    public function test_completion_action_keeps_the_completed_row_on_the_new_list(): void
    {
        $this->mock(AllocationSyncService::class)->shouldReceive('syncAllocationIfReady')->once()->andReturnNull();
        $component = $this->newPage();
        $component->callTableAction('complete', 1)->assertHasNoTableActionErrors();
        $this->assertTrue(WmsShortageAllocation::findOrFail(1)->is_finished);
        $component->assertCanSeeTableRecords([WmsShortageAllocation::findOrFail(1)]);
        $this->assertSame(1, DB::table('stock_transfer_queue')->count());
    }

    public function test_cancellation_only_invalidates_the_request_and_preserves_all_business_values(): void
    {
        $before = (array) DB::table('wms_shortage_allocations')->find(1);
        $parent = (array) DB::table('wms_shortages')->find(1);
        $trade = (array) DB::table('trades')->find(10);
        $result = app(ProxyShipmentLifecycleService::class)->cancel(1, 9, '横持ちを実施しない');
        $after = $result->getRawOriginal();
        foreach (['status', 'cancelled_at', 'cancelled_user_id', 'cancel_reason', 'cancel_previous_status', 'updated_at'] as $field) {
            unset($before[$field], $after[$field]);
        }
        $this->assertEquals($before, $after);
        $this->assertEquals($parent, (array) DB::table('wms_shortages')->find(1));
        $this->assertEquals($trade, (array) DB::table('trades')->find(10));
        $this->assertSame(30, DB::table('real_stocks')->value('current_quantity'));
        $this->assertSame(0, DB::table('stock_transfer_queue')->count());
        $this->assertSame(0, DB::table('quantity_update_queue')->count());
        $this->assertSame('RESERVED', $result->cancel_previous_status);
        $this->assertSame(2, $result->picked_qty);
        $this->assertFalse($result->is_finished);
    }

    public function test_approval_does_not_revive_a_cancelled_pending_request_or_sync_the_source(): void
    {
        DB::table('wms_shortage_allocations')->where('id', 1)->update(['status' => 'PENDING', 'is_confirmed' => false]);
        app(ProxyShipmentLifecycleService::class)->cancel(1, 9, '不要');
        \App\Actions\Wms\ConfirmShortageAllocations::execute(1, 9);
        $this->assertSame('CANCELLED', WmsShortageAllocation::findOrFail(1)->status);
        $shortage = \App\Models\WmsShortage::findOrFail(1);
        $shortage->source_pick_result_id = 100;
        $this->assertNull(app(\App\Services\QuantityUpdate\QuantityUpdateQueueService::class)->createQueueForShortageApproval($shortage));
        $this->assertSame(0, DB::table('quantity_update_queue')->count());
    }

    public function test_cancel_resend_is_idempotent_and_stale_completion_is_rejected(): void
    {
        $service = app(ProxyShipmentLifecycleService::class);
        $first = $service->cancel(1, 9, '取消');
        $second = $service->cancel(1, 10, '再送');
        $this->assertSame($first->cancelled_user_id, $second->cancelled_user_id);
        $this->assertSame('取消', $second->cancel_reason);
        $this->expectException(ValidationException::class);
        $service->complete(1, 9);
    }

    public function test_cancelled_row_cannot_be_saved_through_a_stale_model(): void
    {
        $stale = WmsShortageAllocation::findOrFail(1);
        app(ProxyShipmentLifecycleService::class)->cancel(1, 9, '取消');
        $this->expectException(ValidationException::class);
        $stale->update(['picked_qty' => 3]);
    }

    public function test_existing_queue_prevents_cancellation_without_deleting_or_rewriting_it(): void
    {
        DB::table('stock_transfer_queue')->insert(['client_id' => 6, 'request_id' => 'proxy-shipment-1']);
        try {
            app(ProxyShipmentLifecycleService::class)->cancel(1, 9, '取消');
            $this->fail('Cancellation should be rejected');
        } catch (ValidationException) {
            $this->assertSame('RESERVED', WmsShortageAllocation::findOrFail(1)->status);
            $this->assertSame(1, DB::table('stock_transfer_queue')->count());
        }
    }

    public function test_sibling_or_periodic_sync_cannot_reduce_the_source_for_a_cancelled_group(): void
    {
        app(ProxyShipmentLifecycleService::class)->cancel(1, 9, '取消');
        DB::table('wms_shortage_allocations')->insert(['id' => 2, 'shortage_id' => 1, 'is_finished' => true, 'status' => 'SHORTAGE']);
        $service = new class extends AllocationSyncService
        {
            public function syncGroup(int $id)
            {
                return $this->syncShortageIdIfReady($id, app(\App\Services\QuantityUpdate\QuantityUpdateQueueService::class));
            }
        };
        $this->assertNull($service->syncGroup(1));
        $this->assertSame(0, DB::table('quantity_update_queue')->count());
        $this->assertSame(10, DB::table('trades')->where('id', 10)->value('quantity'));
    }

    public function test_completion_creates_one_exact_queue_and_repeat_does_not_recreate_transfer(): void
    {
        $this->mock(AllocationSyncService::class)->shouldReceive('syncAllocationIfReady')->once()->andReturnNull();
        $service = app(ProxyShipmentLifecycleService::class);
        $first = $service->complete(1, 9, pickedQty: 6);
        $second = $service->complete(1, 9, pickedQty: 6);
        $this->assertSame($first['stock_transfer_queue_id'], $second['stock_transfer_queue_id']);
        $this->assertSame(1, DB::table('stock_transfer_queue')->count());
        $this->assertSame('proxy-shipment-1', DB::table('stock_transfer_queue')->value('request_id'));
        $this->assertSame('FULFILLED', $first['allocation']->status);
    }

    public function test_completion_rolls_back_when_queue_registration_fails(): void
    {
        $this->mock(\App\Services\Shortage\StockTransferQueueService::class)->shouldReceive('createStockTransferQueue')->andThrow(new \RuntimeException('failure'));
        try {
            app(ProxyShipmentLifecycleService::class)->complete(1, 9, pickedQty: 6);
        } catch (\RuntimeException) {
        }
        $this->assertFalse(WmsShortageAllocation::findOrFail(1)->is_finished);
        $this->assertSame('RESERVED', WmsShortageAllocation::findOrFail(1)->status);
    }

    public function test_exact_mapping_ignores_deliver_and_never_uses_slip_number_matches(): void
    {
        $this->makePrintable();
        DB::table('stock_transfer_queue')->insert(['client_id' => 6, 'request_id' => 'proxy-shipment-deliver-1', 'action_type' => 'DELIVER', 'stock_transfer_id' => 50]);
        $resolved = app(ProxyShipmentTransferResolver::class)->resolve(1, 6);
        $this->assertSame(1, (int) $resolved['stock_transfer_queue_id']);
        $this->assertSame(50, (int) $resolved['stock_transfer_id']);
        $this->expectException(ValidationException::class);
        app(ProxyShipmentTransferResolver::class)->resolve(1, 7);
    }

    public function test_duplicate_create_results_are_rejected_instead_of_linking_two_requests_to_one_transfer(): void
    {
        $this->makePrintable();
        DB::table('stock_transfer_queue')->insert(['client_id' => 6, 'request_id' => 'proxy-shipment-999', 'action_type' => 'CREATE', 'stock_transfer_id' => 50]);
        $this->expectException(ValidationException::class);
        app(ProxyShipmentTransferResolver::class)->resolve(1, 6);
    }

    public function test_print_request_retry_returns_the_same_request_and_rejects_selection_change(): void
    {
        $this->makePrintable();
        $key = (string) Str::uuid();
        $service = app(ProxyShipmentPrintRequestService::class);
        $this->assertSame($service->create([1], 6, 9, $key), $service->create([1], 6, 9, $key));
        $this->assertSame(1, DB::table('wms_proxy_shipment_print_requests')->count());
        $this->assertSame(1, DB::table('wms_proxy_shipment_print_request_items')->count());
        $this->expectException(ValidationException::class);
        $service->create([1, 2], 6, 9, $key);
    }

    public function test_completed_sibling_does_not_sync_or_change_the_parent_after_cancellation(): void
    {
        app(ProxyShipmentLifecycleService::class)->cancel(1, 9, '不要');
        $cancelled = (array) DB::table('wms_shortage_allocations')->find(1);
        $parent = (array) DB::table('wms_shortages')->find(1);
        DB::table('wms_shortage_allocations')->insert(['id' => 2, 'shortage_id' => 1, 'shipment_date' => now()->toDateString()]);
        $this->app->instance(AllocationSyncService::class, new class extends AllocationSyncService
        {
            protected function systemShipmentDate(): ?string
            {
                return now()->toDateString();
            }
        });
        $result = app(ProxyShipmentLifecycleService::class)->complete(2, pickerId: 11, pickedQty: 2);
        $this->assertTrue($result['allocation']->is_finished);
        $this->assertNull($result['quantity_update_queue_id']);
        $this->assertEquals($cancelled, (array) DB::table('wms_shortage_allocations')->find(1));
        $this->assertEquals($parent, (array) DB::table('wms_shortages')->find(1));
        $this->assertSame(0, DB::table('quantity_update_queue')->count());
        $this->assertSame('proxy-shipment-2', DB::table('stock_transfer_queue')->value('request_id'));
        $this->assertSame(10, DB::table('trades')->where('id', 10)->value('quantity'));
        $this->assertSame(30, DB::table('real_stocks')->value('current_quantity'));
    }

    public function test_zero_quantity_and_same_warehouse_complete_without_a_transfer(): void
    {
        $this->mock(AllocationSyncService::class)->shouldReceive('syncAllocationIfReady')->twice()->andReturnNull();
        $service = app(ProxyShipmentLifecycleService::class);
        $zero = $service->complete(1, 9, pickedQty: 0);
        $this->assertSame('ZERO_QUANTITY', $zero['allocation']->transfer_not_required_reason);
        DB::table('wms_shortage_allocations')->insert(['id' => 2, 'shortage_id' => 1, 'target_warehouse_id' => 1]);
        $same = $service->complete(2, 9, pickedQty: 6);
        $this->assertSame('SAME_WAREHOUSE', $same['allocation']->transfer_not_required_reason);
        $this->assertTrue($zero['allocation']->is_finished);
        $this->assertTrue($same['allocation']->is_finished);
        $this->assertSame(0, DB::table('stock_transfer_queue')->count());
    }

    public function test_invalid_quantity_does_not_finish_or_create_a_queue(): void
    {
        foreach ([-1, 7] as $quantity) {
            try {
                app(ProxyShipmentLifecycleService::class)->complete(1, 9, pickedQty: $quantity);
                $this->fail('Out-of-range quantity was accepted');
            } catch (ValidationException) {
            }
            $this->assertSame(2, WmsShortageAllocation::findOrFail(1)->picked_qty);
            $this->assertFalse(WmsShortageAllocation::findOrFail(1)->is_finished);
        }
        $this->assertSame(0, DB::table('stock_transfer_queue')->count());
    }

    public function test_mixed_print_selection_rolls_back_the_whole_request(): void
    {
        $this->makePrintable();
        DB::table('wms_shortage_allocations')->insert(['id' => 2, 'shortage_id' => 1]);
        try {
            app(ProxyShipmentPrintRequestService::class)->create([1, 2], 6, 9, (string) Str::uuid());
            $this->fail('Unfinished selection was accepted');
        } catch (ValidationException) {
        }
        $this->assertSame(0, DB::table('wms_proxy_shipment_print_requests')->count());
        $this->assertSame(0, DB::table('wms_proxy_shipment_print_request_items')->count());
        $this->assertSame(1, DB::table('stock_transfer_queue')->count());
    }

    public function test_print_dialog_defaults_to_both_and_keeps_the_selected_outbound_type(): void
    {
        $this->makePrintable();
        $page = $this->newPage()->set('selectedTableRecords', [1]);
        $page->mountTableAction('printTransfers')->assertTableActionDataSet(['copy_type' => 'both']);
        $page->call('unmountAction')->callTableAction('printTransfers', data: ['copy_type' => 'outbound'])->assertHasNoTableActionErrors();
        $this->assertSame('outbound', DB::table('wms_proxy_shipment_print_requests')->value('copy_type'));
        $this->assertSame(1, DB::table('wms_proxy_shipment_print_request_items')->count());
    }

    public function test_same_print_operation_cannot_change_its_copy_type(): void
    {
        $this->makePrintable();
        $key = (string) Str::uuid();
        $service = app(ProxyShipmentPrintRequestService::class);
        $first = $service->create([1], 6, 9, $key, 'outbound');
        $this->assertSame($first, $service->create([1], 6, 9, $key, 'outbound'));
        try {
            $service->create([1], 6, 9, $key, 'both');
            $this->fail('Print type was changed by a retry');
        } catch (ValidationException) {
        }
        $this->assertSame(1, DB::table('wms_proxy_shipment_print_requests')->count());
        $this->assertSame('outbound', DB::table('wms_proxy_shipment_print_requests')->value('copy_type'));
    }

    public function test_invalid_print_type_is_rejected_before_creating_a_request(): void
    {
        try {
            app(ProxyShipmentPrintRequestService::class)->create([1], 6, 9, (string) Str::uuid(), 'inbound');
            $this->fail('Unsupported print type was accepted');
        } catch (ValidationException) {
        }
        $this->assertSame(0, DB::table('wms_proxy_shipment_print_requests')->count());
    }

    public function test_cancelled_rows_are_not_work_candidates_or_editable_in_device_response(): void
    {
        app(ProxyShipmentLifecycleService::class)->cancel(1, 9, '取消');
        $this->assertSame(0, WmsShortageAllocation::readyForProxyPicking()->count());
        $this->assertSame(0, WmsShortageAllocation::activeRequest()->count());
        $this->expectException(ValidationException::class);
        app(ProxyShipmentPickingService::class)->update(WmsShortageAllocation::findOrFail(1), new \App\Models\WmsPicker(['id' => 1]), 3);
    }
}
