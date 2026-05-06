<?php

namespace App\Services\PickingList;

use App\Enums\QuantityType;
use App\Models\Sakemaru\Location;
use Illuminate\Support\Facades\DB;

/**
 * ピッキングリストデータ取得サービス（読み取り専用）
 *
 * 3種類のピッキングリストのデータを取得する:
 * - 1次リスト: Wave集約（商品別の総数量一覧）
 * - 2次リスト: 作業者別実行（棚番順＋納品先内訳）
 * - 3次リスト: 納品先別仕分け（配送コース別→納品先別）
 */
class PickingListService
{
    private function db()
    {
        return DB::connection('sakemaru');
    }

    /**
     * 1次ピッキングリスト（Wave集約）
     *
     * @param  bool  $includePast  過去の伝票（出荷日が波動出荷日より前）も含める
     * @param  bool  $includeDelivered  配送済み（COMPLETED）タスクも含める
     * @return array{header: array, items: array, summary: array}
     */
    public function generatePrimaryList(int $waveId, bool $includePast = false, bool $includeDelivered = false): array
    {
        $wave = $this->db()->table('wms_waves as w')
            ->join('wms_wave_settings as ws', 'w.wms_wave_setting_id', '=', 'ws.id')
            ->join('delivery_courses as dc', 'ws.delivery_course_id', '=', 'dc.id')
            ->leftJoin('warehouses as wh', 'dc.warehouse_id', '=', 'wh.id')
            ->where('w.id', $waveId)
            ->select([
                'w.wave_no',
                'w.shipping_date',
                'wh.name as warehouse_name',
            ])
            ->first();

        if (! $wave) {
            return ['header' => [], 'items' => [], 'summary' => []];
        }

        $query = $this->db()->table('wms_picking_item_results as pir')
            ->join('wms_picking_tasks as pt', 'pir.picking_task_id', '=', 'pt.id')
            ->join('items as i', 'pir.item_id', '=', 'i.id')
            ->leftJoin('locations as l', 'pir.location_id', '=', 'l.id')
            ->where('pt.wave_id', $waveId);

        if (! $includePast) {
            $query->leftJoin('earnings as e', 'pir.earning_id', '=', 'e.id')
                ->where(function ($q) use ($wave) {
                    $q->whereNull('e.delivered_date')
                        ->orWhere('e.delivered_date', '>=', $wave->shipping_date);
                });
        }

        if (! $includeDelivered) {
            $query->where('pt.status', '!=', 'COMPLETED');
        }

        $items = $query->select([
            'i.code as item_code',
            'i.name as item_name',
            'i.capacity_case',
            'i.packaging',
            'pir.location_id',
            'l.code1',
            'l.code2',
            'l.code3',
            DB::raw('SUM(pir.planned_qty) as total_qty'),
            DB::raw('SUM(pir.shortage_qty) as shortage_qty'),
            'pir.planned_qty_type',
        ])
            ->groupBy('i.id', 'i.code', 'i.name', 'i.capacity_case', 'i.packaging', 'pir.planned_qty_type', 'pir.location_id', 'l.code1', 'l.code2', 'l.code3')
            ->orderByRaw("COALESCE(l.code1, 'ZZZ')")
            ->orderByRaw("COALESCE(l.code2, 'ZZZ')")
            ->orderByRaw("COALESCE(l.code3, 'ZZZ')")
            ->orderBy('i.code')
            ->get();

        $formattedItems = [];
        $totalQty = 0;
        $totalCase = 0;
        $totalPiece = 0;

        foreach ($items as $item) {
            $capacityCase = $item->capacity_case ?: 1;
            $qty = (int) $item->total_qty;
            $qtyType = QuantityType::tryFrom($item->planned_qty_type) ?? QuantityType::PIECE;

            if ($qtyType === QuantityType::CASE) {
                $caseQty = $qty;
                $pieceQty = 0;
            } elseif ($capacityCase > 1) {
                $caseQty = intdiv($qty, $capacityCase);
                $pieceQty = $qty % $capacityCase;
            } else {
                $caseQty = 0;
                $pieceQty = $qty;
            }

            $locationCode = $item->location_id
                ? Location::formatCode($item->code1, $item->code2, $item->code3, '-')
                : '';

            $formattedItems[] = [
                'item_code' => $item->item_code,
                'item_name' => $item->item_name,
                'packaging' => $item->packaging ?? '',
                'location_code' => $locationCode,
                'total_qty' => $qty,
                'case_qty' => $caseQty,
                'piece_qty' => $pieceQty,
                'shortage_qty' => (int) $item->shortage_qty,
            ];

            $totalQty += $qty;
            $totalCase += $caseQty;
            $totalPiece += $pieceQty;
        }

        return [
            'header' => [
                'wave_no' => $wave->wave_no,
                'shipping_date' => $wave->shipping_date,
                'warehouse_name' => $wave->warehouse_name ?? '',
            ],
            'items' => $formattedItems,
            'summary' => [
                'sku_count' => count($formattedItems),
                'total_qty' => $totalQty,
                'total_case' => $totalCase,
                'total_piece' => $totalPiece,
            ],
        ];
    }

