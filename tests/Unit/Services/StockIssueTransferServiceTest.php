<?php

namespace Tests\Unit\Services;

use App\Enums\QuantityType;
use App\Enums\StockIssueReason;
use App\Services\StockIssueTransferService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StockIssueTransferServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::connection('sakemaru')->beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::connection('sakemaru')->rollBack();

        parent::tearDown();
    }

    public function test_it_creates_stock_transfer_queue_for_stock_issue(): void
    {
        $connection = DB::connection('sakemaru');
        $issueWarehouseCode = (string) (config('wms.stock_issue.warehouse_code') ?: '999');

        $warehouseId = $connection->table('warehouses')
            ->where('is_active', true)
            ->where('code', '!=', $issueWarehouseCode)
            ->value('id');
        $itemId = $connection->table('items')
            ->where('is_active', true)
            ->value('id');

        if (! $warehouseId || ! $itemId) {
            $this->markTestSkipped('Active warehouse and item master data are required.');
        }

        $result = app(StockIssueTransferService::class)->create([
            'from_warehouse_id' => (int) $warehouseId,
            'item_id' => (int) $itemId,
            'quantity' => 1,
            'quantity_type' => QuantityType::PIECE->value,
            'reason' => StockIssueReason::DAMAGED->value,
            'process_date' => now()->toDateString(),
            'note' => 'test',
        ], 1);

        $queue = $connection->table('stock_transfer_queue')
            ->where('id', $result['queue_id'])
            ->first();

        $this->assertNotNull($queue);
        $this->assertSame('CREATE', $queue->action_type);
        $this->assertSame('BEFORE', $queue->status);
        $this->assertSame($issueWarehouseCode, (string) $queue->to_warehouse_code);
        $this->assertStringContainsString('理由:破損', $queue->note);
        $items = json_decode($queue->items, true);
        $this->assertSame(QuantityType::PIECE->value, $items[0]['quantity_type']);
    }
}
