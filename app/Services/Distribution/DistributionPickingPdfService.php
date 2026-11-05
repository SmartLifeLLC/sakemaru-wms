<?php

namespace App\Services\Distribution;

use App\Models\Sakemaru\Location;
use App\Models\WmsDistributionBatch;
use App\Services\PickingList\PickingListPdfService;
use App\Services\WarehouseResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Read-only distribution instructions. No waves, reservations or stock changes. */
class DistributionPickingPdfService
{
    public const TYPES = ['primary', 'primary_total', 'secondary', 'secondary_v2', 'tertiary'];

    public function rows(WmsDistributionBatch $batch): Collection
    {
        $db = DB::connection('sakemaru');
        $sourceId = WarehouseResolver::resolveRealWarehouseId($batch->warehouse_id);
        $warehouse = $db->table('warehouses')->where('client_id', $batch->client_id)->where('id', $sourceId)->first();
        abort_unless($warehouse, 422, '出庫元倉庫を確認してください。');
        $saved = $batch->rows()->orderBy('id')->get(['row_data'])->pluck('row_data');
        $items = $db->table('items')->where('client_id', $batch->client_id)
            ->whereIn('code', $saved->pluck('productCode')->filter()->unique())
            ->get(['id', 'code', 'name', 'capacity_case', 'packaging'])->keyBy('code');
        $jans = $db->table('item_search_information')->where('client_id', $batch->client_id)
            ->whereIn('item_id', $items->pluck('id'))->where('is_active', true)->where('is_in_use', true)
            ->where('code_type', 'JAN')->orderBy('id')->get(['item_id', 'search_string'])->unique('item_id')->keyBy('item_id');
        $locations = $this->shelfLocations($batch->client_id, $sourceId, $items->pluck('id')->all());
        $warehouses = $db->table('warehouses')->where('client_id', $batch->client_id)->get(['id', 'code', 'name'])->keyBy('code');
        $courses = $db->table('delivery_courses')->where('client_id', $batch->client_id)->get(['id', 'code', 'name'])->keyBy('id');
        $defaults = $db->table('warehouse_stock_transfer_delivery_courses')->where('from_warehouse_id', $sourceId)
            ->orderBy('id')->get(['to_warehouse_id', 'delivery_course_id'])->unique('to_warehouse_id')->keyBy('to_warehouse_id');
        $rows = collect();
        foreach ($saved as $row) {
            $allocations = collect($row)->filter(fn ($qty, $key) => str_starts_with($key, 'alloc_') && is_numeric($qty) && (int) $qty > 0);
            if ($allocations->isEmpty()) {
                continue;
            }
            $item = $items->get($row['productCode'] ?? '');
            if (! $item) {
                throw ValidationException::withMessages(['商品CD' => '商品CD'.($row['productCode'] ?? '').'の商品マスタを確認してください。']);
            }
            $location = $locations->get($item->id);
            foreach ($allocations as $key => $qty) {
                $destination = $warehouses->get((int) substr($key, 6));
                if (! $destination) {
                    throw ValidationException::withMessages(['分配先' => '倉庫CD'.substr($key, 6).'の分配先を確認してください。']);
                }
                $courseId = (int) ($row['deliveryCourseId'] ?? $row['delivery_course_id'] ?? 0) ?: ($defaults->get($destination->id)?->delivery_course_id ?? 0);
                $course = $courses->get($courseId);
                $rows->push([
                    'jan_code' => $jans->get($item->id)?->search_string ?? '', 'item_code' => (string) $item->code, 'item_name' => $item->name, 'packaging' => $item->packaging ?? '',
                    'capacity_case' => max(1, (int) $item->capacity_case), 'total_pieces' => (int) $qty,
                    'location_code' => $location ? Location::formatCode($location->code1, $location->code2, $location->code3, '-') : '未設定',
                    'code1' => $location?->code1 ?? '', 'floor_id' => $location?->floor_id,
                    'floor_name' => $location?->floor_name ?? '', 'warehouse_name' => $warehouse->name,
                    'course_id' => $course?->id ?? 0, 'course_code' => $course?->code ?? '', 'course_name' => $course?->name ?? 'コース未設定',
                    'buyer_code' => (string) $destination->code, 'buyer_name' => $destination->name,
                ]);
            }
        }

        return $rows->sortBy([['course_code', 'asc'], ['floor_id', 'asc'], ['location_code', 'asc'], ['item_code', 'asc'], ['buyer_code', 'asc']])->values();
    }

