<?php

namespace Database\Seeders;

use App\Enums\AutoOrder\ConfirmationLevel;
use App\Enums\AutoOrder\EOrderFileGenerator;
use App\Enums\AutoOrder\TransmissionType;
use App\Models\Sakemaru\Contractor;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsContractorSetting;
use App\Models\WmsContractorWarehouseSetting;
use App\Models\WmsOrderJxSetting;
use App\Models\WmsWarehouseAutoOrderSetting;
use Illuminate\Database\Seeder;

/**
 * 発注先時刻初期設定シーダー
 *
 * 全発注先に対してデフォルトの自動発注生成時刻・送信時刻を設定する。
 * 送信方式がJX-FINETの場合は異なる時刻を適用する。
 *
 * 実行方法:
 *   php artisan db:seed --class=ContractorInitSeeder
 */
class ContractorInitSeeder extends Seeder
{
    private const DEFAULT_AUTO_ORDER_TIME = '09:30';

    private const DEFAULT_TRANSMISSION_TIME = '10:30';

    private const JX_FINET_AUTO_ORDER_TIME = '11:00';

    private const JX_FINET_TRANSMISSION_TIME = '12:00';

    public function run(): void
    {
        $contractors = Contractor::all();
        $created = 0;
        $updated = 0;

        foreach ($contractors as $contractor) {
            $setting = WmsContractorSetting::where('contractor_id', $contractor->id)->first();

            if ($setting) {
                $isJxFinet = $setting->transmission_type === TransmissionType::JX_FINET;

                $setting->update([
                    'auto_order_generation_time' => $isJxFinet ? self::JX_FINET_AUTO_ORDER_TIME : self::DEFAULT_AUTO_ORDER_TIME,
                    'transmission_time' => $isJxFinet ? self::JX_FINET_TRANSMISSION_TIME : self::DEFAULT_TRANSMISSION_TIME,
                    'is_auto_transmission' => $isJxFinet,
                ]);
                $updated++;
            } else {
                WmsContractorSetting::create([
                    'contractor_id' => $contractor->id,
                    'transmission_type' => TransmissionType::MANUAL_CSV,
                    'auto_order_generation_time' => self::DEFAULT_AUTO_ORDER_TIME,
                    'transmission_time' => self::DEFAULT_TRANSMISSION_TIME,
                    'is_auto_transmission' => false,
                ]);
                $created++;
            }
        }

        $this->command->info("発注時刻設定完了: 新規={$created}, 更新={$updated}");

        // JX設定に対してgeneratorを設定（MB65D7のみHANA2、それ以外はHANA）
        $jxSettings = WmsOrderJxSetting::where('is_active', true)->get();
        $jxUpdated = 0;
        foreach ($jxSettings as $jxSetting) {
            $generator = $jxSetting->receiver_station_code === 'MB65D7'
                ? EOrderFileGenerator::HANA2
                : EOrderFileGenerator::HANA;

            $jxSetting->update([
                'order_file_generator' => $generator,
            ]);
            $jxUpdated++;
        }
        $this->command->info("JX設定generator設定: {$jxUpdated}件");

        // 倉庫別確定レベル設定
        $this->seedWarehouseSettings();

        // 納入先指定コード設定（既存データ削除→再作成）
        $this->seedDesignatedCodes();
    }

    /**
     * 倉庫別の確定レベル設定
     *
     * 全倉庫: STATUS1（候補表示のみ）
     */
    private function seedWarehouseSettings(): void
    {
        $warehouses = Warehouse::all();
        $created = 0;
        $updated = 0;

        foreach ($warehouses as $warehouse) {
            $level = ConfirmationLevel::STATUS1;

            $existing = WmsWarehouseAutoOrderSetting::where('warehouse_id', $warehouse->id)->first();

            if ($existing) {
                $existing->update(['confirmation_level' => $level]);
                $updated++;
            } else {
                WmsWarehouseAutoOrderSetting::create([
                    'warehouse_id' => $warehouse->id,
                    'confirmation_level' => $level,
                ]);
                $created++;
            }
        }

        $this->command->info("倉庫別確定レベル設定完了: 新規={$created}, 更新={$updated}");
    }

    /**
     * 納入先指定コード設定（init-contractors.md より）
     *
     * 既存の wms_contractor_warehouse_settings を全削除後、
     * 発注先1266の倉庫別納入先指定コードのみ再作成する。
     */
    private function seedDesignatedCodes(): void
    {
        // 既存データを全削除
        $deleted = WmsContractorWarehouseSetting::query()->delete();
        $this->command->info("発注先×倉庫設定: 既存{$deleted}件を削除");

        // 納入先指定コードマッピング（init-contractors.md より）
        // contractor_id=1266, [warehouse_code => designated_code]
        $designatedCodeMap = [
            '91' => '4540',
            '1' => '4811',
            '7' => '4817',
            '8' => '4818',
            '3' => '4813',
            '11' => '4815',
            '4' => '4814',
            '9' => '4819',
            '10' => '9892',
            '21' => '2745',
            '22' => '5358',
        ];

        $contractorId = 1266;
        $warehouses = Warehouse::all()->keyBy('code');
        $created = 0;

        foreach ($designatedCodeMap as $warehouseCode => $designatedCode) {
            $warehouse = $warehouses[$warehouseCode] ?? null;
            if (! $warehouse) {
                $this->command->warn("倉庫コード {$warehouseCode} が見つかりません、スキップ");

                continue;
            }

            WmsContractorWarehouseSetting::create([
                'warehouse_id' => $warehouse->id,
                'contractor_id' => $contractorId,
                'designated_code' => $designatedCode,
            ]);
            $created++;
        }

        $this->command->info("納入先指定コード設定完了: {$created}件（発注先ID={$contractorId}）");
    }
}
