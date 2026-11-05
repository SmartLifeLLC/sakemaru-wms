<?php

namespace Tests\Unit;

use App\Services\Distribution\DistributionBatchService;
use PHPUnit\Framework\TestCase;

class DistributionBatchStatusTest extends TestCase
{
    public function test_receipt_processing_success_failure_and_partial_states_are_distinct(): void
    {
        $rows = [['warehouseTransferQueueIds' => [1]]];
        self::assertSame('未生成', DistributionBatchService::status([['alloc_01' => 12]], []));
        self::assertSame('要確認', DistributionBatchService::status($rows, []));
        self::assertSame('生成中', DistributionBatchService::status($rows, [1 => (object) ['status' => 'BEFORE']]));
        $queues = [1 => (object) ['status' => 'FINISHED', 'is_success' => 1, 'stock_transfer_id' => 33]];
        self::assertSame('生成済', DistributionBatchService::status($rows, $queues));
        self::assertSame('一部生成済', DistributionBatchService::status([...$rows, []], $queues));
        $queues[1]->is_success = 0;
        self::assertSame('要確認', DistributionBatchService::status($rows, $queues));
        self::assertFalse(DistributionBatchService::rowLocked(['warehouseTransferGenerated' => 'false']));
    }
}
