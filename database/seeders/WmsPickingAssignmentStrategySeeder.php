<?php

namespace Database\Seeders;

use App\Enums\PickingStrategyType;
use App\Models\WmsPickingAssignmentStrategy;
use Illuminate\Database\Seeder;

class WmsPickingAssignmentStrategySeeder extends Seeder
{
    public function run(): void
    {
        $warehouseId = 991;

        $strategies = [
            [
                'warehouse_id' => $warehouseId,
                'name' => '[991] 標準均等割り当て',
                'description' => 'タスクを作業者に均等に配分する標準的な戦略です。',
                'strategy_key' => PickingStrategyType::EQUAL->value,
                'parameters' => null,
                'is_default' => true,
                'is_active' => true,
            ],
            [
                'warehouse_id' => $warehouseId,
                'name' => '[991] 新人制限モード',
                'description' => '新人作業者への割り当て数を制限するモードです。',
                'strategy_key' => PickingStrategyType::EQUAL->value,
                'parameters' => ['max_orders_per_picker' => 20],
                'is_default' => false,
                'is_active' => true,
            ],
            [
                'warehouse_id' => $warehouseId,
                'name' => '[991] 冷凍エリア優先',
                'description' => '冷凍エリアのタスクを優先的に割り当てます。',
                'strategy_key' => PickingStrategyType::ZONE_PRIORITY->value,
                'parameters' => ['target_zone' => 'FROZEN'],
                'is_default' => false,
                'is_active' => true,
            ],
        ];

        foreach ($strategies as $strategy) {
            WmsPickingAssignmentStrategy::updateOrCreate(
                [
                    'warehouse_id' => $strategy['warehouse_id'],
                    'name' => $strategy['name'],
                ],
                $strategy
            );
        }
    }
}
