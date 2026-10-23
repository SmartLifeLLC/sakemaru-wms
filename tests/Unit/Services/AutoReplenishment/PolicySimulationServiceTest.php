<?php

namespace Tests\Unit\Services\AutoReplenishment;

use App\Models\WmsItemReorderPolicy;
use App\Services\AutoReplenishment\LogisticsPickingDate;
use App\Services\AutoReplenishment\PolicySimulationService;
use App\Services\AutoReplenishment\ProposalSafety;
use App\Services\AutoReplenishment\SnapshotReader;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Exceptions;
use Mockery;
use Tests\TestCase;

class PolicySimulationServiceTest extends TestCase
{
    private function policy(array $values = []): WmsItemReorderPolicy
    {
        return new WmsItemReorderPolicy($values + ['channel' => 'EOS', 'strategy_key' => 'threshold_v1',
            'is_enabled' => false, 'version' => 2, 'reorder_point_pieces' => 100, 'min_order_pieces' => 24,
            'max_order_pieces' => 120, 'order_multiple_pieces' => 6]);
    }

    private function snapshot(array $values = []): array
    {
        return $values + ['stock' => 13, 'incoming' => 0, 'claims' => 0, 'packing_unit' => 24,
            'quantity_type' => 'CASE', 'capacity_case' => 24, 'arrival_date' => '2026-10-19',
            'business_date' => '2026-10-18', 'route_snapshot' => null, 'ordering_code' => '0123456789012',
            'jx_route_hash' => 'internal-route-must-not-be-returned'];
    }

    private function calculate(WmsItemReorderPolicy $policy, array $snapshot): array
    {
        $reader = Mockery::mock(SnapshotReader::class);
        $reader->shouldReceive('readForSimulation')->once()->with($policy)->andReturn($snapshot);

        return (new PolicySimulationService($reader, new ProposalSafety, new LogisticsPickingDate))->simulate($policy);
    }

    public function test_off_product_can_be_simulated_using_real_strategy_and_output_units(): void
    {
        $r = $this->calculate($this->policy(), $this->snapshot());
        self::assertSame('READY', $r['phase']);
        self::assertSame(96, $r['quantity_pieces']);
        self::assertSame(4, $r['order_quantity']);
        self::assertSame('CASE', $r['quantity_type']);
        self::assertSame('2026-10-19', $r['arrival_date']);
        self::assertNull($r['picking_date']);
        self::assertStringContainsString('最小公倍数（最小発注単位 6、出力単位 24）＝ 24', implode("\n", $r['formula']));
        self::assertStringContainsString('87 ÷ 24 を切上げ × 24 ＝ 96', implode("\n", $r['formula']));
        self::assertArrayNotHasKey('jx_route_hash', $r);
        self::assertArrayNotHasKey('policy', $r);
    }

    public function test_cap_excess_is_held_without_presenting_a_positive_order(): void
    {
        $r = $this->calculate($this->policy(['max_order_pieces' => 72]), $this->snapshot());
        self::assertSame('HOLD', $r['phase']);
        self::assertSame(96, $r['calculated_pieces']);
        self::assertSame(0, $r['quantity_pieces']);
        self::assertSame(0, $r['order_quantity']);
        self::assertStringContainsString('96 ＞ 最大発注数 72', implode("\n", $r['formula']));
    }

    public function test_arrival_batch_preview_uses_the_same_guard_and_existing_dates(): void
    {
        $r = $this->calculate($this->policy(['strategy_key' => 'arrival_batch_v1',
            'reorder_point_pieces' => 2688, 'target_stock_pieces' => 2688, 'min_order_pieces' => 1728,
            'max_order_pieces' => 1728, 'order_multiple_pieces' => 192,
            'calculation_basis' => ['mean_daily' => 461.3929]]), $this->snapshot([
                'stock' => 2387, 'incoming' => 120, 'business_date' => '2026-10-14', 'arrival_date' => '2026-10-15',
            ]));
        self::assertSame('NO_ORDER', $r['phase']);
        self::assertSame(0, $r['quantity_pieces']);
        self::assertStringContainsString('3774 ＞ 補充目標 2688', implode("\n", $r['formula']));
    }

