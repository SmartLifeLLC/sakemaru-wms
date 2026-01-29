<?php

namespace Tests\Unit\Services;

use App\Models\WmsStockTransferCandidate;
use App\Services\AutoOrder\TransferOrderRecalculationService;
use Mockery;
use Tests\TestCase;

class TransferOrderRecalculationServiceTest extends TestCase
{
    protected TransferOrderRecalculationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TransferOrderRecalculationService;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * @test
     */
    public function round_up_to_unit_rounds_correctly(): void
    {
        $reflection = new \ReflectionClass($this->service);
        $method = $reflection->getMethod('roundUpToUnit');
        $method->setAccessible(true);

        // 単位1の場合はそのまま
        $this->assertEquals(100, $method->invoke($this->service, 100, 1));

        // 切り上げが必要な場合
        $this->assertEquals(36, $method->invoke($this->service, 25, 12)); // 25 / 12 = 2.08... -> 3 * 12 = 36
        $this->assertEquals(24, $method->invoke($this->service, 24, 12)); // 24 / 12 = 2 -> 2 * 12 = 24
        $this->assertEquals(24, $method->invoke($this->service, 13, 12)); // 13 / 12 = 1.08... -> 2 * 12 = 24

        // 0以下の場合
        $this->assertEquals(0, $method->invoke($this->service, 0, 12));
        $this->assertEquals(0, $method->invoke($this->service, -5, 12));
    }

    /**
     * @test
     */
    public function recalculate_order_for_transfer_returns_null_when_quantity_unchanged(): void
    {
        // モック移動候補を作成
        $transfer = Mockery::mock(WmsStockTransferCandidate::class)->makePartial();
        $transfer->batch_code = '20260128120000';
        $transfer->hub_warehouse_id = 1;
        $transfer->item_id = 100;
        $transfer->transfer_quantity = 50;

        // 同じ数量で呼び出し
        $result = $this->service->recalculateOrderForTransfer($transfer, 50, 50);

        $this->assertNull($result);
    }

    /**
     * @test
     */
    public function build_demand_breakdown_includes_self_shortage_when_positive(): void
    {
        // サービスを部分的にモック
        $service = Mockery::mock(TransferOrderRecalculationService::class)
            ->makePartial()
            ->shouldAllowMockingProtectedMethods();

        // 空の移動候補コレクションを返すように設定
        $service->shouldReceive('calculateSatelliteDemand')
            ->andReturn(0);

        // buildDemandBreakdownのテスト用にモック設定（移動候補がない場合）
        $breakdown = $this->service->buildDemandBreakdown(
            '20260128120000',
            1,
            100,
            50 // 自倉庫不足
        );

        // 自倉庫不足のみ含まれる
        $this->assertCount(1, $breakdown);
        $this->assertEquals(1, $breakdown[0]['warehouse_id']);
        $this->assertEquals(50, $breakdown[0]['quantity']);
    }

    /**
     * @test
     */
    public function build_demand_breakdown_excludes_self_shortage_when_zero(): void
    {
        $breakdown = $this->service->buildDemandBreakdown(
            '20260128120000',
            1,
            100,
            0 // 自倉庫不足なし
        );

        // 移動候補がないので空配列（自倉庫不足0は含まない）
        $this->assertCount(0, $breakdown);
    }
}
