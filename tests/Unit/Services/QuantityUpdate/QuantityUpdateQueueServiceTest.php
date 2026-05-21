<?php

namespace Tests\Unit\Services\QuantityUpdate;

use App\Models\WmsShortage;
use App\Services\QuantityUpdate\QuantityUpdateQueueService;
use Tests\TestCase;

class QuantityUpdateQueueServiceTest extends TestCase
{
    public function test_shortage_approval_does_not_create_quantity_update_queue(): void
    {
        $shortage = new WmsShortage([
            'trade_id' => 16430,
            'trade_item_id' => 173922,
            'order_qty' => 4,
            'planned_qty' => 0,
            'picked_qty' => 0,
            'shortage_qty' => 4,
            'qty_type_at_order' => 'PIECE',
            'source_pick_result_id' => 20798,
        ]);
        $shortage->id = 1323;

        $queue = (new QuantityUpdateQueueService)->createQueueForShortageApproval($shortage);

        $this->assertNull($queue);
    }
}
