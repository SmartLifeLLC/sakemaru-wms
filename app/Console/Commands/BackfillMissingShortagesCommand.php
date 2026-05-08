<?php

namespace App\Console\Commands;

use App\Models\WmsPickingItemResult;
use App\Models\WmsShortage;
use App\Services\Shortage\PickingShortageDetector;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class BackfillMissingShortagesCommand extends Command
{
    protected $signature = 'wms:backfill-missing-shortages
        {--dry-run : 実行せずに対象件数のみ表示}';

    protected $description = 'ピッキング完了済みだがwms_shortagesレコードが未作成の引当欠品を検出・生成';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $candidates = DB::connection('sakemaru')
            ->table('wms_picking_item_results as pir')
            ->join('wms_picking_tasks as pt', 'pir.picking_task_id', '=', 'pt.id')
            ->whereIn('pt.status', ['COMPLETED', 'SHORTAGE'])
            ->whereColumn('pir.ordered_qty', '>', 'pir.picked_qty')
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('wms_shortages as ws')
                    ->whereColumn('ws.wave_id', 'pt.wave_id')
                    ->whereColumn('ws.warehouse_id', 'pt.warehouse_id')
                    ->whereColumn('ws.item_id', 'pir.item_id')
                    ->whereColumn('ws.trade_item_id', 'pir.trade_item_id');
            })
            ->select(['pir.id', 'pt.wave_id', 'pir.item_id', 'pir.ordered_qty', 'pir.planned_qty', 'pir.picked_qty'])
            ->get();

        $this->info("対象: {$candidates->count()} 件の欠品レコード未作成アイテム");

        if ($candidates->isEmpty()) {
            $this->info('修復対象なし');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->table(
                ['PIR ID', 'Wave ID', 'Item ID', '受注数', '引当数', 'ピック数', '欠品数'],
                $candidates->map(fn ($r) => [
                    $r->id, $r->wave_id, $r->item_id,
                    $r->ordered_qty, $r->planned_qty, $r->picked_qty,
                    $r->ordered_qty - $r->picked_qty,
                ])->toArray()
            );

            return self::SUCCESS;
        }

        if (! $this->confirm("{$candidates->count()} 件の欠品レコードを生成しますか？")) {
            return self::SUCCESS;
        }

        $detector = app(PickingShortageDetector::class);
        $created = 0;
        $failed = 0;

        $bar = $this->output->createProgressBar($candidates->count());

        foreach ($candidates as $row) {
            try {
                $pickResult = WmsPickingItemResult::find($row->id);
                if (! $pickResult) {
                    $failed++;
                    $bar->advance();
                    continue;
                }

                $shortage = $detector->detectAndRecord($pickResult, null);
                if ($shortage) {
                    $created++;
                }
            } catch (\Exception $e) {
                $this->error("PIR #{$row->id}: {$e->getMessage()}");
                $failed++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("完了: 作成 {$created} 件 / 失敗 {$failed} 件");

        return self::SUCCESS;
    }
}
