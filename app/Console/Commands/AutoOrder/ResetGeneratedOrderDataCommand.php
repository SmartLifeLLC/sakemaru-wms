<?php

namespace App\Console\Commands\AutoOrder;

use App\Enums\AutoOrder\OrderSource;
use App\Models\WmsQueueProgress;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ResetGeneratedOrderDataCommand extends Command
{
    protected $signature = 'wms:auto-order-reset-generated-data
        {--execute : 実際に削除する。指定しない場合は件数確認のみ}
        {--run-after : 削除後に wms:auto-order-scheduled --force を実行する}';

    protected $description = '自動発注の生成データを削除して再生成可能な状態に戻す';

    private const SAKEMARU_TABLES = [
        'wms_order_transmission_logs',
        'wms_order_data_files',
        'wms_order_jx_documents',
        'wms_order_candidate_audit_logs',
        'wms_order_calculation_logs',
        'wms_order_incoming_schedules',
        'wms_order_candidates',
        'wms_stock_transfer_candidates',
        'wms_auto_order_execution_log',
        'wms_queue_progress',
    ];

    public function handle(): int
    {
        $execute = (bool) $this->option('execute');
        $runAfter = (bool) $this->option('run-after');

        $this->warn($execute
            ? '実行モード: 自動発注の生成データを削除します。'
            : 'dry-run: 削除対象件数だけ確認します。');

        $counts = $this->collectCounts();
        $this->table(['table', 'delete_target_rows'], collect($counts)->map(fn ($count, $table) => [$table, $count])->values()->all());

        if (! $execute) {
            $this->info('削除する場合は --execute を付けて再実行してください。');

            return self::SUCCESS;
        }

        DB::connection('sakemaru')->transaction(function () {
            $this->deleteSakemaruData();
        });
        $this->deleteQueuedOrderCandidateJobs();

        $this->info('自動発注の生成データを削除しました。');

        if ($runAfter) {
            $this->warn('削除後の自動発注生成を開始します。');
            $this->call('wms:auto-order-scheduled', ['--force' => true]);
        }

        return self::SUCCESS;
    }

    private function collectCounts(): array
    {
        $connection = DB::connection('sakemaru');
        $counts = [];

        foreach (self::SAKEMARU_TABLES as $table) {
            if (! Schema::connection('sakemaru')->hasTable($table)) {
                continue;
            }

            $counts[$table] = match ($table) {
                'wms_order_incoming_schedules' => $connection->table($table)
                    ->whereIn('order_source', [OrderSource::AUTO->value, OrderSource::TRANSFER->value])
                    ->count(),
                'wms_queue_progress' => $connection->table($table)
                    ->whereIn('job_type', [
                        WmsQueueProgress::JOB_TYPE_ORDER_CANDIDATE_GENERATION,
                        WmsQueueProgress::JOB_TYPE_ORDER_CONFIRMATION,
                        WmsQueueProgress::JOB_TYPE_CSV_GENERATION,
                        WmsQueueProgress::JOB_TYPE_JX_GENERATION,
    
                        WmsQueueProgress::JOB_TYPE_AUTO_SEND,
                    ])
                    ->count(),
                default => $connection->table($table)->count(),
            };
        }

        if (Schema::hasTable('jobs')) {
            $counts['jobs(order_candidate_generation)'] = $this->queuedOrderCandidateJobsQuery()->count();
        }

        return $counts;
    }

    private function deleteSakemaruData(): void
    {
        $connection = DB::connection('sakemaru');

        $this->deleteAllIfExists('wms_order_transmission_logs');
        $this->deleteAllIfExists('wms_order_data_files');
        $this->deleteAllIfExists('wms_order_jx_documents');
        $this->deleteAllIfExists('wms_order_candidate_audit_logs');
        $this->deleteAllIfExists('wms_order_calculation_logs');

        if (Schema::connection('sakemaru')->hasTable('wms_order_incoming_schedules')) {
            $connection->table('wms_order_incoming_schedules')
                ->whereIn('order_source', [OrderSource::AUTO->value, OrderSource::TRANSFER->value])
                ->delete();
        }

        $this->deleteAllIfExists('wms_order_candidates');
        $this->deleteAllIfExists('wms_stock_transfer_candidates');
        $this->deleteAllIfExists('wms_auto_order_execution_log');

        if (Schema::connection('sakemaru')->hasTable('wms_queue_progress')) {
            $connection->table('wms_queue_progress')
                ->whereIn('job_type', [
                    WmsQueueProgress::JOB_TYPE_ORDER_CANDIDATE_GENERATION,
                    WmsQueueProgress::JOB_TYPE_ORDER_CONFIRMATION,
                    WmsQueueProgress::JOB_TYPE_CSV_GENERATION,
                    WmsQueueProgress::JOB_TYPE_JX_GENERATION,

                    WmsQueueProgress::JOB_TYPE_AUTO_SEND,
                ])
                ->delete();
        }
    }

    private function deleteAllIfExists(string $table): void
    {
        if (! Schema::connection('sakemaru')->hasTable($table)) {
            return;
        }

        DB::connection('sakemaru')->table($table)->delete();
    }

    private function deleteQueuedOrderCandidateJobs(): void
    {
        if (! Schema::hasTable('jobs')) {
            return;
        }

        $this->queuedOrderCandidateJobsQuery()->delete();
    }

    private function queuedOrderCandidateJobsQuery()
    {
        return DB::table('jobs')
            ->where('payload', 'like', '%ProcessOrderCandidateGenerationJob%');
    }
}
