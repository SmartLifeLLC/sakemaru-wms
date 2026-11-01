<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Models\WmsAutoOrderRun;
use App\Models\WmsItemReorderPolicy;
use App\Services\AutoReplenishment\ArtifactRecorder;
use App\Services\AutoReplenishment\BudgetService;
use App\Services\AutoReplenishment\ExecutionService;
use App\Services\AutoReplenishment\ReplenishmentGuard;
use App\Services\AutoReplenishment\SnapshotReader;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class PositionExecutionTest extends TestCase
{
    public function test_prepare_is_idempotent_and_confirmation_rechecks_new_manual_supply_before_any_writer(): void
    {
        config(['database.connections.sakemaru' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('sakemaru');
        $db = DB::connection('sakemaru');
        $db->statement('CREATE TABLE wms_auto_order_run_lines (id INTEGER PRIMARY KEY AUTOINCREMENT, run_id INTEGER, policy_id INTEGER, effect_key TEXT, client_id INTEGER, warehouse_id INTEGER, item_id INTEGER, channel TEXT, phase TEXT, reason TEXT, quantity_pieces INTEGER, policy_version INTEGER, warehouse_version INTEGER, proposal_snapshot TEXT, order_candidate_id INTEGER, transfer_candidate_id INTEGER, created_at TEXT, updated_at TEXT)');
        $db->statement('CREATE TABLE wms_warehouse_auto_order_settings (warehouse_id INTEGER, replenishment_version INTEGER)');
        $db->statement('CREATE TABLE wms_item_reorder_policies (id INTEGER PRIMARY KEY, client_id INTEGER, warehouse_id INTEGER, item_id INTEGER, channel TEXT, strategy_key TEXT, reorder_point_pieces INTEGER, target_stock_pieces INTEGER, min_order_pieces INTEGER, max_order_pieces INTEGER, order_multiple_pieces INTEGER, calculation_basis TEXT, version INTEGER, created_at TEXT, updated_at TEXT)');
        foreach (['EOS', 'LOGISTICS'] as $i => $channel) {
            $policy = WmsItemReorderPolicy::create(PositionNormalBatchStrategyTest::policy(['channel' => $channel]) + [
                'id' => $i + 1, 'client_id' => 6, 'warehouse_id' => 1, 'item_id' => $i + 1, 'version' => 1]);
            $before = PositionNormalBatchStrategyTest::snapshot();
            $after = PositionNormalBatchStrategyTest::snapshot(['incoming' => 100, 'supply_events' => [['date' => '2026-11-03', 'pieces' => 100]]]);
            $reader = Mockery::mock(SnapshotReader::class);
            $reader->shouldReceive('read')->with(Mockery::type(WmsItemReorderPolicy::class))->once()->andReturn($before);
            $reader->shouldReceive('read')->with(Mockery::type(WmsItemReorderPolicy::class), Mockery::type('int'))->once()->andReturn($after);
            $guard = Mockery::mock(ReplenishmentGuard::class);
            $guard->shouldReceive('assertLine')->once();
            $guard->shouldReceive('lockScope')->with(6, 1, $i + 1)->once();
            $service = new ExecutionService($guard, $reader, Mockery::mock(BudgetService::class), Mockery::mock(ArtifactRecorder::class));
            $run = new WmsAutoOrderRun(['id' => $i + 1, 'client_id' => 6, 'warehouse_id' => 1]);
            $line = $service->prepare($run, $policy);
            self::assertSame('READY', $line->phase);
            self::assertSame(96, (int) $line->quantity_pieces);
            self::assertSame(100, $line->proposal_snapshot['calculation']['decision_inventory']['position']);
            self::assertSame($line->id, $service->prepare($run, $policy)->id);
            try {
                $service->execute($line, false);
                self::fail('Changed supply must invalidate the prepared order.');
            } catch (\RuntimeException $e) {
                self::assertSame('在庫・供給が変わったため再計算が必要です。', $e->getMessage());
            }
            self::assertNull($line->refresh()->order_candidate_id);
            self::assertNull($line->transfer_candidate_id);
        }
        self::assertSame(2, $db->table('wms_auto_order_run_lines')->count());
        $db->disconnect();
    }
}
