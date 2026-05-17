<?php

namespace App\Jobs;

use App\Enums\AutoOrder\OriginType;
use App\Models\WmsQueueProgress;
use App\Services\AutoOrder\SalesBasedOrderCandidateService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessSalesBasedOrderCandidateJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;

    public int $tries = 1;

    public function __construct(
        public string $jobId,
        public ?int $warehouseId,
        public int $createdBy,
        public ?array $contractorIds = null,
        public ?string $batchCode = null,
        public ?string $originType = null,
        public string $salesBasis = 'last_3d',
        public string $orderPointFilter = 'ignore',
        public string $autoOrderFlagFilter = 'ignore',
        public bool $transferOnly = false,
    ) {}

    public function handle(): void
    {
        ini_set('memory_limit', '-1');

        $this->createdBy ??= \App\Models\Sakemaru\User::resolveAutomatorId();

        $progress = WmsQueueProgress::findByJobId($this->jobId);

        if (! $progress) {
            Log::error('[SalesBasedJob] Queue progress not found', ['job_id' => $this->jobId]);

            return;
        }

        try {
            $salesBasis = $this->salesBasis ?? 'last_3d';
            $orderPointFilter = $this->orderPointFilter ?? 'ignore';
            $autoOrderFlagFilter = $this->autoOrderFlagFilter ?? 'ignore';

            $progress->markAsProcessing(100, $this->transferOnly ? '実績ベース移動候補を生成中...' : '実績ベース発注候補を生成中...');

            $progress->update([
                'progress' => 10,
                'message' => $this->transferOnly ? '実績ベース移動候補を計算中...' : '実績ベース発注候補を計算中...',
            ]);

            $service = app(SalesBasedOrderCandidateService::class);
            $calcJob = $service->calculate(
                warehouseId: $this->warehouseId,
                createdBy: $this->createdBy,
                contractorIds: $this->contractorIds,
                batchCode: $this->batchCode,
                originType: $this->originType ? OriginType::from($this->originType) : null,
                salesBasis: $salesBasis,
                orderPointFilter: $orderPointFilter,
                autoOrderFlagFilter: $autoOrderFlagFilter,
                transferOnly: $this->transferOnly,
            );

            $results = [
                'batchCode' => $calcJob->batch_code,
                'calculated' => $calcJob->processed_records,
                'transferCandidates' => $calcJob->result_data['summary']['internal_candidates'] ?? 0,
                'orderCandidates' => $calcJob->result_data['summary']['external_candidates'] ?? 0,
            ];

            $progress->update([
                'progress' => 90,
                'message' => '集計中...',
            ]);

            $results['byWarehouse'] = DB::connection('sakemaru')
                ->table('wms_order_candidates')
                ->join('warehouses', 'wms_order_candidates.warehouse_id', '=', 'warehouses.id')
                ->where('wms_order_candidates.batch_code', $calcJob->batch_code)
                ->groupBy('wms_order_candidates.warehouse_id', 'warehouses.name')
                ->selectRaw('warehouses.name as warehouse_name, COUNT(*) as count')
                ->orderBy('warehouses.name')
                ->get()
                ->map(fn ($row) => [
                    'warehouse_name' => $row->warehouse_name,
                    'count' => $row->count,
                ])
                ->toArray();

            $progress->markAsCompleted($results, $this->transferOnly ? '実績ベース移動候補の生成が完了しました' : '実績ベース発注候補の生成が完了しました');

            Log::info('[SalesBasedJob] completed', [
                'job_id' => $this->jobId,
                'results' => $results,
            ]);
        } catch (\Throwable $e) {
            Log::error('[SalesBasedJob] failed', [
                'job_id' => $this->jobId,
                'error' => $e->getMessage(),
            ]);

            $progress->markAsFailed($e->getMessage());
            throw $e;
        }
    }
}
