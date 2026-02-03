<?php

namespace App\Jobs;

use App\Models\Sakemaru\RealStockLot;
use App\Models\Sakemaru\RealStockLotHistory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ArchiveDepletedLots implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    protected int $retentionDays;

    /**
     * Create a new job instance.
     *
     * @param  int  $retentionDays  アーカイブ対象のロットが更新されてからの経過日数
     */
    public function __construct(int $retentionDays = 30)
    {
        $this->retentionDays = $retentionDays;
        $this->onConnection('sakemaru');
        $this->onQueue('lot-archive');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $cutoffDate = now()->subDays($this->retentionDays);

        // DEPLETED または EXPIRED のロットで、更新日が一定期間過ぎたものを取得
        $lots = RealStockLot::whereIn('status', [
            RealStockLot::STATUS_DEPLETED,
            RealStockLot::STATUS_EXPIRED,
        ])
            ->where('updated_at', '<', $cutoffDate)
            ->limit(1000)
            ->get();

        $archivedCount = 0;

        foreach ($lots as $lot) {
            try {
                $this->archiveLot($lot);
                $archivedCount++;
            } catch (\Exception $e) {
                Log::error('Failed to archive lot', [
                    'lot_id' => $lot->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Depleted lots archived', [
            'archived_count' => $archivedCount,
            'retention_days' => $this->retentionDays,
        ]);
    }

    /**
     * 個別のロットをアーカイブ
     */
    protected function archiveLot(RealStockLot $lot): void
    {
        DB::connection('sakemaru')->transaction(function () use ($lot) {
            // 履歴テーブルに移行
            RealStockLotHistory::createFromLot($lot);

            // 元のロットを削除
            $lot->delete();
        });
    }
}
