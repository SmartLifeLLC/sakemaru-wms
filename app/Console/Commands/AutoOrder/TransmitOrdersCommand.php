<?php

namespace App\Console\Commands\AutoOrder;

use App\Enums\AutoOrder\CandidateStatus;
use App\Models\WmsAutoOrderJobControl;
use App\Models\WmsOrderCandidate;
use App\Services\AutoOrder\OrderTransmissionService;
use Illuminate\Console\Command;

class TransmitOrdersCommand extends Command
{
    protected $signature = 'wms:transmit-orders
                            {--batch-code= : 送信するバッチコード（省略時は最新の承認済みバッチを使用）}
                            {--dry-run : 実際の送信は行わず、対象データのみ表示}';

    protected $description = '承認済み発注候補をJX-FINET/FTPで送信';

    public function handle(OrderTransmissionService $service): int
    {
        $this->info('発注送信を開始します...');

        $batchCode = $this->option('batch-code');
        $dryRun = $this->option('dry-run');

        if (!$batchCode) {
            // 最新の承認済みバッチを取得
            $latestCandidate = WmsOrderCandidate::where('status', CandidateStatus::APPROVED)
                ->whereNull('transmitted_at')
                ->orderBy('created_at', 'desc')
                ->first();

            if (!$latestCandidate) {
                $this->error('送信対象の承認済み発注候補がありません。');
                return self::FAILURE;
            }

            $batchCode = $latestCandidate->batch_code;
            $this->info("バッチコード: {$batchCode} を使用します");
        }

        // 送信対象の確認
        $candidates = WmsOrderCandidate::where('batch_code', $batchCode)
            ->where('status', CandidateStatus::APPROVED)
            ->whereNull('transmitted_at')
            ->with(['warehouse', 'item', 'contractor'])
            ->get();

        if ($candidates->isEmpty()) {
            $this->error("バッチコード {$batchCode} に送信対象の候補がありません。");
            return self::FAILURE;
        }

        $this->info("送信対象: {$candidates->count()}件");
        $this->newLine();

        // 倉庫・発注先別にグループ化して表示
        $groups = $candidates->groupBy(function ($c) {
            $warehouseName = $c->warehouse?->name ?? 'N/A';
            $contractorName = $c->contractor?->contractor_name ?? 'N/A';
            return "{$warehouseName}|{$contractorName}";
        });

        $this->table(
            ['倉庫', '発注先', '商品数', '合計数量'],
            $groups->map(function ($items, $key) {
                [$warehouse, $contractor] = explode('|', $key);
                return [
                    $warehouse,
                    $contractor,
                    $items->count(),
                    $items->sum('order_quantity'),
                ];
            })->values()
        );

        if ($dryRun) {
            $this->warn('--dry-run が指定されているため、実際の送信は行いません。');
            return self::SUCCESS;
        }

        if (!$this->confirm('送信を実行しますか？')) {
            $this->info('キャンセルされました。');
            return self::SUCCESS;
        }

        try {
            $job = $service->transmitApprovedOrders($batchCode);

            $this->newLine();
            $this->info("送信完了しました。バッチコード: {$job->batch_code}");
            $this->info("処理件数: {$job->processed_records}");

            return self::SUCCESS;

        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;

        } catch (\Exception $e) {
            $this->error('エラーが発生しました: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
}