    public function test_adaptive_preview_shows_target_shortage_cap_and_existing_logistics_dates(): void
    {
        $r = $this->calculate($this->policy(['strategy_key' => 'arrival_adaptive_v1', 'channel' => 'LOGISTICS',
            'reorder_point_pieces' => 6, 'target_stock_pieces' => 10, 'min_order_pieces' => 6,
            'max_order_pieces' => 12, 'order_multiple_pieces' => 1,
            'calculation_basis' => ['mean_daily' => .7143, 'batch_quantity_pieces' => 6]]), $this->snapshot([
                'stock' => -6, 'packing_unit' => 1, 'quantity_type' => 'PIECE', 'route_snapshot' => ['effective_picking_lead_days' => 1],
            ]));
        self::assertSame('READY', $r['phase']);
        self::assertSame(12, $r['quantity_pieces']);
        self::assertSame(12, $r['order_quantity']);
        self::assertSame('2026-10-19', $r['arrival_date']);
        self::assertSame('2026-10-19', $r['picking_date']);
        self::assertStringContainsString('不足数 16 と最大発注数 12', implode('', $r['formula']));
        self::assertStringContainsString('残りの不足 4バラ', implode('', $r['formula']));
    }

    public function test_threshold_equality_does_not_order_even_with_higher_target(): void
    {
        $r = $this->calculate($this->policy(['target_stock_pieces' => 200]), $this->snapshot(['stock' => 100]));
        self::assertSame('NO_ORDER', $r['phase']);
        self::assertSame(0, $r['quantity_pieces']);
        self::assertStringContainsString('100 ≧ 発注点 100', implode("\n", $r['formula']));
    }

    public function test_capped_strategy_shows_required_quantity_separately_from_order_quantity(): void
    {
        $r = $this->calculate($this->policy(['strategy_key' => 'threshold_capped_v1', 'max_order_pieces' => 72]), $this->snapshot());
        self::assertSame('READY', $r['phase']);
        self::assertSame(72, $r['quantity_pieces']);
        self::assertSame(3, $r['order_quantity']);
        self::assertStringContainsString('87 ÷ 24 を切上げ × 24 ＝ 96', implode("\n", $r['formula']));
        self::assertStringContainsString('必要数量 96 ＞ 最大発注数 72', implode("\n", $r['formula']));
        self::assertStringContainsString('次回再判定', implode("\n", $r['formula']));
    }

    public function test_incoming_and_pending_candidates_prevent_reordering(): void
    {
        $r = $this->calculate($this->policy(), $this->snapshot(['incoming' => 30, 'claims' => 60]));
        self::assertSame('NO_ORDER', $r['phase']);
        self::assertSame(0, $r['order_quantity']);
        self::assertStringContainsString('13 ＋ 未入荷残 30 ＋ 未確定候補 60 ＝ 103', implode("\n", $r['formula']));
    }

    public function test_logistics_uses_total_pieces_and_existing_monday_correction(): void
    {
        $r = $this->calculate($this->policy(['channel' => 'LOGISTICS']), $this->snapshot([
            'packing_unit' => 1, 'quantity_type' => 'PIECE', 'route_snapshot' => ['effective_picking_lead_days' => 1],
        ]));
        self::assertSame('READY', $r['phase']);
        self::assertSame(90, $r['order_quantity']);
        self::assertSame('PIECE', $r['quantity_type']);
        self::assertSame('2026-10-19', $r['picking_date']);
        self::assertSame('2026-10-19', $r['arrival_date']);
    }

    public function test_unlinked_stock_is_held_with_reason_and_no_invented_dates(): void
    {
        $reader = Mockery::mock(SnapshotReader::class);
        $reader->shouldReceive('readForSimulation')->once()->andThrow(new \RuntimeException('在庫データが未連携です。'));
        $reader->shouldReceive('lastObservation')->once()->andReturn([]);
        $r = (new PolicySimulationService($reader, new ProposalSafety, new LogisticsPickingDate))->simulate($this->policy());
        self::assertSame('HOLD', $r['phase']);
        self::assertSame('在庫データが未連携です。', $r['reason']);
        self::assertNull($r['arrival_date']);
        self::assertSame([], $r['formula']);
    }

    public function test_sql_error_is_not_exposed_to_operator(): void
    {
        Exceptions::fake();
        $error = new QueryException('sakemaru', 'select secret from sensitive_table', [], new \PDOException('internal detail'));
        $reader = Mockery::mock(SnapshotReader::class);
        $reader->shouldReceive('readForSimulation')->once()->andThrow($error);
        $reader->shouldReceive('lastObservation')->once()->andReturn([]);
        $r = (new PolicySimulationService($reader, new ProposalSafety, new LogisticsPickingDate))->simulate($this->policy());
        self::assertSame('HOLD', $r['phase']);
        self::assertStringNotContainsString('secret', $r['reason']);
        self::assertStringContainsString('管理者に連絡', $r['reason']);
        Exceptions::assertReported(QueryException::class);
    }
}
