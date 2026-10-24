<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Models\Sakemaru\Item;
use App\Models\WmsStockTransferCandidate;
use App\Services\AutoOrder\TransferCandidateExecutionService;
use App\Services\AutoReplenishment\ReplenishmentGuard;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class TransferOrderUnitGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['database.connections.sakemaru' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']]);
        DB::purge('sakemaru');
        DB::connection('sakemaru')->statement('CREATE TABLE wms_auto_order_run_lines (id INTEGER,transfer_candidate_id INTEGER,quantity_pieces INTEGER,warehouse_id INTEGER,confirmation_snapshot TEXT)');
        $guard = Mockery::mock(ReplenishmentGuard::class);
        $guard->shouldReceive('installed')->andReturnTrue();
        $guard->shouldReceive('assertLine');
        $this->app->instance(ReplenishmentGuard::class, $guard);
    }

    protected function tearDown(): void
    {
        DB::disconnect('sakemaru');
        parent::tearDown();
    }

    private function candidate(string $type = 'CASE', int $qty = 4): WmsStockTransferCandidate
    {
        $candidate = new WmsStockTransferCandidate(['quantity_type' => $type, 'transfer_quantity' => $qty,
            'hub_warehouse_id' => 91, 'satellite_warehouse_id' => 1, 'expected_arrival_date' => '2026-10-24']);
        $candidate->id = 10;
        $candidate->setRelation('item', new Item(['capacity_case' => 24]));

        return $candidate;
    }

    private function line(?string $type = 'CASE'): void
    {
        $snapshot = ['capacity_case' => 24, 'policy' => ['source_warehouse_id' => 91], 'arrival_date' => '2026-10-24'];
        if ($type !== null) {
            $snapshot['quantity_type'] = $type;
        }
        DB::connection('sakemaru')->table('wms_auto_order_run_lines')->insert(['id' => 1, 'transfer_candidate_id' => 10,
            'quantity_pieces' => 96, 'warehouse_id' => 1, 'confirmation_snapshot' => json_encode($snapshot)]);
    }

    private function verify(WmsStockTransferCandidate $candidate, bool $automatic = true): void
    {
        (new \ReflectionMethod(TransferCandidateExecutionService::class, 'assertReplenishmentExecutionAllowed'))
            ->invoke(new TransferCandidateExecutionService, collect([$candidate]), $automatic);
    }

    public function test_automatic_case_quantity_is_checked_against_the_same_total_pieces(): void
    {
        $this->line();
        $this->verify($this->candidate());
        $this->addToAssertionCount(1);
    }

    public function test_old_automatic_piece_snapshots_and_manual_candidates_keep_their_existing_path(): void
    {
        $this->verify($this->candidate(), false);
        $this->line(null);
        $this->verify($this->candidate('PIECE', 96));
        $this->addToAssertionCount(2);
    }

    public function test_changing_case_to_piece_after_confirmation_is_rejected(): void
    {
        $this->line();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('移動候補が変更');
        $this->verify($this->candidate('PIECE', 96));
    }

    public function test_case_capacity_change_after_confirmation_is_rejected(): void
    {
        $this->line();
        $candidate = $this->candidate();
        $candidate->item->capacity_case = 12;
        $this->expectException(\RuntimeException::class);
        $this->verify($candidate);
    }
}
