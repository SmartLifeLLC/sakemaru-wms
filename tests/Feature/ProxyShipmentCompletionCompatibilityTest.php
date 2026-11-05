<?php

namespace Tests\Feature;

use App\Models\QuantityUpdateQueue;
use App\Models\WmsPicker;
use App\Models\WmsShortageAllocation;
use App\Services\QuantityUpdate\AllocationSyncService;
use App\Services\QuantityUpdate\QuantityUpdateQueueService;
use App\Services\Shortage\ProxyShipmentLifecycleService;
use App\Services\Shortage\ProxyShipmentPickingService;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Support\ProxyShipmentDatabase;
use Tests\TestCase;

class ProxyShipmentCompletionCompatibilityTest extends TestCase
{
    use ProxyShipmentDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createProxyShipmentDatabase('sakemaru');
    }

    public function test_quantity_sync_failure_preserves_device_completion_and_transfer_registration(): void
    {
        $this->mock(AllocationSyncService::class)->shouldReceive('syncAllocationIfReady')->once()
            ->andReturnUsing(function (WmsShortageAllocation $allocation) {
                $this->assertSame(0, DB::connection('sakemaru')->transactionLevel());
                $this->assertTrue($allocation->fresh()->is_finished);
                $this->assertSame(1, DB::table('stock_transfer_queue')->count());
                throw new \RuntimeException('simulated sync failure');
            });
        $picker = new WmsPicker;
        $picker->setRawAttributes(['id' => 11]);

        $result = app(ProxyShipmentPickingService::class)->complete(WmsShortageAllocation::findOrFail(1), $picker, 2);

        $this->assertTrue($result['quantity_sync_failed']);
        $this->assertNull($result['quantity_update_queue_id']);
        $this->assertTrue($result['allocation']->is_finished);
        $this->assertSame('SHORTAGE', $result['allocation']->status);
        $this->assertSame(11, $result['allocation']->finished_picker_id);
        $this->assertSame('PARTIAL_SHORTAGE', DB::table('wms_shortages')->value('status'));
        $this->assertSame('proxy-shipment-1', DB::table('stock_transfer_queue')->value('request_id'));
        $this->assertSame(2, json_decode(DB::table('stock_transfer_queue')->value('items'), true)[0]['quantity']);
        $this->assertSame(10, DB::table('trades')->where('id', 10)->value('quantity'));
        $this->assertSame(30, DB::table('real_stocks')->value('current_quantity'));
    }

    public function test_retry_after_sync_failure_syncs_once_without_recompleting_or_recreating_transfer(): void
    {
        // Exercise the real synchronization transaction and cancellation checks, replacing only
        // the downstream quantity queue writer. Its first partial write must be rolled back.
        $calls = 0;
        $this->mock(QuantityUpdateQueueService::class)->shouldReceive('createQueueForAllocationSync')->twice()
            ->andReturnUsing(function ($shortage, string $requestId) use (&$calls) {
                $this->assertSame(1, DB::connection('sakemaru')->transactionLevel());
                $id = DB::table('quantity_update_queue')->insertGetId(['request_id' => $requestId]);
                if (++$calls === 1) {
                    throw new \RuntimeException('simulated queue writer failure');
                }

                return QuantityUpdateQueue::findOrFail($id);
            });
        $service = app(ProxyShipmentLifecycleService::class);
        $first = $service->complete(1, 9, pickedQty: 2);
        $completed = (array) DB::table('wms_shortage_allocations')->find(1);
        $this->assertTrue($first['quantity_sync_failed']);
        $this->assertSame(0, DB::table('quantity_update_queue')->count());
        $this->assertTrue($first['allocation']->is_finished);

        $second = $service->complete(1, 10, pickedQty: 2);
        $third = $service->complete(1, 10, pickedQty: 2);

        $this->assertFalse($second['quantity_sync_failed']);
        $this->assertNotNull($second['quantity_update_queue_id']);
        $this->assertFalse($third['quantity_sync_failed']);
        $this->assertTrue($second['allocation']->is_synced);
        $this->assertSame($first['stock_transfer_queue_id'], $second['stock_transfer_queue_id']);
        $this->assertSame($second['stock_transfer_queue_id'], $third['stock_transfer_queue_id']);
        $this->assertSame($completed['finished_at'], DB::table('wms_shortage_allocations')->value('finished_at'));
        $this->assertSame(9, DB::table('wms_shortage_allocations')->value('finished_user_id'));
        $this->assertSame(1, DB::table('stock_transfer_queue')->count());
        $this->assertSame(1, DB::table('quantity_update_queue')->count());
    }

    public function test_zero_picked_completion_is_kept_without_a_transfer_when_sync_fails(): void
    {
        $this->mock(AllocationSyncService::class)->shouldReceive('syncAllocationIfReady')->once()
            ->andThrow(new \Error('simulated synchronization error'));

        $result = app(ProxyShipmentLifecycleService::class)->complete(1, 9, pickedQty: 0);

        $this->assertTrue($result['quantity_sync_failed']);
        $this->assertTrue($result['allocation']->is_finished);
        $this->assertSame('SHORTAGE', $result['allocation']->status);
        $this->assertSame('ZERO_QUANTITY', $result['allocation']->transfer_not_required_reason);
        $this->assertNull($result['stock_transfer_queue_id']);
        $this->assertSame(0, DB::table('stock_transfer_queue')->count());
        $this->assertSame(0, DB::table('quantity_update_queue')->count());
    }

    public function test_sync_failure_on_already_finished_resend_keeps_the_existing_completion(): void
    {
        $this->makePrintable();
        DB::table('wms_shortage_allocations')->where('id', 1)->update(['status' => 'SHORTAGE', 'picked_qty' => 2]);
        $before = (array) DB::table('wms_shortage_allocations')->find(1);
        $calls = 0;
        $this->mock(AllocationSyncService::class)->shouldReceive('syncAllocationIfReady')->twice()
            ->andReturnUsing(function () use (&$calls) {
                $calls++;
                throw new \RuntimeException('simulated sync failure');
            });
        $service = app(ProxyShipmentLifecycleService::class);

        $first = $service->complete(1, 9);
        $second = $service->complete(1, 9);
        $this->assertSame(2, $calls);
        $this->assertTrue($first['quantity_sync_failed']);
        $this->assertTrue($second['quantity_sync_failed']);
        $this->assertEquals($before, (array) DB::table('wms_shortage_allocations')->find(1));
        $this->assertSame(1, DB::table('stock_transfer_queue')->count());
    }

    public function test_cancelled_sibling_blocks_quantity_sync_on_completion_resend_without_business_changes(): void
    {
        DB::table('wms_shortage_allocations')->insert(['id' => 2, 'shortage_id' => 1, 'shipment_date' => '2026-10-14']);
        $service = app(ProxyShipmentLifecycleService::class);
        $service->complete(1, 9, pickedQty: 2); // Sibling remains unfinished; no quantity sync yet.
        $service->cancel(2, 9, '横持ち不要');
        $before = $this->businessSnapshot();
        $this->mock(QuantityUpdateQueueService::class)->shouldNotReceive('createQueueForAllocationSync');

        $result = $service->complete(1, 9, pickedQty: 2);

        $this->assertFalse($result['quantity_sync_failed']);
        $this->assertNull($result['quantity_update_queue_id']);
        $this->assertEquals($before, $this->businessSnapshot());
        $this->assertFalse(WmsShortageAllocation::findOrFail(1)->is_synced);
    }

    public function test_cancellation_and_stale_resend_only_invalidate_the_request(): void
    {
        $before = $this->businessSnapshot();
        $allocation = (array) DB::table('wms_shortage_allocations')->find(1);
        $service = app(ProxyShipmentLifecycleService::class);
        $service->cancel(1, 9, '不要');
        $cancelled = (array) DB::table('wms_shortage_allocations')->find(1);
        try {
            $service->complete(1, 9, pickedQty: 2);
            $this->fail('Cancelled allocation was completed');
        } catch (ValidationException) {
        }
        $service->cancel(1, 10, '再送');

        $this->assertEquals($before, $this->businessSnapshot());
        $this->assertEquals($cancelled, (array) DB::table('wms_shortage_allocations')->find(1));
        foreach (['status', 'cancelled_at', 'cancelled_user_id', 'cancel_reason', 'cancel_previous_status', 'updated_at'] as $key) {
            unset($allocation[$key], $cancelled[$key]);
        }
        $this->assertEquals($allocation, $cancelled);
    }

    public function test_old_and_new_completion_actions_warn_without_losing_completion_on_sync_failure(): void
    {
        $this->mock(AllocationSyncService::class)->shouldReceive('syncAllocationIfReady')->twice()
            ->andThrow(new \RuntimeException('simulated sync failure'));
        $page = $this->newPage();
        $page->callTableAction('complete', 1)->assertHasNoTableActionErrors();
        $this->assertTrue(WmsShortageAllocation::findOrFail(1)->is_finished);
        $this->assertSyncWarning();

        DB::table('wms_shortage_allocations')->insert(['id' => 2, 'shortage_id' => 1, 'shipment_date' => '2026-10-14']);
        // Use the actual legacy table action with its normal Filament injection/evaluation.
        $table = \App\Filament\Resources\WmsShortageAllocations\Tables\WmsShortageAllocationsTable::configure($page->instance()->getTable());
        $action = $table->getAction('complete')->record(WmsShortageAllocation::findOrFail(2));
        $action->call();
        $this->assertTrue(WmsShortageAllocation::findOrFail(2)->is_finished);
        $this->assertSyncWarning();
        $this->assertSame(2, DB::table('stock_transfer_queue')->count());
    }

    private function assertSyncWarning(): void
    {
        Notification::assertNotified(Notification::make()->title('横持ち出荷を完了しました')
            ->body('数量同期に失敗しました。完了状態は保存済みです。横持ち出荷履歴の「手動同期」から再実行してください。')->warning());
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

    private function businessSnapshot(): array
    {
        return collect(['trades', 'trade_items', 'wms_shortages', 'real_stocks', 'stock_transfer_queue', 'quantity_update_queue'])
            ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->orderBy('id')->get()->toArray()])->all();
    }
}
