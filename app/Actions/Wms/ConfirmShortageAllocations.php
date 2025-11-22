<?php

namespace App\Actions\Wms;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 代理出荷を承認するアクション
 *
 * wms_shortages.is_confirmed = true にされたときに、
 * 関連する wms_shortage_allocations も自動的に承認する
 */
class ConfirmShortageAllocations
{
    /**
     * 欠品に関連する全ての代理出荷を承認
     *
     * @param int $wmsShortageId 欠品ID
     * @param int $confirmedUserId 承認者のユーザーID
     * @return int 承認された代理出荷の件数
     */
    public static function execute(int $wmsShortageId, int $confirmedUserId): int
    {
        $confirmedCount = DB::connection('sakemaru')
            ->table('wms_shortage_allocations')
            ->where('shortage_id', $wmsShortageId)
            ->where('is_confirmed', false)
            ->update([
                'is_confirmed' => true,
                'confirmed_at' => now(),
                'confirmed_user_id' => $confirmedUserId,
                'status' => 'RESERVED',  // 承認時にステータスをRESERVEDに変更
                'updated_at' => now(),
            ]);

        Log::info('WMS shortage allocations confirmed', [
            'wms_shortage_id' => $wmsShortageId,
            'confirmed_user_id' => $confirmedUserId,
            'confirmed_count' => $confirmedCount,
        ]);

        return $confirmedCount;
    }
}