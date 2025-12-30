<?php

namespace App\Services;

use App\Models\Wave;
use App\Models\WaveSetting;
use Illuminate\Support\Facades\DB;

/**
 * Wave管理サービス
 *
 * Waveの検索、生成、管理を行うサービスクラス
 */
class WaveService
{
    /**
     * 既存Waveを検索
     *
     * @param  int  $warehouseId  倉庫ID
     * @param  int  $deliveryCourseId  配送コースID
     * @param  string  $shippingDate  出荷日（Y-m-d形式）
     */
    public function findExistingWave(int $warehouseId, int $deliveryCourseId, string $shippingDate): ?Wave
    {
        return Wave::whereHas('waveSetting', function ($query) use ($warehouseId, $deliveryCourseId) {
            $query->where('warehouse_id', $warehouseId)
                ->where('delivery_course_id', $deliveryCourseId);
        })
            ->where('shipping_date', $shippingDate)
            ->first();
    }

    /**
     * Waveを取得または生成
     *
     * 既存Waveがあればそれを返し、なければ新規生成する
     * 現在時刻の時間帯（例：14:10 → 14:00）に対応するwave_settingを優先的に使用
     *
     * @param  int  $warehouseId  倉庫ID
     * @param  int  $deliveryCourseId  配送コースID
     * @param  string  $shippingDate  出荷日（Y-m-d形式）
     */
    public function getOrCreateWave(int $warehouseId, int $deliveryCourseId, string $shippingDate): Wave
    {
        // 1. 現在時刻の時間帯のwave_settingを取得
        // 例：14:10:00 → 14:00:00 の時間帯を探す
        $currentHour = now()->format('H');
        $currentHourStartTime = sprintf('%02d:00:00', $currentHour);

        // 2. 該当時間帯のwave_settingを検索
        $waveSetting = WaveSetting::where('warehouse_id', $warehouseId)
            ->where('delivery_course_id', $deliveryCourseId)
            ->whereTime('picking_start_time', $currentHourStartTime)
            ->first();

        // 3. 該当時間帯がなければ、現在時刻以前の最新のwave_settingを取得
        if (! $waveSetting) {
            $currentTime = now()->format('H:i:s');
            $waveSetting = WaveSetting::where('warehouse_id', $warehouseId)
                ->where('delivery_course_id', $deliveryCourseId)
                ->where(function ($query) use ($currentTime) {
                    $query->whereTime('picking_start_time', '<=', $currentTime)
                        ->orWhereNull('picking_start_time'); // 臨時Waveも対象
                })
                ->orderBy('picking_start_time', 'desc')
                ->first();
        }

        if (! $waveSetting) {
            // 4. wave_settingがない場合は臨時Wave生成
            return $this->createTemporaryWave($warehouseId, $deliveryCourseId, $shippingDate);
        }

        // 5. 既存Waveを検索（wave_settingが見つかった場合）
        $existingWave = Wave::where('wms_wave_setting_id', $waveSetting->id)
            ->where('shipping_date', $shippingDate)
            ->first();

        if ($existingWave) {
            return $existingWave;
        }

        // 6. wave_settingを使用してWave生成
        return $this->createWaveFromSetting($waveSetting, $shippingDate);
    }

    /**
     * wave_settingからWaveを生成
     */
    public function createWaveFromSetting(WaveSetting $waveSetting, string $shippingDate): Wave
    {
        return DB::transaction(function () use ($waveSetting, $shippingDate) {
            // Wave作成
            $wave = Wave::create([
                'wms_wave_setting_id' => $waveSetting->id,
                'wave_no' => uniqid('TEMP_'),
                'shipping_date' => $shippingDate,
                'status' => 'PENDING',
            ]);

            // 倉庫・配送コース情報を取得
            $warehouse = DB::connection('sakemaru')
                ->table('warehouses')
                ->where('id', $waveSetting->warehouse_id)
                ->first();

            $course = DB::connection('sakemaru')
                ->table('delivery_courses')
                ->where('id', $waveSetting->delivery_course_id)
                ->first();

            // wave_noを更新
            $waveNo = Wave::generateWaveNo(
                $warehouse->code ?? 0,
                $course->code ?? 0,
                $shippingDate,
                $wave->id
            );

            $wave->update(['wave_no' => $waveNo]);

            return $wave;
        });
    }

    /**
     * 臨時Waveを生成
     *
     * wave_settingがない場合に、臨時のwave_settingとWaveを生成する
     */
    public function createTemporaryWave(int $warehouseId, int $deliveryCourseId, string $shippingDate): Wave
    {
        return DB::transaction(function () use ($warehouseId, $deliveryCourseId, $shippingDate) {
            // 臨時wave_settingを作成
            $temporaryWaveSetting = WaveSetting::create([
                'warehouse_id' => $warehouseId,
                'delivery_course_id' => $deliveryCourseId,
                'picking_start_time' => null, // 臨時Wave
                'picking_deadline_time' => null,
                'creator_id' => auth()->id() ?? 1,
                'last_updater_id' => auth()->id() ?? 1,
            ]);

            // 臨時Waveを生成
            return $this->createWaveFromSetting($temporaryWaveSetting, $shippingDate);
        });
    }

    /**
     * Waveのstatusを更新
     */
    public function updateWaveStatus(int $waveId, string $status): bool
    {
        $wave = Wave::find($waveId);

        if (! $wave) {
            return false;
        }

        $wave->update(['status' => $status]);

        return true;
    }
}