    /**
     * 2次ピッキングリスト（タスク単位）
     *
     * 棚番順にソートし、各棚番・商品ごとに納品先内訳を付加する。
     *
     * @return array{header: array, items: array, summary: array}
     */
    public function generateSecondaryList(int $pickingTaskId): array
    {
        return $this->generateSecondaryListByTaskIds([$pickingTaskId]);
    }

    /**
     * 2次ピッキングリスト一括（ピッカー別）
     *
     * 複数WaveのタスクをピッカーIDでグループ化し、ピッカーごとの2次リストを返す。
     *
     * @return array[] generateSecondaryList と同じ構造の配列
     */
    public function generateSecondaryBatchList(array $waveIds): array
    {
        if (empty($waveIds)) {
            return [];
        }

        // 全タスクをピッカー別にグループ化
        $tasks = $this->db()->table('wms_picking_tasks')
            ->whereIn('wave_id', $waveIds)
            ->select(['id', 'picker_id'])
            ->get();

        $pickerTasks = [];
        foreach ($tasks as $task) {
            $pickerKey = $task->picker_id ?? 0;
            $pickerTasks[$pickerKey][] = $task->id;
        }

        $results = [];
        foreach ($pickerTasks as $pickerId => $taskIds) {
            $data = $this->generateSecondaryListByTaskIds($taskIds);
            if (! empty($data['items'])) {
                $results[] = $data;
            }
        }

        return $results;
    }

    /**
     * 複数タスクIDから2次リストデータを生成
     */
    private function generateSecondaryListByTaskIds(array $taskIds): array
    {
        // ヘッダー情報（最初のタスクから）
        $task = $this->db()->table('wms_picking_tasks as pt')
            ->join('wms_waves as w', 'pt.wave_id', '=', 'w.id')
            ->leftJoin('delivery_courses as dc', 'pt.delivery_course_id', '=', 'dc.id')
            ->leftJoin('wms_picking_areas as pa', 'pt.wms_picking_area_id', '=', 'pa.id')
            ->leftJoin('wms_pickers as pk', 'pt.picker_id', '=', 'pk.id')
            ->whereIn('pt.id', $taskIds)
            ->select([
                'w.wave_no',
                'dc.name as course_name',
                'pa.name as area_name',
                'pk.name as picker_name',
                'pt.shipment_date as shipping_date',
            ])
            ->first();

        if (! $task) {
            return ['header' => [], 'items' => [], 'summary' => []];
        }

        // 明細取得（棚番順）
        $results = $this->db()->table('wms_picking_item_results as pir')
            ->join('items as i', 'pir.item_id', '=', 'i.id')
            ->leftJoin('locations as l', 'pir.location_id', '=', 'l.id')
            ->leftJoin('earnings as e', 'pir.earning_id', '=', 'e.id')
            ->leftJoin('partners as p', 'e.buyer_id', '=', 'p.id')
            ->whereIn('pir.picking_task_id', $taskIds)
            ->select([
                'pir.id',
                'pir.item_id',
                'i.code as item_code',
                'i.name as item_name',
                'l.code1',
                'l.code2',
                'l.code3',
                'pir.location_id',
                'pir.walking_order',
                'pir.planned_qty',
                'pir.planned_qty_type',
                'pir.earning_id',
                'p.name as buyer_name',
            ])
            ->orderByRaw('COALESCE(pir.walking_order, 999999)')
            ->orderByRaw("COALESCE(l.code1, 'ZZZ')")
            ->orderByRaw("COALESCE(l.code2, 'ZZZ')")
            ->orderByRaw("COALESCE(l.code3, 'ZZZ')")
            ->orderBy('i.code')
            ->get();

        return $this->buildSecondaryItems($results, [
            'wave_no' => $task->wave_no,
            'course_name' => $task->course_name ?? '',
            'area_name' => $task->area_name ?? '',
            'picker_name' => $task->picker_name ?? '',
            'shipping_date' => $task->shipping_date,
        ]);
    }

