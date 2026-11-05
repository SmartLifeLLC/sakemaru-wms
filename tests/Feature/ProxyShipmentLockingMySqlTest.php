<?php

namespace Tests\Feature;

use App\Filament\Resources\WmsShortagesWaitingApprovals\Tables\WmsShortagesWaitingApprovalsTable;
use App\Models\WmsShortage;
use App\Models\WmsShortageAllocation;
use App\Services\Shortage\ProxyShipmentLifecycleService;
use App\Services\Shortage\ProxyShipmentService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class ProxyShipmentLockingMySqlTest extends TestCase
{
    private ?string $prefix = null;

    private array $ownedTables = [];

    private array $workers = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('RUN_PROXY_SHIPMENT_MYSQL_TESTS') !== '1') {
            $this->markTestSkipped('Opt-in: RUN_PROXY_SHIPMENT_MYSQL_TESTS=1; dedicated local MySQL test DB only.');
        }
        $connection = config('database.connections.sakemaru');
        $this->assertSame('mysql', $connection['driver']);
        $this->assertContains($connection['host'], ['127.0.0.1', 'localhost', '::1']);
        $this->assertStringContainsString('test', strtolower($connection['database']));
        $this->prefix = 'codex_proxy_lock_'.bin2hex(random_bytes(6)).'_';
        config(['database.connections.sakemaru.prefix' => $this->prefix, 'app.client_id' => 6]);
        DB::purge('sakemaru');
        $this->createOwnedTable('wms_shortages', function (Blueprint $t) {
            $t->id();
            $t->integer('item_id')->default(1);
            $t->integer('warehouse_id')->default(1);
            $t->integer('trade_item_id')->default(1);
            $t->integer('trade_id')->default(1);
            $t->date('shipment_date')->nullable();
            $t->integer('delivery_course_id')->nullable();
            $t->integer('shortage_qty')->default(6);
            $t->string('qty_type_at_order')->default('PIECE');
            $t->string('status')->default('REALLOCATING');
            $t->boolean('is_confirmed')->default(false);
            $t->integer('updater_id')->nullable();
            $t->timestamps();
        });
        $this->createOwnedTable('wms_shortage_allocations', function (Blueprint $t) {
            $t->id();
            $t->integer('shortage_id')->index('shortage_idx');
            $t->integer('target_warehouse_id')->default(2);
            $t->integer('source_warehouse_id')->default(1);
            $t->date('shipment_date')->nullable();
            $t->integer('delivery_course_id')->nullable();
            $t->integer('assign_qty')->default(6);
            $t->integer('picked_qty')->default(0);
            $t->string('assign_qty_type')->default('PIECE');
            $t->string('status')->default('PENDING');
            $t->boolean('is_finished')->default(false);
            $t->boolean('is_confirmed')->default(false);
            $t->decimal('purchase_price')->nullable();
            $t->decimal('tax_exempt_price')->nullable();
            $t->decimal('price')->nullable();
            $t->timestamp('cancelled_at')->nullable();
            $t->integer('cancelled_user_id')->nullable();
            $t->string('cancel_reason', 500)->nullable();
            $t->string('cancel_previous_status')->nullable();
            $t->integer('created_by')->nullable();
            $t->integer('updated_by')->nullable();
            $t->timestamps();
        });
        $this->createOwnedTable('trades', function (Blueprint $t) {
            $t->id();
            $t->integer('client_id')->default(6);
            $t->boolean('is_active')->default(true);
            $t->boolean('is_latest')->default(true);
        });
        $this->createOwnedTable('stock_transfer_queue', function (Blueprint $t) {
            $t->id();
            $t->string('request_id');
            $t->string('action_type');
            $t->integer('stock_transfer_id')->nullable();
        });
        $this->createOwnedTable('trade_items', function (Blueprint $t) {
            $t->id();
            $t->decimal('price')->nullable();
        });
        foreach (['item_partner_prices', 'item_prices'] as $name) {
            $this->createOwnedTable($name, function (Blueprint $t) {
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
        DB::connection('sakemaru')->table('wms_shortages')->insert(['id' => 1]);
        DB::connection('sakemaru')->table('trades')->insert(['id' => 1]);
        DB::connection('sakemaru')->table('trade_items')->insert(['id' => 1, 'price' => 100]);
        DB::connection('sakemaru')->table('wms_shortage_allocations')->insert(['id' => 1, 'shortage_id' => 1]);
    }

    private function createOwnedTable(string $name, callable $definition): void
    {
        $this->assertFalse(Schema::connection('sakemaru')->hasTable($name));
        $this->ownedTables[] = $name;
        Schema::connection('sakemaru')->create($name, $definition);
    }

    protected function tearDown(): void
    {
        try {
            if ($this->prefix !== null) {
                while (DB::connection('sakemaru')->transactionLevel() > 0) {
                    DB::connection('sakemaru')->rollBack();
                }
                foreach ($this->workers as $worker) {
                    $worker->stop(1);
                }
                // Only these random-prefix tables were created by this test; never migrate/reset.
                foreach (array_reverse($this->ownedTables) as $name) {
                    Schema::connection('sakemaru')->dropIfExists($name);
                }
                DB::purge('sakemaru');
            }
        } finally {
            parent::tearDown();
        }
    }

    private function startCompetingCreation(int $warehouseId = 2): Process
    {
        $process = new Process([PHP_BINARY, base_path('tests/Support/proxy_shipment_lock_worker.php'), $this->prefix, (string) $warehouseId], base_path(), [
            'APP_ENV' => 'testing', 'APP_CONFIG_CACHE' => sys_get_temp_dir().'/'.$this->prefix.'no-config.php',
        ]);
        $process->setTimeout(20);
        $this->workers[] = $process;
        $process->start();
        $deadline = microtime(true) + 5;
        while (! str_contains($process->getOutput(), 'locking-business-row') && $process->isRunning() && microtime(true) < $deadline) {
            usleep(10000);
        }
        $this->assertStringContainsString('locking-business-row', $process->getOutput(), $process->getErrorOutput());
        $this->assertTrue($process->isRunning(), $process->getErrorOutput());

        return $process;
    }

    private function finishWorker(Process $worker): int
    {
        $this->assertSame(0, $worker->wait(), $worker->getErrorOutput());
        $this->assertSame(1, preg_match('/result:(\d+)/', $worker->getOutput(), $matches));

        return (int) $matches[1];
    }

    public function test_waiting_edit_can_create_while_another_creator_waits_on_its_rows(): void
    {
        DB::connection('sakemaru')->beginTransaction();
        WmsShortage::whereKey(1)->lockForUpdate()->firstOrFail();
        WmsShortageAllocation::where('shortage_id', 1)->lockForUpdate()->get();
        $worker = $this->startCompetingCreation();
        $component = \Mockery::mock(\Filament\Tables\Contracts\HasTable::class);
        $action = WmsShortagesWaitingApprovalsTable::configure(\Filament\Tables\Table::make($component))->getAction('editProxyShipment');
        // Regression: formerly attempts GET_LOCK held by the other creator and times out.
        ($action->getActionFunction())(WmsShortage::findOrFail(1), ['allocations' => [
            ['from_warehouse_id' => 2, 'assign_qty' => 3],
        ]]);
        $this->assertSame(3, WmsShortageAllocation::findOrFail(1)->assign_qty);
        $this->assertTrue($worker->isRunning());
        DB::connection('sakemaru')->commit();
        $this->assertSame(1, $this->finishWorker($worker));
        $this->assertSame(1, WmsShortageAllocation::count());
        $this->assertSame(4, WmsShortageAllocation::findOrFail(1)->assign_qty);
    }

    public function test_new_request_stays_serialized_until_outer_transaction_commit(): void
    {
        DB::connection('sakemaru')->beginTransaction();
        $created = app(ProxyShipmentService::class)->createProxyShipment(WmsShortage::findOrFail(1), 3, 3, 'PIECE', 9);
        $worker = $this->startCompetingCreation(3);
        $this->assertTrue($worker->isRunning());
        DB::connection('sakemaru')->commit();
        $this->assertSame($created->id, $this->finishWorker($worker));
        $this->assertSame(1, WmsShortageAllocation::where('target_warehouse_id', 3)->count());
    }

    public function test_update_and_create_use_the_same_parent_lock(): void
    {
        DB::connection('sakemaru')->beginTransaction();
        app(ProxyShipmentService::class)->updateProxyShipment(WmsShortageAllocation::findOrFail(1), 2, 3, 9);
        $worker = $this->startCompetingCreation();
        DB::connection('sakemaru')->commit();
        $this->assertSame(1, $this->finishWorker($worker));
        $this->assertSame(4, WmsShortageAllocation::findOrFail(1)->assign_qty);
    }

    public function test_delete_and_create_are_serialized_without_losing_the_replacement(): void
    {
        DB::connection('sakemaru')->beginTransaction();
        app(ProxyShipmentService::class)->deleteProxyShipment(WmsShortageAllocation::findOrFail(1));
        $worker = $this->startCompetingCreation();
        DB::connection('sakemaru')->commit();
        $id = $this->finishWorker($worker);
        $this->assertNotSame(1, $id);
        $this->assertSame(1, WmsShortageAllocation::count());
        $this->assertSame(4, WmsShortageAllocation::findOrFail($id)->assign_qty);
    }

    public function test_cancellation_and_replacement_creation_preserve_the_cancelled_row(): void
    {
        DB::connection('sakemaru')->beginTransaction();
        app(ProxyShipmentLifecycleService::class)->cancel(1, 9, '横持ち不要');
        $cancelled = WmsShortageAllocation::findOrFail(1)->getRawOriginal();
        $worker = $this->startCompetingCreation();
        DB::connection('sakemaru')->commit();
        $id = $this->finishWorker($worker);
        $this->assertNotSame(1, $id);
        $this->assertSame($cancelled, WmsShortageAllocation::findOrFail(1)->getRawOriginal());
        $this->assertSame('PENDING', WmsShortageAllocation::findOrFail($id)->status);
        $this->assertSame(0, DB::connection('sakemaru')->table('stock_transfer_queue')->count());
    }
}
