<?php

namespace App\Console\Commands\AutoOrder;

use Illuminate\Console\Command;

class GenerateCalendarsCommand extends Command
{
    protected $signature = 'wms:generate-calendars
                            {--warehouse= : 特定の倉庫IDのみ生成}
                            {--months=12 : 生成する月数}';

    protected $description = '【利用停止】倉庫別営業日カレンダーを生成';

    public function handle(): int
    {
        // 休日の一括生成による既存の入荷日・出庫日への影響を避けるため利用停止。
        // 再利用時は自動発注ON/OFFから対象倉庫を分離し、既定の休日を作成しないこと。
        // 登録済み・手動編集済みの日付を保護し、倉庫・期間を限定した差分確認を必須にする。
        $this->error('倉庫カレンダーの一括生成は利用停止中です。日付の変更は「倉庫カレンダー」画面で行ってください。');

        return self::FAILURE;
    }
}
