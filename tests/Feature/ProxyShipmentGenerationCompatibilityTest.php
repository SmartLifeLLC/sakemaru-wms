<?php

namespace Tests\Feature;

use App\Models\WmsShortage;
use App\Models\WmsShortageAllocation;
use App\Services\Shortage\ProxyShipmentLifecycleService;
use App\Services\Shortage\ProxyShipmentService;
use App\Services\Shortage\ShortageApprovalService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ProxyShipmentGenerationCompatibilityTest extends \Tests\TestCase
{
    use \Tests\Support\ProxyShipmentDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createProxyShipmentDatabase('sakemaru');
        Schema::table('wms_shortages', function (Blueprint $t) {
            $t->integer('updater_id')->nullable();
            $t->date('shipment_date')->nullable();
            $t->integer('delivery_course_id')->nullable();
            $t->boolean('is_confirmed')->default(false);
            $t->boolean('is_synced')->default(false);
            $t->integer('confirmed_by')->nullable();
            $t->integer('confirmed_user_id')->nullable();
            $t->timestamp('confirmed_at')->nullable();
        });
        Schema::table('wms_shortage_allocations', function (Blueprint $t) {
            $t->decimal('tax_exempt_price')->nullable();
            $t->decimal('price')->nullable();
            $t->integer('created_by')->nullable();
            $t->integer('updated_by')->nullable();
        });
        Schema::table('trade_items', fn (Blueprint $t) => $t->decimal('price')->nullable());
        foreach (['item_partner_prices', 'item_prices'] as $name) {
            Schema::create($name, function (Blueprint $t) {
                $t->id();
                $t->integer('item_id');
                $t->integer('warehouse_id')->nullable();
                $t->integer('partner_id')->nullable();
                $t->boolean('is_active');
                $t->date('start_date');
                $t->decimal('purchase_unit_price')->nullable();
                $t->decimal('tax_exempt_unit_price')->nullable();
                $t->decimal('cost_unit_price')->nullable();
            });
        }
        Schema::create('wms_picking_item_results', function (Blueprint $t) {
            $t->id();
            $t->integer('shortage_allocated_qty')->default(0);
            $t->string('shortage_allocated_qty_type')->nullable();
            $t->boolean('is_ready_to_shipment')->default(false);
            $t->timestamp('shipment_ready_at')->nullable();
            $t->timestamps();
        });
        Schema::table('wms_picking_item_results', function (Blueprint $t) {
            $t->integer('picking_task_id')->nullable();
            $t->integer('item_id')->nullable();
            $t->string('status')->nullable();
            $t->string('source_type')->default('EARNING');
        });
        Schema::create('wms_picking_tasks', function (Blueprint $t) {
            $t->id();
            $t->integer('delivery_course_id');
            $t->date('shipment_date');
            $t->string('status');
        });
        Schema::table('trades', fn (Blueprint $t) => $t->string('trade_category')->default('EARNING'));
        Schema::table('quantity_update_queue', function (Blueprint $t) {
            $t->integer('client_id')->nullable();
            $t->string('trade_category')->nullable();
            $t->integer('trade_id')->nullable();
            $t->integer('trade_item_id')->nullable();
            $t->integer('update_qty')->nullable();
            $t->string('quantity_type')->nullable();
            $t->date('shipment_date')->nullable();
            $t->string('status')->default('BEFORE');
            $t->timestamps();
        });
        Schema::create('earnings', function (Blueprint $t) {
            $t->id();
            $t->integer('trade_id');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
        DB::table('earnings')->insert(['id' => 1, 'trade_id' => 10]);
        DB::table('wms_picking_tasks')->insert(['id' => 1, 'delivery_course_id' => 1, 'shipment_date' => '2026-10-14', 'status' => 'COMPLETED']);
        DB::table('wms_picking_item_results')->insert(['id' => 1, 'picking_task_id' => 1, 'item_id' => 1, 'status' => 'SHORTAGE']);
        DB::table('wms_shortages')->where('id', 1)->update(['source_pick_result_id' => 1, 'shipment_date' => '2026-10-14']);
    }

    private function action(string $name = 'editProxyShipment'): \Filament\Actions\Action
    {
        $component = \Mockery::mock(\Filament\Tables\Contracts\HasTable::class);

        return \App\Filament\Resources\WmsShortagesWaitingApprovals\Tables\WmsShortagesWaitingApprovalsTable::configure(\Filament\Tables\Table::make($component))->getAction($name);
    }

    private function snapshot(array $tables): array
    {
        return collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderBy('id')->get()->toJson()])->all();
    }

    public function test_normal_generation_and_approval_still_register_original_quantity_update(): void
    {
        DB::table('wms_shortage_allocations')->where('id', 1)->update(['status' => 'PENDING', 'is_confirmed' => false]);
        $service = app(ProxyShipmentService::class);
        $allocation = $service->createProxyShipment(WmsShortage::findOrFail(1), 2, 6, 'PIECE', 9);
        $this->assertSame(1, $allocation->id);
        $this->assertSame(1, DB::table('wms_shortage_allocations')->count());
        $result = app(ShortageApprovalService::class)->approveShortage(WmsShortage::findOrFail(1), 9);
        $this->assertTrue($result['confirmed']);
        $this->assertSame(1, $result['allocations_confirmed']);
        $this->assertSame('10.00', $result['queue']->update_qty);
        $this->assertSame('EARNING', $result['queue']->trade_category);
        $this->assertSame('RESERVED', $allocation->fresh()->status);
        $this->assertSame(6, DB::table('wms_picking_item_results')->value('shortage_allocated_qty'));
        $this->assertSame(0, DB::table('stock_transfer_queue')->count());
        $again = app(ShortageApprovalService::class)->approveShortage(WmsShortage::findOrFail(1), 9);
        $this->assertFalse($again['confirmed']);
        $this->assertSame(1, DB::table('quantity_update_queue')->count());
    }

    public function test_cancellation_after_approval_preserves_original_documents_and_printability(): void
    {
        DB::table('wms_shortage_allocations')->where('id', 1)->update(['status' => 'PENDING', 'is_confirmed' => false]);
        app(ShortageApprovalService::class)->approveShortage(WmsShortage::findOrFail(1), 9);
        // Represent the existing core worker's completed update and already-generated sales PDF.
        DB::table('quantity_update_queue')->update(['status' => 'FINISHED']);
        DB::table('wms_shortages')->where('id', 1)->update(['is_synced' => true]);
        DB::table('log_pdf_exports')->insert(['client_id' => 6, 'creator_id' => 9, 'type' => 'EARNING', 'name' => '売上伝票', 'path' => 'existing/sales.pdf', 'exported_count' => 1]);
        $tables = ['wms_shortages', 'wms_picking_item_results', 'trades', 'trade_items', 'earnings', 'quantity_update_queue', 'stock_transfer_queue', 'stock_transfers', 'real_stocks', 'log_pdf_exports'];
        $before = $this->snapshot($tables);
        $printBefore = app(ShortageApprovalService::class)->checkPrintability(1, '2026-10-14');
        $this->assertTrue($printBefore['can_print']);
        app(ProxyShipmentLifecycleService::class)->cancel(1, 9, 'この商品の倉庫移動は不要');
        $this->assertSame($before, $this->snapshot($tables));
        $this->assertSame($printBefore, app(ShortageApprovalService::class)->checkPrintability(1, '2026-10-14'));
        $this->assertSame('CANCELLED', WmsShortageAllocation::findOrFail(1)->status);
        $this->assertNull(app(\App\Services\QuantityUpdate\QuantityUpdateQueueService::class)->createQueueForShortageApproval(WmsShortage::findOrFail(1)));
        $this->assertSame($before, $this->snapshot($tables));
    }

    public function test_cancelled_request_cannot_create_or_print_a_transfer(): void
    {
        app(ProxyShipmentLifecycleService::class)->cancel(1, 9, '不要');
        $tables = ['trades', 'trade_items', 'earnings', 'stock_transfer_queue', 'stock_transfers', 'quantity_update_queue', 'wms_proxy_shipment_print_requests', 'log_pdf_exports'];
        $before = $this->snapshot($tables);
        foreach ([
            fn () => app(ProxyShipmentLifecycleService::class)->complete(1, 9),
            fn () => app(\App\Services\Shortage\StockTransferQueueService::class)->createStockTransferQueue(WmsShortageAllocation::findOrFail(1)),
            fn () => app(\App\Services\Shortage\ProxyShipmentPrintRequestService::class)->create([1], 6, 9, (string) \Illuminate\Support\Str::uuid()),
        ] as $operation) {
            try {
                $operation();
                $this->fail('Cancelled request must be rejected');
            } catch (\Illuminate\Validation\ValidationException|\RuntimeException $e) {
                $this->assertSame($before, $this->snapshot($tables));
            }
        }
    }

    public function test_replacement_is_new_and_approval_counts_only_active_quantity(): void
    {
        app(ProxyShipmentLifecycleService::class)->cancel(1, 9, '不要');
        $cancelled = $this->snapshot(['wms_shortage_allocations']);
        $new = app(ProxyShipmentService::class)->createProxyShipment(WmsShortage::findOrFail(1), 2, 6, 'PIECE', 9);
        $this->assertNotSame(1, $new->id);
        $this->assertSame('PENDING', $new->status);
        $result = app(ShortageApprovalService::class)->approveShortage(WmsShortage::findOrFail(1), 9);
        $this->assertSame(1, $result['allocations_confirmed']);
        $this->assertSame('RESERVED', $new->fresh()->status);
        $this->assertSame('CANCELLED', WmsShortageAllocation::findOrFail(1)->status);
        $this->assertSame(json_decode($cancelled['wms_shortage_allocations'], true)[0], (array) DB::table('wms_shortage_allocations')->find(1));
        $this->assertSame(6, DB::table('wms_picking_item_results')->value('shortage_allocated_qty'));
        $this->assertNull($result['queue']);
        $this->assertSame(0, DB::table('quantity_update_queue')->count());
        // Cancellation must not fake synchronization or bypass the original sales-slip guard.
        $this->assertFalse(WmsShortage::findOrFail(1)->is_synced);
        $this->assertFalse(app(ShortageApprovalService::class)->checkPrintability(1, '2026-10-14')['can_print']);
    }

    public function test_waiting_form_excludes_cancelled_rows_and_saves_active_edits(): void
    {
        app(ProxyShipmentLifecycleService::class)->cancel(1, 9, '不要');
        DB::table('wms_shortage_allocations')->insert(['id' => 2, 'shortage_id' => 1, 'assign_qty' => 4, 'status' => 'PENDING', 'is_confirmed' => false]);
        $action = $this->action();
        $fill = (new \ReflectionFunction($action->getMountUsing()))->getStaticVariables()['data'];
        $data = $fill(WmsShortage::findOrFail(1));
        $this->assertSame([2], array_column($data['allocations'], 'id'));
        $data['allocations'][0]['assign_qty'] = 3;
        ($action->getActionFunction())(WmsShortage::findOrFail(1), $data);
        $this->assertSame(3, WmsShortageAllocation::findOrFail(2)->assign_qty);
        $this->assertSame('PARTIAL_SHORTAGE', WmsShortage::findOrFail(1)->status);
        $this->assertSame('CANCELLED', WmsShortageAllocation::findOrFail(1)->status);
    }

    public function test_empty_active_form_can_be_saved_without_deleting_cancellation_history(): void
    {
        app(ProxyShipmentLifecycleService::class)->cancel(1, 9, '不要');
        $before = $this->snapshot(['wms_shortage_allocations']);
        $action = $this->action();
        ($action->getActionFunction())(WmsShortage::findOrFail(1), ['allocations' => []]);
        $this->assertSame('SHORTAGE', WmsShortage::findOrFail(1)->status);
        $this->assertSame($before, $this->snapshot(['wms_shortage_allocations']));
    }

    public function test_stale_cancelled_row_rejects_whole_save_without_partial_updates(): void
    {
        DB::table('wms_shortage_allocations')->insert(['id' => 2, 'shortage_id' => 1, 'assign_qty' => 2, 'status' => 'PENDING', 'is_confirmed' => false]);
        app(ProxyShipmentLifecycleService::class)->cancel(1, 9, '不要');
        $before = $this->snapshot(['wms_shortages', 'wms_shortage_allocations']);
        try {
            ($this->action()->getActionFunction())(WmsShortage::findOrFail(1), ['allocations' => [
                ['id' => 2, 'from_warehouse_id' => 2, 'assign_qty' => 3],
                ['id' => 1, 'from_warehouse_id' => 2, 'assign_qty' => 3],
            ]]);
            $this->fail('Stale cancelled row must be rejected');
        } catch (\Illuminate\Validation\ValidationException) {
            $this->assertSame($before, $this->snapshot(['wms_shortages', 'wms_shortage_allocations']));
        }
    }

    public function test_already_approved_shortage_rejects_stale_edit(): void
    {
        $stale = WmsShortage::findOrFail(1);
        DB::table('wms_shortages')->where('id', 1)->update(['is_confirmed' => true]);
        $before = $this->snapshot(['wms_shortages', 'wms_shortage_allocations']);
        try {
            ($this->action()->getActionFunction())($stale, ['allocations' => [['id' => 1, 'from_warehouse_id' => 2, 'assign_qty' => 3]]]);
            $this->fail('Approved shortage must be rejected');
        } catch (\Illuminate\Validation\ValidationException) {
            $this->assertSame($before, $this->snapshot(['wms_shortages', 'wms_shortage_allocations']));
        }
    }

    public function test_reset_shortage_keeps_cancelled_history_and_deletes_only_active_work(): void
    {
        app(ProxyShipmentLifecycleService::class)->cancel(1, 9, '不要');
        DB::table('wms_shortage_allocations')->insert(['id' => 2, 'shortage_id' => 1, 'assign_qty' => 2, 'status' => 'PENDING', 'is_confirmed' => false]);
        $before = $this->snapshot(['trades', 'trade_items', 'earnings', 'quantity_update_queue', 'stock_transfer_queue']);
        ($this->action('cancelShortage')->getActionFunction())(WmsShortage::findOrFail(1));
        $this->assertSame(1, DB::table('wms_shortage_allocations')->count());
        $this->assertSame('CANCELLED', WmsShortageAllocation::findOrFail(1)->status);
        $this->assertSame('BEFORE', WmsShortage::findOrFail(1)->status);
        $this->assertSame($before, $this->snapshot(['trades', 'trade_items', 'earnings', 'quantity_update_queue', 'stock_transfer_queue']));
    }

    public function test_reset_rolls_back_when_a_finished_request_is_present(): void
    {
        DB::table('wms_shortage_allocations')->where('id', 1)->update(['status' => 'PENDING', 'is_confirmed' => false]);
        DB::table('wms_shortage_allocations')->insert(['id' => 2, 'shortage_id' => 1, 'assign_qty' => 2, 'status' => 'FULFILLED', 'is_finished' => true]);
        $before = $this->snapshot(['wms_shortages', 'wms_shortage_allocations']);
        ($this->action('cancelShortage')->getActionFunction())(WmsShortage::findOrFail(1));
        $this->assertSame($before, $this->snapshot(['wms_shortages', 'wms_shortage_allocations']));
        \Filament\Notifications\Notification::assertNotified(
            \Filament\Notifications\Notification::make()->title('エラー')->body('この横持ち出荷依頼は操作できません。一覧を更新してください。')->danger()
        );
    }

    public function test_waiting_form_can_create_a_replacement_without_reviving_the_cancelled_request(): void
    {
        app(ProxyShipmentLifecycleService::class)->cancel(1, 9, '不要');
        $cancelled = (array) DB::table('wms_shortage_allocations')->find(1);
        ($this->action()->getActionFunction())(WmsShortage::findOrFail(1), ['allocations' => [
            ['from_warehouse_id' => 2, 'assign_qty' => 4],
        ]]);
        $new = WmsShortageAllocation::where('id', '!=', 1)->sole();
        $this->assertSame('PENDING', $new->status);
        $this->assertSame(4, $new->assign_qty);
        $this->assertSame('PARTIAL_SHORTAGE', WmsShortage::findOrFail(1)->status);
        $this->assertSame($cancelled, (array) DB::table('wms_shortage_allocations')->find(1));
        $this->assertSame(0, DB::table('quantity_update_queue')->count());
        $this->assertSame(0, DB::table('stock_transfer_queue')->count());
    }

    public function test_waiting_edit_cannot_modify_another_shortages_request(): void
    {
        DB::table('wms_shortages')->insert(['id' => 2, 'trade_id' => 10]);
        DB::table('wms_shortage_allocations')->insert(['id' => 2, 'shortage_id' => 2, 'assign_qty' => 2, 'status' => 'PENDING']);
        $before = $this->snapshot(['wms_shortages', 'wms_shortage_allocations']);
        try {
            ($this->action()->getActionFunction())(WmsShortage::findOrFail(1), ['allocations' => [
                ['id' => 2, 'from_warehouse_id' => 2, 'assign_qty' => 3],
            ]]);
            $this->fail('A different shortage request must be rejected');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            $this->assertSame($before, $this->snapshot(['wms_shortages', 'wms_shortage_allocations']));
        }
    }

    public function test_cancelled_only_approval_quantity_is_zero_and_active_quantity_is_counted(): void
    {
        app(ProxyShipmentLifecycleService::class)->cancel(1, 9, '不要');
        $service = app(ShortageApprovalService::class);
        $service->markPickingResultReadyForShipment(WmsShortage::findOrFail(1));
        $this->assertSame(0, DB::table('wms_picking_item_results')->value('shortage_allocated_qty'));
        DB::table('wms_shortage_allocations')->insert(['id' => 2, 'shortage_id' => 1, 'assign_qty' => 3, 'status' => 'RESERVED']);
        $service->markPickingResultReadyForShipment(WmsShortage::findOrFail(1));
        $this->assertSame(3, DB::table('wms_picking_item_results')->value('shortage_allocated_qty'));
    }
}
