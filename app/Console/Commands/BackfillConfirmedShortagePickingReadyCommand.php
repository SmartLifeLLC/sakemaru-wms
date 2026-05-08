<?php

namespace App\Console\Commands;

use App\Services\Shortage\ShortageApprovalService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillConfirmedShortagePickingReadyCommand extends Command
{
    protected $signature = 'wms:backfill-confirmed-shortage-picking-ready
        {--dry-run : 実行せずに対象件数のみ表示}';

    protected $description = '承認済み欠品の元ピッキング結果へ出荷準備済みフラグを反映する';

    public function handle(ShortageApprovalService $approvalService): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $shortages = DB::connection('sakemaru')
            ->table('wms_shortages as ws')
            ->join('wms_picking_item_results as pir', 'pir.id', '=', 'ws.source_pick_result_id')
            ->where('ws.is_confirmed', true)
            ->where(function ($query) {
                $query->whereNull('pir.is_ready_to_shipment')
                    ->orWhere('pir.is_ready_to_shipment', false);
            })
            ->select([
                'ws.id',
                'ws.source_pick_result_id',
                'ws.trade_item_id',
                'ws.shortage_qty',
                'pir.planned_qty',
                'pir.picked_qty',
            ])
            ->orderBy('ws.id')
            ->get();

        $this->info("対象: {$shortages->count()} 件");

        if ($shortages->isEmpty()) {
            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->table(
                ['Shortage ID', 'PIR ID', 'Trade Item ID', '欠品数', '引当数', 'ピック数'],
                $shortages->map(fn ($row) => [
                    $row->id,
                    $row->source_pick_result_id,
                    $row->trade_item_id,
                    $row->shortage_qty,
                    $row->planned_qty,
                    $row->picked_qty,
                ])->toArray()
            );

            return self::SUCCESS;
        }

        $models = \App\Models\WmsShortage::whereIn('id', $shortages->pluck('id')->all())->get();

        foreach ($models as $shortage) {
            $approvalService->markPickingResultReadyForShipment($shortage);
        }

        $this->info("完了: {$models->count()} 件を更新しました");

        return self::SUCCESS;
    }
}
