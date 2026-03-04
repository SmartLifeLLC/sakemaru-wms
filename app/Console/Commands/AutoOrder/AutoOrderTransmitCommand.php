<?php

namespace App\Console\Commands\AutoOrder;

use App\Enums\AutoOrder\CandidateStatus;
use App\Jobs\ProcessAutoSendJob;
use App\Models\WmsAutoOrderExecutionLog;
use App\Models\WmsContractorSetting;
use App\Models\WmsOrderCandidate;
use App\Models\WmsQueueProgress;
use App\Models\WmsStockTransferCandidate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AutoOrderTransmitCommand extends Command
{
    protected $signature = 'wms:auto-order-transmit';

    protected $description = '送信時刻に基づく自動送信（未送信候補を全バッチから統合）';

    public function handle(): int
    {
        $now = now();
        $currentTime = $now->format('H:i');
        $currentDayColumn = 'is_transmission_'.strtolower($now->format('D'));

        $this->info("=== 自動送信スケジューラー ({$currentTime}) ===");

        // 自動送信対象の発注先を取得
        $settings = WmsContractorSetting::query()
            ->whereNull('transmission_contractor_id')
            ->where('is_auto_transmission', true)
            ->whereNotNull('transmission_time')
            ->where('transmission_time', '<=', $currentTime)
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

            // 当日すでに送信済み or 処理中かチェック（重複dispatch防止）
            $todayLog = WmsAutoOrderExecutionLog::where('contractor_id', $contractorId)
                ->where('executed_date', today())
                ->latest('id')
                ->first();

            if ($todayLog && in_array($todayLog->transmission_status, ['RUNNING', 'SUCCESS'])) {
                $this->line("  仕入先ID:{$contractorId} → スキップ（送信{$todayLog->transmission_status}）");
                $skipped++;

                continue;
            }

            // 対象の発注先（親＋子）
            $allContractorIds = WmsContractorSetting::getContractorIdsWithChildren($contractorId);

            // 未送信の候補があるか確認（日付制限なし、全バッチ対象）
            $hasUnsentOrders = WmsOrderCandidate::whereIn('contractor_id', $allContractorIds)
                ->whereIn('status', [CandidateStatus::PENDING, CandidateStatus::APPROVED, CandidateStatus::CONFIRMED])
                ->where(function ($q) {
                    $q->where('status', '!=', CandidateStatus::CONFIRMED)
                        ->orWhereNull('wms_order_jx_document_id');
                })
                ->exists();

            // 移動候補はINTERNAL contractor IDを持つため、発注候補のbatch_codeから関連を特定
            $relatedBatchCodes = WmsOrderCandidate::whereIn('contractor_id', $allContractorIds)
                ->whereIn('status', [CandidateStatus::PENDING, CandidateStatus::APPROVED, CandidateStatus::CONFIRMED])
                ->distinct()
                ->pluck('batch_code')
                ->toArray();

            $hasUnsentTransfers = ! empty($relatedBatchCodes) && WmsStockTransferCandidate::whereIn('batch_code', $relatedBatchCodes)
                ->whereIn('status', [CandidateStatus::PENDING, CandidateStatus::APPROVED])
                ->exists();

            if (! $hasUnsentOrders && ! $hasUnsentTransfers) {
                $this->line("  仕入先ID:{$contractorId} → スキップ（未送信候補なし）");
                $skipped++;

                continue;
            }

            // 実行ログを取得 or 作成（当日のログがなければ送信専用ログを作成）
            $executionLog = $todayLog ?? WmsAutoOrderExecutionLog::create([
                'contractor_id' => $contractorId,
                'executed_date' => $now->toDateString(),
                'status' => 'SUCCESS',
                'started_at' => $now,
                'finished_at' => $now,
            ]);

            $executionLog->update(['transmission_status' => 'RUNNING']);

            // ProcessAutoSendJob dispatch（batchCode=null で全バッチ統合処理）
            $autoSendProgress = WmsQueueProgress::createJob(
                WmsQueueProgress::JOB_TYPE_AUTO_SEND,
                null,
                ['contractor_id' => $contractorId, 'source' => 'scheduled_transmit']
            );

            ProcessAutoSendJob::dispatch(
                progressId: $autoSendProgress->job_id,
                batchCode: null,
                contractorId: $contractorId,
                executionLogId: $executionLog->id,
            );

            $this->line("  仕入先ID:{$contractorId} → AutoSend dispatch完了（全バッチ統合）");
            Log::info('Auto-send job dispatched from transmit command (all batches)', [
                'contractor_id' => $contractorId,
            ]);
            $dispatched++;
        }

        $this->newLine();
        $this->info("実行: {$dispatched}件, スキップ: {$skipped}件");
        $this->info('=== 完了 ===');

        return self::SUCCESS;
    }
}