    /**
     * 2次リスト明細行の組み立て（共通処理）
     */
    private function buildSecondaryItems($results, array $header): array
    {
        $grouped = [];
        $locationSet = [];

        foreach ($results as $row) {
            $locationCode = $row->location_id
                ? Location::formatCode($row->code1, $row->code2, $row->code3)
                : '未設定';

            $key = "{$locationCode}|{$row->item_id}";

            if (! isset($grouped[$key])) {
                $qtyType = QuantityType::tryFrom($row->planned_qty_type) ?? QuantityType::PIECE;
                $grouped[$key] = [
                    'location_code' => $locationCode,
                    'item_code' => $row->item_code,
                    'item_name' => $row->item_name,
                    'total_pick_qty' => 0,
                    'qty_type' => $qtyType->name(),
                    'destinations' => [],
                ];
                $locationSet[$locationCode] = true;
            }

            $grouped[$key]['total_pick_qty'] += (int) $row->planned_qty;

            if ($row->buyer_name) {
                $qtyType = QuantityType::tryFrom($row->planned_qty_type) ?? QuantityType::PIECE;
                $grouped[$key]['destinations'][] = [
                    'name' => $row->buyer_name,
                    'qty' => (int) $row->planned_qty,
                    'qty_type' => $qtyType->name(),
                ];
            }
        }

        $items = array_values($grouped);
        $totalQty = array_sum(array_column($items, 'total_pick_qty'));

        return [
            'header' => $header,
            'items' => $items,
            'summary' => [
                'item_count' => count($items),
                'location_count' => count($locationSet),
                'total_qty' => $totalQty,
            ],
        ];
    }

    /**
     * 3次ピッキングリスト（配送コース別仕分け）
     *
     * 2次リストと同じ構造（商品＋納品先内訳）を配送コース別にページ分割する。
     *
     * @return array[] 配送コースごとの2次リスト構造の配列
     */
    public function generateTertiaryList(int $waveId): array
    {
        return $this->generateTertiaryListByWaveIds([$waveId]);
    }

    /**
     * 3次ピッキングリスト一括（複数Wave対応）
     *
     * @return array[] 配送コースごとの2次リスト構造の配列
     */
    public function generateTertiaryListByWaveIds(array $waveIds): array
    {
        if (empty($waveIds)) {
            return [];
        }

        // ヘッダー情報（最初のWaveから）
        $wave = $this->db()->table('wms_waves as w')
            ->whereIn('w.id', $waveIds)
            ->select(['w.wave_no', 'w.shipping_date'])
            ->first();

        if (! $wave) {
            return [];
        }

        // タスクを配送コース別にグループ化
        $tasks = $this->db()->table('wms_picking_tasks as pt')
            ->leftJoin('delivery_courses as dc', 'pt.delivery_course_id', '=', 'dc.id')
            ->whereIn('pt.wave_id', $waveIds)
            ->select(['pt.id', 'pt.delivery_course_id', 'dc.code as course_code', 'dc.name as course_name'])
            ->orderBy('dc.code')
            ->get();

        $courseTasks = [];
        foreach ($tasks as $task) {
            $courseKey = $task->delivery_course_id ?? 0;
            if (! isset($courseTasks[$courseKey])) {
                $courseTasks[$courseKey] = [
                    'course_code' => $task->course_code ?? '',
                    'course_name' => $task->course_name ?? '未設定',
                    'task_ids' => [],
                ];
            }
            $courseTasks[$courseKey]['task_ids'][] = $task->id;
        }

        // 配送コースごとに明細を取得・組み立て
        $results = [];
        foreach ($courseTasks as $courseData) {
            $taskIds = $courseData['task_ids'];

            $rows = $this->db()->table('wms_picking_item_results as pir')
                ->join('items as i', 'pir.item_id', '=', 'i.id')
                ->leftJoin('locations as l', 'pir.location_id', '=', 'l.id')
                ->leftJoin('earnings as e', 'pir.earning_id', '=', 'e.id')
                ->leftJoin('partners as p', 'e.buyer_id', '=', 'p.id')
                ->whereIn('pir.picking_task_id', $taskIds)
                ->select([
                    'pir.id',
                    'pir.item_id',
                    'i.code as item_code',
                    'i.name as item_name',
                    'l.code1',
                    'l.code2',
                    'l.code3',
                    'pir.location_id',
                    'pir.walking_order',
                    'pir.planned_qty',
                    'pir.planned_qty_type',
                    'pir.earning_id',
                    'p.name as buyer_name',
                ])
                ->orderByRaw('COALESCE(pir.walking_order, 999999)')
                ->orderByRaw("COALESCE(l.code1, 'ZZZ')")
                ->orderByRaw("COALESCE(l.code2, 'ZZZ')")
                ->orderByRaw("COALESCE(l.code3, 'ZZZ')")
                ->orderBy('i.code')
                ->get();

            $data = $this->buildSecondaryItems($rows, [
                'wave_no' => $wave->wave_no,
                'course_name' => $courseData['course_name'],
                'area_name' => '',
                'picker_name' => '',
                'shipping_date' => $wave->shipping_date,
            ]);

            if (! empty($data['items'])) {
                $results[] = $data;
            }
        }

        return $results;
    }
}
