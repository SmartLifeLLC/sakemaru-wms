<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PatchStockTransferDeliveryCoursesCommand extends Command
{
    protected $signature = 'wms:patch-stock-transfer-delivery-courses
        {--date= : 対象出庫日。COALESCE(picking_date, delivered_date) で判定}
        {--id=* : 対象 stock_transfers.id。複数指定可}
        {--all-statuses : picking_status / is_active 条件を外す}
        {--execute : 実更新する。未指定時は dry-run}
        {--limit=1000 : 最大処理件数}';

    protected $description = '倉庫間移動伝票の配送コースIDを移動元/移動先倉庫設定から補正する';

    public function handle(): int
    {
        $date = $this->option('date');
        $ids = collect($this->option('id'))
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
        $execute = (bool) $this->option('execute');
        $allStatuses = (bool) $this->option('all-statuses');
        $limit = max(1, (int) $this->option('limit'));

        if ($execute && ! $date && $ids->isEmpty()) {
            $this->error('--execute には --date か --id が必要です。');

            return self::FAILURE;
        }

        $rows = $this->targetRows($date, $ids, $allStatuses, $limit);

        $this->info(($execute ? '実更新' : 'dry-run').' 対象: '.$rows->count().' 件');

        if ($rows->isEmpty()) {
            return self::SUCCESS;
        }

        $this->printSummary($rows);

        if (! $execute) {
            $this->warn('更新はしていません。実行する場合は --execute を付けてください。');

            return self::SUCCESS;
        }

        DB::connection('sakemaru')->transaction(function () use ($rows) {
            $updatedTransfers = $this->patchStockTransfers($rows);
            $updatedQueues = $this->patchStockTransferQueues($rows);
            $updatedCandidates = $this->patchTransferCandidates($rows);

            $this->info("stock_transfers 更新: {$updatedTransfers} 件");
            $this->info("stock_transfer_queue 更新: {$updatedQueues} 件");
            $this->info("wms_stock_transfer_candidates 更新: {$updatedCandidates} 件");
        });

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, int>  $ids
     * @return Collection<int, object>
     */
    private function targetRows(?string $date, Collection $ids, bool $allStatuses, int $limit): Collection
    {
        $query = DB::connection('sakemaru')
            ->table('stock_transfers as st')
            ->join('warehouse_stock_transfer_delivery_courses as map', function ($join) {
                $join->on('map.from_warehouse_id', '=', 'st.from_warehouse_id')
                    ->on('map.to_warehouse_id', '=', 'st.to_warehouse_id');
            })
            ->join('delivery_courses as dc', 'dc.id', '=', 'map.delivery_course_id')
            ->join('warehouses as fw', 'fw.id', '=', 'st.from_warehouse_id')
            ->join('warehouses as tw', 'tw.id', '=', 'st.to_warehouse_id')
            ->leftJoin('stock_transfer_queue as q', function ($join) {
                $join->on('q.stock_transfer_id', '=', 'st.id')
                    ->where('q.action_type', '=', 'CREATE');
            })
            ->whereNull('st.delivery_course_id')
            ->whereNotNull('map.delivery_course_id');

        if ($date) {
            $query->whereRaw('COALESCE(st.picking_date, st.delivered_date) = ?', [$date]);
        }

        if ($ids->isNotEmpty()) {
            $query->whereIn('st.id', $ids->all());
        }

        if (! $allStatuses) {
            $query->where('st.is_active', true)
                ->where('st.picking_status', 'BEFORE');
        }

        return $query
            ->orderBy('st.id')
            ->limit($limit)
            ->select([
                'st.id as stock_transfer_id',
                'st.picking_date',
                'st.delivered_date',
                'st.picking_status',
                'st.is_active',
                'st.from_warehouse_id',
                'fw.code as from_warehouse_code',
                'fw.name as from_warehouse_name',
                'st.to_warehouse_id',
                'tw.code as to_warehouse_code',
                'tw.name as to_warehouse_name',
                'map.delivery_course_id as patch_delivery_course_id',
                'dc.code as patch_delivery_course_code',
                'dc.name as patch_delivery_course_name',
                'q.id as queue_id',
                'q.delivery_course_id as queue_delivery_course_id',
                'q.items as queue_items',
            ])
            ->get();
    }

    /**
     * @param  Collection<int, object>  $rows
     */
    private function printSummary(Collection $rows): void
    {
        $summary = $rows
            ->groupBy(fn ($row) => "{$row->from_warehouse_code}->{$row->to_warehouse_code}|{$row->patch_delivery_course_code}")
            ->map(function (Collection $group) {
                $first = $group->first();

                return [
                    'from' => "[{$first->from_warehouse_code}] {$first->from_warehouse_name}",
                    'to' => "[{$first->to_warehouse_code}] {$first->to_warehouse_name}",
                    'course' => "[{$first->patch_delivery_course_code}] {$first->patch_delivery_course_name}",
                    'count' => $group->count(),
                    'min_id' => $group->min('stock_transfer_id'),
                    'max_id' => $group->max('stock_transfer_id'),
                ];
            })
            ->values();

        $this->table(
            ['移動元', '移動先', '補正配送コース', '件数', 'min ID', 'max ID'],
            $summary->map(fn ($row) => [
                $row['from'],
                $row['to'],
                $row['course'],
                $row['count'],
                $row['min_id'],
                $row['max_id'],
            ])->all()
        );
    }

    /**
     * @param  Collection<int, object>  $rows
     */
    private function patchStockTransfers(Collection $rows): int
    {
        $updated = 0;

        foreach ($rows->groupBy('patch_delivery_course_id') as $deliveryCourseId => $group) {
            $updated += DB::connection('sakemaru')
                ->table('stock_transfers')
                ->whereNull('delivery_course_id')
                ->whereIn('id', $group->pluck('stock_transfer_id')->all())
                ->update([
                    'delivery_course_id' => (int) $deliveryCourseId,
                    'updated_at' => now(),
                ]);
        }

        return $updated;
    }

    /**
     * @param  Collection<int, object>  $rows
     */
    private function patchStockTransferQueues(Collection $rows): int
    {
        $updated = 0;

        foreach ($rows->whereNotNull('queue_id')->groupBy('patch_delivery_course_id') as $deliveryCourseId => $group) {
            $updated += DB::connection('sakemaru')
                ->table('stock_transfer_queue')
                ->whereNull('delivery_course_id')
                ->whereIn('id', $group->pluck('queue_id')->all())
                ->update([
                    'delivery_course_id' => (int) $deliveryCourseId,
                    'updated_at' => now(),
                ]);
        }

        return $updated;
    }

    /**
     * @param  Collection<int, object>  $rows
     */
    private function patchTransferCandidates(Collection $rows): int
    {
        $candidateIdsByCourse = [];

        foreach ($rows as $row) {
            foreach ($this->extractCandidateIds($row->queue_items) as $candidateId) {
                $candidateIdsByCourse[(int) $row->patch_delivery_course_id][] = $candidateId;
            }
        }

        $updated = 0;

        foreach ($candidateIdsByCourse as $deliveryCourseId => $candidateIds) {
            $updated += DB::connection('sakemaru')
                ->table('wms_stock_transfer_candidates')
                ->whereNull('delivery_course_id')
                ->whereIn('id', array_values(array_unique($candidateIds)))
                ->update([
                    'delivery_course_id' => $deliveryCourseId,
                    'updated_at' => now(),
                ]);
        }

        return $updated;
    }

    /**
     * @return array<int>
     */
    private function extractCandidateIds(?string $itemsJson): array
    {
        if (! $itemsJson) {
            return [];
        }

        $items = json_decode($itemsJson, true);
        if (! is_array($items)) {
            return [];
        }

        $ids = [];
        foreach ($items as $item) {
            $note = is_array($item) ? (string) ($item['note'] ?? '') : '';
            if (preg_match('/移動候補ID:\s*(\d+)/u', $note, $matches)) {
                $ids[] = (int) $matches[1];
            }
        }

        return $ids;
    }
}
