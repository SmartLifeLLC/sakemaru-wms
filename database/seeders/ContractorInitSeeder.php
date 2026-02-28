<?php

namespace Database\Seeders;

use App\Enums\AutoOrder\EOrderFileGenerator;
use App\Enums\AutoOrder\TransmissionType;
use App\Models\Sakemaru\Contractor;
use App\Models\WmsContractorSetting;
use App\Models\WmsOrderJxSetting;
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
                ]);
                $updated++;
            } else {
                WmsContractorSetting::create([
                    'contractor_id' => $contractor->id,
                    'transmission_type' => TransmissionType::MANUAL_CSV,
                    'auto_order_generation_time' => self::DEFAULT_AUTO_ORDER_TIME,
                    'transmission_time' => self::DEFAULT_TRANSMISSION_TIME,
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
    }
}
