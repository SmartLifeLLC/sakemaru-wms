<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 得意先の配送コースを時間帯で自動切替するコマンド
 *
 * 15分間隔でスケジュール実行され、wms_buyer_delivery_course_switch_settings の
 * switch_time に合致する設定を実行する。
 */
class SwitchDeliveryCourseCommand extends Command
{
    protected $signature = 'wms:switch-delivery-course';

    protected $description = 'Switch delivery courses for buyers based on time-based settings';

    public function handle(): int
    {
        // 現在の時刻スロットを15分単位に切り捨て
        $now = now();
        $minute = (int) floor($now->minute / 15) * 15;
        $timeSlot = sprintf('%02d:%02d:00', $now->hour, $minute);
        $today = $now->format('Y-m-d');

        $this->info("Checking delivery course switches for time slot: {$timeSlot}");

        // Step 1: 原子UPDATE — 実行制御
        // last_executed_date が今日でない設定のみを対象にし、同時に last_executed_date を更新
        $affectedRows = DB::connection('sakemaru')
            ->table('wms_buyer_delivery_course_switch_settings')
            ->where('switch_time', $timeSlot)
            ->whereNull('deleted_at')
            ->where(function ($query) use ($today) {
                $query->where('last_executed_date', '!=', $today)
                    ->orWhereNull('last_executed_date');
            })
            ->update([
                'last_executed_date' => $today,
                'last_executed_at' => $now,
                'updated_at' => $now,
            ]);

        if ($affectedRows === 0) {
            $this->info('No pending switches for this time slot.');

            return 0;
        }

        $this->info("Found {$affectedRows} switch setting(s) to execute.");

        // Step 2: 対象設定を取得し、buyer_details の delivery_course_id を一括更新
        $settings = DB::connection('sakemaru')
            ->table('wms_buyer_delivery_course_switch_settings')
            ->where('switch_time', $timeSlot)
            ->where('last_executed_date', $today)
            ->whereNull('deleted_at')
            ->get();

        $updatedCount = 0;

        foreach ($settings as $setting) {
            $updated = DB::connection('sakemaru')
                ->table('buyer_details')
                ->where('buyer_id', $setting->buyer_id)
                ->where('is_active', true)
                ->where('delivery_course_id', '!=', $setting->to_delivery_course_id)
                ->update([
                    'delivery_course_id' => $setting->to_delivery_course_id,
                    'updated_at' => $now,
                ]);

            if ($updated > 0) {
                $updatedCount += $updated;

                Log::info('Delivery course switched', [
                    'setting_id' => $setting->id,
                    'buyer_id' => $setting->buyer_id,
                    'to_delivery_course_id' => $setting->to_delivery_course_id,
                    'time_slot' => $timeSlot,
                ]);
            }
        }

        $this->info("Delivery course switch completed. Updated {$updatedCount} buyer_details record(s).");

        return 0;
    }
}
