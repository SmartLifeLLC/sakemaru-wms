<?php

namespace Database\Seeders;

use App\Enums\PickerSkillLevel;
use App\Models\Sakemaru\Client;
use App\Models\Sakemaru\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * 初期ピッカー生成シーダー
 *
 * 10人のピッカーを生成します。
 * - コード: 1〜10
 * - パスワード: 1〜10（コードと同じ）
 * - スキルレベル: 全員 SENIOR（熟練）
 */
class InitialPickerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('初期ピッカーを生成しています...');

        $createdCount = 0;
        $skippedCount = 0;

        for ($i = 1; $i <= 10; $i++) {
            $code = (string) $i;

            // 既存チェック
            $exists = DB::connection('sakemaru')
                ->table('wms_pickers')
                ->where('code', $code)
                ->exists();

            if ($exists) {
                $skippedCount++;
                $this->command->warn("ピッカー [{$code}] は既に存在します。スキップします。");

                continue;
            }

            $warehouseId = Warehouse::where('code', Client::first()->default_warehouse_code)->first()->id;
            DB::connection('sakemaru')->table('wms_pickers')->insert([
                'code' => $code,
                'name' => "ピッカー{$i}",
                'password' => Hash::make($code),
                'default_warehouse_id' => $warehouseId,
                'skill_level' => PickerSkillLevel::SENIOR->value,
                'can_access_restricted_area' => false,
                'is_active' => true,
                'is_available_for_picking' => false,
                'current_warehouse_id' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $createdCount++;
        }

        $this->command->info("初期ピッカー生成完了: 作成 {$createdCount}件, スキップ {$skippedCount}件");
    }
}
