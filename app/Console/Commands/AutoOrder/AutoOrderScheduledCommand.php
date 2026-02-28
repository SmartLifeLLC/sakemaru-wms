<?php

namespace App\Console\Commands\AutoOrder;

use App\Enums\AutoOrder\CandidateStatus;
use App\Jobs\ProcessOrderCandidateGenerationJob;
use App\Models\WmsAutoOrderExecutionLog;
use App\Models\WmsContractorSetting;
use App\Models\WmsOrderCandidate;
use App\Models\WmsQueueProgress;
use App\Models\WmsStockTransferCandidate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AutoOrderScheduledCommand extends Command
{
    protected $signature = 'wms:auto-order-scheduled';

    protected $description = '仕入先別スケジュールに基づく自動発注計算';

    public function handle(): int
    {
        $now = now();
        $currentTime = $now->format('H:i');
        $currentDayColumn = 'is_transmission_'.strtolower($now->format('D'));

        $this->info("=== 仕入先別自動発注スケジューラー ({$currentTime}) ===");

        // 自動発注対象の仕入先を取得
        $settings = WmsContractorSetting::query()
            ->whereNotNull('auto_order_generation_time')
            ->where('auto_order_generation_time', '<=', $currentTime)
            ->where($currentDayColumn, true)
            ->get();

        if ($settings->isEmpty()) {
            $this->info('対象の仕入先はありません');

            return self::SUCCESS;
        }

        $this->info("対象仕入先: {$settings->count()}件");

        $dispatched = 0;
        $skipped = 0;

        foreach ($settings as $setting) {
            $contractorId = $setting->contractor_id;

            // 当日すでに実行済み（RUNNING or SUCCESS）かチェック
            if (WmsAutoOrderExecutionLog::hasRunOrSucceededToday($contractorId)) {
                $this->line("  仕入先ID:{$contractorId} → スキップ（当日実行済み）");
                $skipped++;

                continue;
            }

            // 未処理候補（PENDING/APPROVED）がある仕入先はスキップ
            $hasPendingOrders = WmsOrderCandidate::query()
                ->whereIn('status', [CandidateStatus::PENDING, CandidateStatus::APPROVED])
                ->where('contractor_id', $contractorId)
                ->exists();

            $hasPendingTransfers = WmsStockTransferCandidate::query()
                ->whereIn('status', [CandidateStatus::PENDING, CandidateStatus::APPROVED])
                ->where('contractor_id', $contractorId)
                ->exists();

            if ($hasPendingOrders || $hasPendingTransfers) {
                $this->line("  仕入先ID:{$contractorId} → スキップ（未処理候補あり）");
                Log::info("仕入先ID:{$contractorId} に未処理候補あり、スキップ");
                $skipped++;

                continue;
            }

            // 実行ログを記録
            $log = WmsAutoOrderExecutionLog::create([
                'contractor_id' => $contractorId,
                'executed_date' => $now->toDateString(),
                'status' => 'RUNNING',
                'started_at' => $now,
            ]);

            // 進捗レコードを作成
            $queueProgress = WmsQueueProgress::createJob(
                WmsQueueProgress::JOB_TYPE_ORDER_CANDIDATE_GENERATION,
                null,
                ['contractor_id' => $contractorId, 'source' => 'scheduled']
            );

            // 仕入先別に候補生成Job起動
            ProcessOrderCandidateGenerationJob::dispatch(
                jobId: $queueProgress->job_id,
                deletePending: false,
                contractorId: $contractorId,
                executionLogId: $log->id,
            );

            $this->line("  仕入先ID:{$contractorId} → Job dispatch完了");
            $dispatched++;
        }

        $this->newLine();
        $this->info("実行: {$dispatched}件, スキップ: {$skipped}件");
        $this->info('=== 完了 ===');

        return self::SUCCESS;
    }
}