    private function shelfLocations(int $clientId, int $warehouseId, array $itemIds): Collection
    {
        $db = DB::connection('sakemaru');
        $fields = ['l.id', 'l.code1', 'l.code2', 'l.code3', 'l.floor_id', 'f.name as floor_name'];
        $hasCode = fn ($location): bool => trim(Location::formatCode($location->code1, $location->code2, $location->code3, '-')) !== '';
        $defaults = $db->table('item_incoming_default_locations as d')
            ->join('locations as l', 'd.location_id', '=', 'l.id')
            ->leftJoin('floors as f', 'l.floor_id', '=', 'f.id')
            ->where('d.warehouse_id', $warehouseId)->where('l.warehouse_id', $warehouseId)->where('l.client_id', $clientId)
            ->whereIn('d.item_id', $itemIds)
            ->orderBy('l.code1')->orderBy('l.code2')->orderBy('l.code3')->orderBy('d.id')
            ->get(array_merge(['d.item_id'], $fields))->filter($hasCode)->unique('item_id')->keyBy('item_id');
        $missingIds = array_values(array_diff($itemIds, $defaults->keys()->all()));
        if ($missingIds === []) {
            return $defaults;
        }

        // Match product lookup: use a live stock shelf only when no standard shelf is registered.
        // These are reference locations, not stock allocations or picking instructions from a wave.
        $stockLocations = $db->table('real_stock_lots as rsl')
            ->join('real_stocks as rs', 'rs.id', '=', 'rsl.real_stock_id')
            ->join('locations as l', 'l.id', '=', 'rsl.location_id')
            ->leftJoin('floors as f', 'l.floor_id', '=', 'f.id')
            ->where('rs.client_id', $clientId)->where('rs.warehouse_id', $warehouseId)
            ->where('l.client_id', $clientId)->where('l.warehouse_id', $warehouseId)
            ->whereIn('rs.item_id', $missingIds)->where('rsl.status', 'ACTIVE')->where('rsl.current_quantity', '>', 0)
            ->orderBy('l.code1')->orderBy('l.code2')->orderBy('l.code3')->orderBy('l.id')
            ->select(array_merge(['rs.item_id'], $fields))->distinct()->get()
            ->filter($hasCode)->unique('item_id')->keyBy('item_id');

        return $defaults->union($stockLocations);
    }

    public function documents(Collection $rows, string $type, string $name, string $date = ''): array
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException('帳票種別を選択してください。');
        }
        $groups = match ($type) {
            'primary_total' => $rows->groupBy(fn ($r) => $r['floor_id'] ?? 'none'),
            'tertiary' => $rows->groupBy('course_id'),
            'secondary_v2' => $rows->groupBy(fn ($r) => $r['course_id'].'|'.$this->floorBucket($r)),
            default => $rows->groupBy(fn ($r) => $r['course_id'].'|'.($r['floor_id'] ?? 'none')),
        };
        if ($type === 'secondary_v2') {
            $groups = $groups->sortBy(fn ($group) => [$this->floorBucket($group->first()), $group->first()['course_code']]);
        }

        return $groups->map(function ($group) use ($type, $name, $date) {
            $first = $group->first();
            $items = $group->groupBy(fn ($r) => ($type === 'tertiary' ? $r['buyer_code'].'|' : '').$r['item_code'].'|'.$r['location_code'])
                ->map(function ($lines) {
                    $row = $lines->first();
                    $total = (int) $lines->sum('total_pieces');

                    return array_merge($row, ['case_qty' => intdiv($total, $row['capacity_case']),
                        'piece_qty' => $total % $row['capacity_case'], 'total_pieces' => $total,
                        'total_qty' => $total, 'total_piece_qty' => $total, 'shortage_qty' => 0]);
                })->values()->map(fn ($row, $index) => array_merge($row, ['no' => $index + 1]))->all();
            $case = array_sum(array_column($items, 'case_qty'));
            $piece = array_sum(array_column($items, 'piece_qty'));
            $total = array_sum(array_column($items, 'total_pieces'));

            return ['header' => [
                'wave_label' => '分配', 'wave_no' => $name, 'distribution_name' => $name,
                'shipping_date' => $date, 'warehouse_name' => $first['warehouse_name'],
                'course_name' => $first['course_name'],
                'delivery_course_name' => $type === 'primary' ? $first['course_name'] : '',
                'delivery_course_code' => $type === 'primary' ? $first['course_code'] : '',
                'floor_name' => $type === 'secondary_v2' && $this->floorBucket($first) === '9-YX' ? 'YX' : $first['floor_name'],
                'list_title' => $type === 'primary_total' ? '1次ピッキングリスト（一括・分配）' : '1次ピッキングリスト（分配集約）',
            ], 'items' => $items, 'summary' => [
                'sku_count' => count($items), 'row_count' => count($items), 'total_qty' => $total,
                'case_qty' => $case, 'piece_qty' => $piece, 'total_pieces' => $total,
                'total_case' => $case, 'total_piece' => $piece, 'total_piece_qty' => $total,
                'total_shortage' => 0, 'total_pieces_all' => $total,
            ]];
        })->values()->all();
    }

    private function floorBucket(array $row): string
    {
        if (preg_match('/^Y[ABCX]/', $row['code1'])) {
            return '9-YX';
        }

        return str_pad((string) ($row['floor_id'] ?? 99999), 6, '0', STR_PAD_LEFT);
    }

    public function render(WmsDistributionBatch $batch, string $type, string $date = ''): string
    {
        $rows = $this->rows($batch);
        abort_if($rows->isEmpty(), 422, '分配数量が入力されている商品がありません。');
        $documents = $this->documents($rows, $type, '#'.$batch->id.' '.$batch->name, $date);
        $pdf = new PickingListPdfService;

        return match ($type) {
            'primary', 'primary_total' => $pdf->renderBatchPrimaryPdf($documents),
            'secondary', 'secondary_v2' => $pdf->renderCourseGroupedPdf($documents),
            'tertiary' => $pdf->renderBuyerGroupedPdf($documents),
        };
    }
}
