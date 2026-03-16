<?php

namespace Database\Seeders;

use App\Enums\PickingStrategyType;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsPickingAssignmentStrategy;
use Illuminate\Database\Seeder;

class WmsPickingAssignmentStrategySeeder extends Seeder
{
    public function run(): void
    {
        // 1. 既存の ZONE_PRIORITY レコードを無効化
        WmsPickingAssignmentStrategy::where('strategy_key', 'zone_priority')
            ->update([
                'strategy_key' => PickingStrategyType::EQUAL->value,
                'is_active' => false,
            ]);

        // 2. 全アクティブ倉庫にデフォルト戦略を作成
        $warehouses = Warehouse::where('is_active', true)->get();

        foreach ($warehouses as $warehouse) {
            // デフォルト: 商品数均等割り当て
            WmsPickingAssignmentStrategy::updateOrCreate(
                [
                    'warehouse_id' => $warehouse->id,
                    'strategy_key' => PickingStrategyType::EQUAL->value,
                    'is_default' => true,
                ],
                [
                    'name' => '商品数均等割り当て',
                    'description' => '商品数ベースで均等に配分。配送コース単位でまとめて割り当て。',
                    'parameters' => ['group_by' => 'delivery_course'],
                    'is_active' => true,
                ]
            );

            // スキルレベル考慮割り当て
            WmsPickingAssignmentStrategy::updateOrCreate(
                [
                    'warehouse_id' => $warehouse->id,
                    'strategy_key' => PickingStrategyType::SKILL_BASED->value,
                ],
                [
                    'name' => 'スキルレベル考慮割り当て',
                    'description' => 'スキルレベルに応じて商品数の割り当て比率を調整。',
                    'parameters' => [
                        'group_by' => 'delivery_course',
                        'skill_rates' => PickingStrategyType::defaultSkillRates(),
                    ],
                    'is_default' => false,
                    'is_active' => true,
                ]
            );
        }

        $this->command?->info('ピッカー割り当て戦略を ' . ($warehouses->count() * 2) . ' 件作成しました（' . $warehouses->count() . ' 倉庫）');
    }
}
