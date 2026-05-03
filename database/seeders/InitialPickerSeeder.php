<?php

namespace Database\Seeders;

use App\Enums\PickerSkillLevel;
use App\Models\Sakemaru\Warehouse;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class InitialPickerSeeder extends Seeder
{
    public function run(): void
    {
        $createdCount = 0;
        $skippedCount = 0;

        // --- オレンジ倉庫ピッカー（1〜10） ---
        $this->command->info('オレンジ倉庫ピッカーを生成しています...');
        for ($i = 9001; $i <= 9010; $i++) {
            $code = (string) $i;

            if ($this->pickerExists($code)) {
                $skippedCount++;
                $this->command->warn("ピッカー [{$code}] は既に存在します。スキップします。");

                continue;
            }

            $this->insertPicker($code, "オレンジピッカー{$i}", $code, 91);
            $createdCount++;
        }

        // --- CSV店舗ピッカー ---
        $this->command->info('CSVから店舗ピッカーを生成しています...');
        $csvPath = storage_path('seeders/store_picker_warehouses.csv');
        if (! file_exists($csvPath)) {
            $this->command->warn("CSVファイルが見つかりません: {$csvPath} — スキップします。");
            $this->command->info("ピッカー生成完了: 作成 {$createdCount}件, スキップ {$skippedCount}件");

            return;
        }

        $warehouseMap = Warehouse::pluck('id', 'code')->toArray();

        $handle = fopen($csvPath, 'r');
        fgetcsv($handle);

        while (($row = fgetcsv($handle)) !== false) {
            $code = trim($row[3] ?? '');
            $name = trim($row[2] ?? '');
            $password = trim($row[4] ?? '');
            $warehouseCode = trim($row[6] ?? '');

            if (! $code || ! $name) {
                continue;
            }

            $warehouseId = $warehouseMap[$warehouseCode] ?? null;
            if (! $warehouseId) {
                $this->command->warn("倉庫コード [{$warehouseCode}] が見つかりません。ピッカー [{$code}] {$name} をスキップします。");
                $skippedCount++;

                continue;
            }

            if ($this->pickerExists($code)) {
                $skippedCount++;
                $this->command->warn("ピッカー [{$code}] {$name} は既に存在します。スキップします。");

                continue;
            }

            $this->insertPicker($code, $name, $password ?: $code, $warehouseId);
            $createdCount++;
        }

        fclose($handle);

        $this->command->info("ピッカー生成完了: 作成 {$createdCount}件, スキップ {$skippedCount}件");
    }

    private function pickerExists(string $code): bool
    {
        return DB::connection('sakemaru')
            ->table('wms_pickers')
            ->where('code', $code)
            ->exists();
    }

    private function insertPicker(string $code, string $name, string $password, int $warehouseId): void
    {
        DB::connection('sakemaru')->table('wms_pickers')->insert([
            'code' => $code,
            'name' => $name,
            'password' => Hash::make($password),
            'default_warehouse_id' => $warehouseId,
            'skill_level' => PickerSkillLevel::SENIOR->value,
            'can_access_restricted_area' => false,
            'is_active' => true,
            'is_available_for_picking' => true,
            'current_warehouse_id' => $warehouseId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
