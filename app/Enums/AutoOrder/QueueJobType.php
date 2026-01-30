<?php

namespace App\Enums\AutoOrder;

enum QueueJobType: string
{
    case ORDER_CREATE = 'order_create';
    case TRANSFER_CREATE = 'transfer_create';
    case DEMAND_DISTRIBUTION = 'demand_distribution';

    public function label(): string
    {
        return match ($this) {
            self::ORDER_CREATE => '発注候補作成',
            self::TRANSFER_CREATE => '移動候補作成',
            self::DEMAND_DISTRIBUTION => '需要分配',
        };
    }
}
