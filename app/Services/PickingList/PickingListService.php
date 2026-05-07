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
            DB::raw('COALESCE(l.floor_id, pt.floor_id) as floor_id'),
            DB::raw('SUM(pir.planned_qty) as total_qty'),
            DB::raw('SUM(pir.shortage_qty) as shortage_qty'),
            'pir.planned_qty_type',
        ])
            ->groupBy('i.id', 'i.code', 'i.name', 'i.capacity_case', 'i.packaging', 'pir.planned_qty_type', 'pir.location_id', 'l.code1', 'l.code2', 'l.code3')
            ->groupByRaw('COALESCE(l.floor_id, pt.floor_id)')
            ->orderByRaw("COALESCE(l.code1, 'ZZZ')")
            ->orderByRaw("COALESCE(l.code2, 'ZZZ')")
            ->orderByRaw("COALESCE(l.code3, 'ZZZ')")
            ->orderBy('i.code')
            ->get();

        $floorNames = $this->db()->table('floors')
            ->whereIn('id', $items->pluck('floor_id')->filter()->unique()->values()->all())
            ->pluck('name', 'id');

        $formattedItems = [];
        $totalQty = 0;
        $totalCase = 0;
        $totalPiece = 0;
        $totalShortage = 0;
        $totalPieceQty = 0;

        foreach ($items as $item) {
            $capacityCase = $item->capacity_case ?: 1;
            $qty = (int) $item->total_qty;
            $shortageQty = (int) $item->shortage_qty;
            $qtyType = QuantityType::tryFrom($item->planned_qty_type) ?? QuantityType::PIECE;

            if ($qtyType === QuantityType::CASE) {
                $caseQty = $qty;
                $pieceQty = 0;
                $pieceTotalQty = $qty * $capacityCase;
            } elseif ($capacityCase > 1) {
                $caseQty = intdiv($qty, $capacityCase);
                $pieceQty = $qty % $capacityCase;
                $pieceTotalQty = $qty;
            } else {
                $caseQty = 0;
                $pieceQty = $qty;
                $pieceTotalQty = $qty;
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
                'shortage_qty' => $shortageQty,
                'total_piece_qty' => $pieceTotalQty,
                'floor_id' => $item->floor_id ? (int) $item->floor_id : null,
                'floor_name' => $item->floor_id ? ($floorNames[$item->floor_id] ?? '') : '',
            ];

            $totalQty += $qty;
            $totalCase += $caseQty;
            $totalPiece += $pieceQty;
            $totalShortage += $shortageQty;
            $totalPieceQty += $pieceTotalQty;
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
                'total_shortage' => $totalShortage,
                'total_piece_qty' => $totalPieceQty,
            ],
        ];
    }

    /**
     * 1次ピッキングリストを必要に応じてフロア別ページへ分割する。
     *
     * @return array<int, array{header: array, items: array, summary: array}>
     */
    public function generatePrimaryListPages(int $waveId, bool $separateFloors = true): array
    {
        $data = $this->generatePrimaryList($waveId);

        if (! $separateFloors || empty($data['items'])) {
            return [$data];
        }

        $groupedItems = collect($data['items'])->groupBy(fn (array $item) => $item['floor_id'] ?? 'none');

        if ($groupedItems->count() <= 1) {
            return [$data];
        }

        return $groupedItems
            ->map(function ($items) use ($data) {
                $items = $items->values()->all();
                $floorName = $items[0]['floor_name'] ?? 'フロア未設定';

                return [
                    'header' => array_merge($data['header'], [
                        'floor_name' => $floorName ?: 'フロア未設定',
                    ]),
                    'items' => $items,
                    'summary' => $this->summarizePrimaryItems($items),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * 1次ピッキングリスト（一括）
     *
     * 複数Waveをまたいで棚番・商品単位に集計する。波動番号は集計キーに含めない。
     *
     * @param  array<int>  $waveIds
     * @return array{header: array, items: array, summary: array}
     */
    public function generatePrimaryTotalList(array $waveIds, array $header = [], bool $groupByFloor = false): array
    {
        $waveIds = collect($waveIds)->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();

        if (empty($waveIds)) {
            return ['header' => $header, 'items' => [], 'summary' => $this->summarizePrimaryItems([])];
        }

        $firstWave = $this->db()->table('wms_waves as w')
            ->join('wms_wave_settings as ws', 'w.wms_wave_setting_id', '=', 'ws.id')
            ->join('delivery_courses as dc', 'ws.delivery_course_id', '=', 'dc.id')
            ->leftJoin('warehouses as wh', 'dc.warehouse_id', '=', 'wh.id')
            ->whereIn('w.id', $waveIds)
            ->select([
                'w.shipping_date',
                'wh.name as warehouse_name',
            ])
            ->orderBy('w.wave_no')
            ->first();

        $selectColumns = [
            'i.id as item_id',
            'i.code as item_code',
            'i.name as item_name',
            'i.capacity_case',
            'i.packaging',
            'pir.planned_qty_type',
            'pir.location_id',
            'l.code1',
            'l.code2',
            'l.code3',
            DB::raw($groupByFloor ? 'COALESCE(l.floor_id, pt.floor_id) as floor_id' : 'NULL as floor_id'),
            DB::raw('SUM(pir.planned_qty) as total_qty'),
            DB::raw('SUM(pir.shortage_qty) as shortage_qty'),
        ];

        $items = $this->db()->table('wms_picking_item_results as pir')
            ->join('wms_picking_tasks as pt', 'pir.picking_task_id', '=', 'pt.id')
            ->join('items as i', 'pir.item_id', '=', 'i.id')
            ->leftJoin('locations as l', 'pir.location_id', '=', 'l.id')
            ->whereIn('pt.wave_id', $waveIds)
            ->where('pt.status', '!=', 'COMPLETED')
            ->select($selectColumns)
            ->groupBy('i.id', 'i.code', 'i.name', 'i.capacity_case', 'i.packaging', 'pir.planned_qty_type')
            ->groupBy('pir.location_id', 'l.code1', 'l.code2', 'l.code3')
            ->when($groupByFloor, fn ($query) => $query->groupByRaw('COALESCE(l.floor_id, pt.floor_id)'))
            ->orderByRaw("COALESCE(l.code1, 'ZZZ')")
            ->orderByRaw("COALESCE(l.code2, 'ZZZ')")
            ->orderByRaw("COALESCE(l.code3, 'ZZZ')")
            ->orderBy('i.code')
            ->get();

        $floorNames = $this->db()->table('floors')
            ->whereIn('id', $items->pluck('floor_id')->filter()->unique()->values()->all())
            ->pluck('name', 'id');

        $formattedItems = [];

        foreach ($items as $item) {
            $capacityCase = $item->capacity_case ?: 1;
            $qty = (int) $item->total_qty;
            $shortageQty = (int) $item->shortage_qty;
            $qtyType = QuantityType::tryFrom($item->planned_qty_type) ?? QuantityType::PIECE;
            $pieceTotalQty = $qtyType === QuantityType::CASE ? $qty * $capacityCase : $qty;
            $caseQty = $capacityCase > 1 ? intdiv($pieceTotalQty, $capacityCase) : 0;
            $pieceQty = $capacityCase > 1 ? $pieceTotalQty % $capacityCase : $pieceTotalQty;
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
                'shortage_qty' => $shortageQty,
                'total_piece_qty' => $pieceTotalQty,
                'floor_id' => $item->floor_id ? (int) $item->floor_id : null,
                'floor_name' => $item->floor_id ? ($floorNames[$item->floor_id] ?? '') : '',
            ];
        }

        return [
            'header' => array_merge([
                'wave_no' => '全波動合計',
                'shipping_date' => $firstWave->shipping_date ?? '',
                'warehouse_name' => $firstWave->warehouse_name ?? '',
                'list_title' => '1次ピッキングリスト(一括)',
            ], $header),
            'items' => $formattedItems,
            'summary' => $this->summarizePrimaryItems($formattedItems),
        ];
    }

    /**
     * 1次ピッキングリスト（一括）を必要に応じてフロア別ページへ分割する。
     *
     * @param  array<int>  $waveIds
     * @return array<int, array{header: array, items: array, summary: array}>
     */
    public function generatePrimaryTotalListPages(array $waveIds, bool $separateFloors = true): array
    {
        $data = $this->generatePrimaryTotalList($waveIds, groupByFloor: $separateFloors);

        if (! $separateFloors || empty($data['items'])) {
            return [$data];
        }

        $groupedItems = collect($data['items'])->groupBy(fn (array $item) => $item['floor_id'] ?? 'none');

        if ($groupedItems->count() <= 1) {
            return [$data];
        }

        return $groupedItems
            ->map(function ($items) use ($data) {
                $items = $items->values()->all();
                $floorName = $items[0]['floor_name'] ?? 'フロア未設定';

                return [
                    'header' => array_merge($data['header'], [
                        'floor_name' => $floorName ?: 'フロア未設定',
                    ]),
                    'items' => $items,
                    'summary' => $this->summarizePrimaryItems($items),
                ];
            })
            ->values()
            ->all();
    }

    private function summarizePrimaryItems(array $items): array
    {
        return [
            'sku_count' => count($items),
            'total_qty' => array_sum(array_column($items, 'total_qty')),
            'total_case' => array_sum(array_column($items, 'case_qty')),
            'total_piece' => array_sum(array_column($items, 'piece_qty')),
            'total_shortage' => array_sum(array_column($items, 'shortage_qty')),
            'total_piece_qty' => array_sum(array_column($items, 'total_piece_qty')),
        ];
    }

    /**
     * 1次欠品リスト（Wave集約・欠品のみ）
     *
     * @return array{header: array, items: array, summary: array}
     */
    public function generateShortageList(int $waveId): array
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

        $items = $this->db()->table('wms_picking_item_results as pir')
            ->join('wms_picking_tasks as pt', 'pir.picking_task_id', '=', 'pt.id')
            ->join('items as i', 'pir.item_id', '=', 'i.id')
            ->leftJoin('locations as l', 'pir.location_id', '=', 'l.id')
            ->where('pt.wave_id', $waveId)
            ->select([
                'i.code as item_code',
                'i.name as item_name',
                'i.capacity_case',
                'i.packaging',
                'pir.location_id',
                'l.code1',
                'l.code2',
                'l.code3',
                DB::raw('SUM(pir.planned_qty) as planned_qty'),
                DB::raw('SUM(pir.shortage_qty) as shortage_qty'),
                'pir.planned_qty_type',
            ])
            ->groupBy('i.id', 'i.code', 'i.name', 'i.capacity_case', 'i.packaging', 'pir.planned_qty_type', 'pir.location_id', 'l.code1', 'l.code2', 'l.code3')
            ->havingRaw('SUM(pir.shortage_qty) > 0')
            ->orderBy('i.code')
            ->get();

        $formattedItems = [];
        $totalShortage = 0;

        foreach ($items as $item) {
            $plannedQty = (int) $item->planned_qty;
            $shortageQty = (int) $item->shortage_qty;
            $allocatedQty = $plannedQty - $shortageQty;
            $qtyType = QuantityType::tryFrom($item->planned_qty_type) ?? QuantityType::PIECE;
            $qtyLabel = $qtyType === QuantityType::CASE ? 'ケース' : 'バラ';

            $locationCode = $item->location_id
                ? Location::formatCode($item->code1, $item->code2, $item->code3, '-')
                : '';

            $formattedItems[] = [
                'item_code' => $item->item_code,
                'item_name' => $item->item_name,
                'packaging' => $item->packaging ?? '',
                'location_code' => $locationCode,
                'qty_label' => $qtyLabel,
                'planned_qty' => $plannedQty,
                'allocated_qty' => $allocatedQty,
                'shortage_qty' => $shortageQty,
            ];

            $totalShortage += $shortageQty;
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
                'total_shortage' => $totalShortage,
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

            $data = $this->buildTertiaryItems($rows, [
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

    /**
     * 3次リスト明細行の組み立て。
     *
     * ケースとバラが同一棚番・同一商品に混在しても、片方へ寄せず別列へ集計する。
     */
    private function buildTertiaryItems($results, array $header): array
    {
        $grouped = [];
        $locationSet = [];

        foreach ($results as $row) {
            $locationCode = $row->location_id
                ? Location::formatCode($row->code1, $row->code2, $row->code3)
                : '未設定';

            $key = "{$locationCode}|{$row->item_id}";

            if (! isset($grouped[$key])) {
                $grouped[$key] = [
                    'location_code' => $locationCode,
                    'item_code' => $row->item_code,
                    'item_name' => $row->item_name,
                    'case_qty' => 0,
                    'piece_qty' => 0,
                    'destinations' => [],
                ];
                $locationSet[$locationCode] = true;
            }

            $qty = (int) $row->planned_qty;
            $qtyType = QuantityType::tryFrom($row->planned_qty_type) ?? QuantityType::PIECE;
            $qtyColumn = $qtyType === QuantityType::CASE ? 'case_qty' : 'piece_qty';
            $grouped[$key][$qtyColumn] += $qty;

            if ($row->buyer_name) {
                $destinationKey = $row->buyer_name;

                if (! isset($grouped[$key]['destinations'][$destinationKey])) {
                    $grouped[$key]['destinations'][$destinationKey] = [
                        'name' => $row->buyer_name,
                        'case_qty' => 0,
                        'piece_qty' => 0,
                    ];
                }

                $grouped[$key]['destinations'][$destinationKey][$qtyColumn] += $qty;
            }
        }

        $items = array_map(function (array $item): array {
            $item['destinations'] = array_values($item['destinations']);

            return $item;
        }, array_values($grouped));

        return [
            'header' => $header,
            'items' => $items,
            'summary' => [
                'item_count' => count($items),
                'location_count' => count($locationSet),
                'total_case' => array_sum(array_column($items, 'case_qty')),
                'total_piece' => array_sum(array_column($items, 'piece_qty')),
                'total_qty' => array_sum(array_column($items, 'case_qty')) + array_sum(array_column($items, 'piece_qty')),
            ],
        ];
    }

    /**
     * 配送コース別ピッキングリスト（伝票単位ページ）
     *
     * 仮ピッキングリスト出力の2次ピッキングリストとして使用する。
     * 1ページ = 1売上伝票。配送者名には配送コース名を表示する。
     *
     * @return array[] [['header' => [...], 'items' => [...]], ...]
     */
    public function generateCourseGroupedListByWaveIds(array $waveIds): array
    {
        if (empty($waveIds)) {
            return [];
        }

        $rows = $this->db()->table('wms_picking_item_results as pir')
            ->join('wms_picking_tasks as pt', 'pir.picking_task_id', '=', 'pt.id')
            ->join('earnings as e', 'pir.earning_id', '=', 'e.id')
            ->leftJoin('trades as t', 'e.trade_id', '=', 't.id')
            ->leftJoin('delivery_courses as dc', 'pt.delivery_course_id', '=', 'dc.id')
            ->leftJoin('warehouses as wh', 'pt.warehouse_id', '=', 'wh.id')
            ->join('items as i', 'pir.item_id', '=', 'i.id')
            ->leftJoin('srh_searchable_items as ssi', 'ssi.item_id', '=', 'i.id')
            ->leftJoin('locations as l', 'pir.location_id', '=', 'l.id')
            ->whereIn('pt.wave_id', $waveIds)
            ->where('pir.source_type', 'EARNING')
            ->select([
                'pir.id as pir_id',
                'pir.earning_id',
                'e.id as e_id',
                't.slip_number',
                'e.delivered_date',
                'dc.name as course_name',
                'dc.code as course_code',
                'wh.name as warehouse_name',
                'i.code as item_code',
                'i.name as item_name',
                'i.capacity_case',
                'ssi.jancode as jan_code',
                'pir.location_id',
                'l.code1',
                'l.code2',
                'l.code3',
                'pir.planned_qty',
                'pir.planned_qty_type',
                'pir.shortage_qty',
            ])
            ->orderBy('dc.code')
            ->orderBy('e.id')
            ->orderByRaw("COALESCE(l.code1, 'ZZZ')")
            ->orderByRaw("COALESCE(l.code2, 'ZZZ')")
            ->orderByRaw("COALESCE(l.code3, 'ZZZ')")
            ->orderBy('i.code')
            ->get();

        $byEarning = [];
        foreach ($rows as $row) {
            $earningId = $row->earning_id;
            if (! isset($byEarning[$earningId])) {
                $byEarning[$earningId] = [
                    'header' => [
                        'course_name' => $row->course_name ?? '',
                        'slip_no' => $row->slip_number ?? (string) $row->e_id,
                        'shipping_date' => $row->delivered_date,
                        'warehouse_name' => $row->warehouse_name ?? '',
                    ],
                    '_rows' => [],
                ];
            }
            $byEarning[$earningId]['_rows'][] = $row;
        }

        $results = [];
        foreach ($byEarning as $earningId => $bucket) {
            $items = [];
            $rowsByLocationItem = [];

            foreach ($bucket['_rows'] as $row) {
                $key = ($row->location_id ?? 0).'|'.$row->item_code;
                if (! isset($rowsByLocationItem[$key])) {
                    $rowsByLocationItem[$key] = [
                        'item_code' => $row->item_code,
                        'item_name' => $row->item_name,
                        'capacity_case' => (int) ($row->capacity_case ?: 1),
                        'jan_code' => $this->extractFirstJanCode($row->jan_code),
                        'location_id' => $row->location_id,
                        'code1' => $row->code1,
                        'code2' => $row->code2,
                        'code3' => $row->code3,
                        'total_pieces' => 0,
                        'shortage_qty' => 0,
                        'planned_qty_type' => $row->planned_qty_type,
                    ];
                }

                $qtyType = QuantityType::tryFrom($row->planned_qty_type) ?? QuantityType::PIECE;
                $capacityCase = max(1, (int) ($row->capacity_case ?: 1));
                $qty = (int) $row->planned_qty;
                $piecesContribution = $qtyType === QuantityType::CASE ? $qty * $capacityCase : $qty;

                $rowsByLocationItem[$key]['total_pieces'] += $piecesContribution;
                $rowsByLocationItem[$key]['shortage_qty'] += (int) $row->shortage_qty;
            }

            $no = 0;
            foreach ($rowsByLocationItem as $entry) {
                $no++;
                $capacityCase = max(1, $entry['capacity_case']);
                $totalPieces = $entry['total_pieces'];
                $caseQty = intdiv($totalPieces, $capacityCase);
                $pieceQty = $totalPieces % $capacityCase;

                $locationCode = $entry['location_id']
                    ? Location::formatCode($entry['code1'], $entry['code2'], $entry['code3'], '-')
                    : '';

                $items[] = [
                    'no' => $no,
                    'location_code' => $locationCode,
                    'item_code' => $entry['item_code'],
                    'jan_code' => $entry['jan_code'],
                    'item_name' => $entry['item_name'],
                    'capacity_case' => $capacityCase,
                    'case_qty' => $caseQty,
                    'piece_qty' => $pieceQty,
                    'total_pieces' => $totalPieces,
                    'shortage_qty' => $entry['shortage_qty'],
                ];
            }

            $results[] = [
                'header' => $bucket['header'],
                'items' => $items,
            ];
        }

        return $results;
    }

    /**
     * 得意先別ピッキングリスト V2（配送コース別＋得意先内訳）
     *
     * 仮ピッキングリスト出力の3次ピッキングリストとして使用する。
     * 1ページ = 1配送コース。明細は得意先（buyer）→棚番順でソート。
     *
     * @return array[] [['header' => [...], 'items' => [...], 'summary' => [...]], ...]
     */
    public function generateBuyerGroupedListByWaveIds(array $waveIds): array
    {
        if (empty($waveIds)) {
            return [];
        }

        $rows = $this->db()->table('wms_picking_item_results as pir')
            ->join('wms_picking_tasks as pt', 'pir.picking_task_id', '=', 'pt.id')
            ->join('wms_waves as w', 'pt.wave_id', '=', 'w.id')
            ->join('earnings as e', 'pir.earning_id', '=', 'e.id')
            ->leftJoin('buyers as b', 'e.buyer_id', '=', 'b.id')
            ->leftJoin('partners as p', 'b.partner_id', '=', 'p.id')
            ->leftJoin('delivery_courses as dc', 'pt.delivery_course_id', '=', 'dc.id')
            ->join('items as i', 'pir.item_id', '=', 'i.id')
            ->leftJoin('srh_searchable_items as ssi', 'ssi.item_id', '=', 'i.id')
            ->leftJoin('locations as l', 'pir.location_id', '=', 'l.id')
            ->whereIn('pt.wave_id', $waveIds)
            ->where('pir.source_type', 'EARNING')
            ->select([
                'pir.id as pir_id',
                'pir.earning_id',
                'w.id as wave_id',
                'w.wave_no',
                'w.shipping_date',
                'dc.id as course_id',
                'dc.code as course_code',
                'dc.name as course_name',
                'p.code as buyer_code',
                'p.name as buyer_name',
                'i.code as item_code',
                'i.name as item_name',
                'i.capacity_case',
                'ssi.jancode as jan_code',
                'pir.location_id',
                'l.code1',
                'l.code2',
                'l.code3',
                'pir.planned_qty',
                'pir.planned_qty_type',
                'pir.shortage_qty',
            ])
            ->orderBy('dc.code')
            ->orderByRaw('COALESCE(p.code, 999999999)')
            ->orderByRaw("COALESCE(l.code1, 'ZZZ')")
            ->orderByRaw("COALESCE(l.code2, 'ZZZ')")
            ->orderByRaw("COALESCE(l.code3, 'ZZZ')")
            ->orderBy('i.code')
            ->get();

        // 配送コース別にバケット化
        $byCourse = [];
        foreach ($rows as $row) {
            $courseId = $row->course_id ?? 0;
            if (! isset($byCourse[$courseId])) {
                $byCourse[$courseId] = [
                    'header' => [
                        'course_name' => $row->course_name ?? '',
                        'wave_no' => $row->wave_no ?? '',
                        'shipping_date' => $row->shipping_date,
                    ],
                    'rows' => [],
                ];
            }
            $byCourse[$courseId]['rows'][] = $row;
        }

        $results = [];
        foreach ($byCourse as $bucket) {
            // 同じ得意先×棚番×商品 で集約
            $grouped = [];
            foreach ($bucket['rows'] as $row) {
                $key = ($row->buyer_code ?? 'NA').'|'.($row->location_id ?? 0).'|'.$row->item_code;
                if (! isset($grouped[$key])) {
                    $grouped[$key] = [
                        'buyer_code' => $row->buyer_code,
                        'buyer_name' => $row->buyer_name,
                        'location_id' => $row->location_id,
                        'code1' => $row->code1,
                        'code2' => $row->code2,
                        'code3' => $row->code3,
                        'item_code' => $row->item_code,
                        'item_name' => $row->item_name,
                        'capacity_case' => (int) ($row->capacity_case ?: 1),
                        'jan_code' => $this->extractFirstJanCode($row->jan_code),
                        'total_pieces' => 0,
                        'shortage_qty' => 0,
                    ];
                }

                $qtyType = QuantityType::tryFrom($row->planned_qty_type) ?? QuantityType::PIECE;
                $capacityCase = max(1, (int) ($row->capacity_case ?: 1));
                $qty = (int) $row->planned_qty;
                $piecesContribution = $qtyType === QuantityType::CASE ? $qty * $capacityCase : $qty;

                $grouped[$key]['total_pieces'] += $piecesContribution;
                $grouped[$key]['shortage_qty'] += (int) $row->shortage_qty;
            }

            $items = [];
            $totalCase = 0;
            $totalPiece = 0;
            $totalAll = 0;
            $no = 0;

            foreach ($grouped as $entry) {
                $no++;
                $capacityCase = max(1, $entry['capacity_case']);
                $totalPieces = $entry['total_pieces'];
                $caseQty = intdiv($totalPieces, $capacityCase);
                $pieceQty = $totalPieces % $capacityCase;

                $locationCode = $entry['location_id']
                    ? Location::formatCode($entry['code1'], $entry['code2'], $entry['code3'], '-')
                    : '';

                $items[] = [
                    'no' => $no,
                    'location_code' => $locationCode,
                    'buyer_code' => (string) ($entry['buyer_code'] ?? ''),
                    'buyer_name' => (string) ($entry['buyer_name'] ?? ''),
                    'item_code' => $entry['item_code'],
                    'jan_code' => $entry['jan_code'],
                    'item_name' => $entry['item_name'],
                    'capacity_case' => $capacityCase,
                    'case_qty' => $caseQty,
                    'piece_qty' => $pieceQty,
                    'total_pieces' => $totalPieces,
                    'shortage_qty' => $entry['shortage_qty'],
                ];

                $totalCase += $caseQty;
                $totalPiece += $pieceQty;
                $totalAll += $totalPieces;
            }

            $results[] = [
                'header' => $bucket['header'],
                'items' => $items,
                'summary' => [
                    'row_count' => count($items),
                    'total_case' => $totalCase,
                    'total_piece' => $totalPiece,
                    'total_pieces_all' => $totalAll,
                ],
            ];
        }

        return $results;
    }

    /**
     * srh_searchable_items.jancode は複数JANをカンマ等で連結している場合がある。
     * 表示用に最初の有効なJANを抽出する。
     */
    private function extractFirstJanCode(?string $raw): string
    {
        if ($raw === null || $raw === '') {
            return '';
        }
        $tokens = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY);
        if (empty($tokens)) {
            return '';
        }

        return (string) $tokens[0];
    }
}
