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
     * @param int $warehouseId 倉庫ID
     * @param int $deliveryCourseId 配送コースID
     * @param string $shippingDate 出荷日（Y-m-d形式）
     * @return Wave|null
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
     *
     * @param int $warehouseId 倉庫ID
     * @param int $deliveryCourseId 配送コースID
     * @param string $shippingDate 出荷日（Y-m-d形式）
     * @return Wave
     */
    public function getOrCreateWave(int $warehouseId, int $deliveryCourseId, string $shippingDate): Wave
    {
        // 1. 既存Waveを検索
        $existingWave = $this->findExistingWave($warehouseId, $deliveryCourseId, $shippingDate);

        if ($existingWave) {
            return $existingWave;
        }

        // 2. wave_settingを検索（現在時刻以前の開始時刻）
        $currentTime = now()->format('H:i:s');
        $waveSetting = WaveSetting::where('warehouse_id', $warehouseId)
            ->where('delivery_course_id', $deliveryCourseId)
            ->where(function ($query) use ($currentTime) {
                $query->whereTime('picking_start_time', '<=', $currentTime)
                      ->orWhereNull('picking_start_time'); // 臨時Waveも対象
            })
            ->orderBy('picking_start_time', 'desc')
            ->first();

        if ($waveSetting) {
            // 3-A. 既存wave_settingを使用してWave生成
            return $this->createWaveFromSetting($waveSetting, $shippingDate);
        }

        // 3-B. 臨時Wave生成（wave_setting不在）
        return $this->createTemporaryWave($warehouseId, $deliveryCourseId, $shippingDate);
    }

    /**
     * wave_settingからWaveを生成
     *
     * @param WaveSetting $waveSetting
     * @param string $shippingDate
     * @return Wave
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
     *
     * @param int $warehouseId
     * @param int $deliveryCourseId
     * @param string $shippingDate
     * @return Wave
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
     *
     * @param int $waveId
     * @param string $status
     * @return bool
     */
    public function updateWaveStatus(int $waveId, string $status): bool
    {
        $wave = Wave::find($waveId);

        if (!$wave) {
            return false;
        }

        $wave->update(['status' => $status]);

        return true;
    }
}
