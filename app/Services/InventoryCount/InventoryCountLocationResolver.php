<?php

namespace App\Services\InventoryCount;

use App\Models\Sakemaru\Location;
use App\Models\WmsInventoryCountItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InventoryCountLocationResolver
{
    public const NO_LOCATION_LABEL = 'ノーロケ';

    /**
     * Match the stock management screen by preferring the item/warehouse default location.
     * Enrich only display attributes so historical count rows are not rewritten.
     *
     * @param  Collection<int, WmsInventoryCountItem>  $items
     * @return Collection<int, WmsInventoryCountItem>
     */
    public function enrich(Collection $items, int $warehouseId): Collection
    {
        $itemIds = $items
            ->pluck('item_id')
            ->filter()
            ->map(fn ($itemId): int => (int) $itemId)
            ->unique()
            ->values()
            ->all();

        $defaultLocations = $this->defaultLocations($warehouseId, $itemIds);

        return $items->map(function (WmsInventoryCountItem $item) use ($defaultLocations): WmsInventoryCountItem {
            $location = $defaultLocations->get((int) $item->item_id);
            if ($location === null && $this->hasSnapshotLocation($item)) {
                $location = (object) [
                    'location_id' => $item->location_id,
                    'floor_id' => $item->floor_id,
                    'floor_name' => $item->floor_name,
                    'code1' => $item->location_code1,
                    'code2' => $item->location_code2,
                    'code3' => $item->location_code3,
                    'location_no' => $item->location_no,
                ];
            }

            $code1 = $location?->code1;
            $code2 = $location?->code2;
            $code3 = $location?->code3;
            $locationNo = (string) ($location?->location_no ?? '');
            $locationNo = $locationNo !== ''
                ? $locationNo
                : Location::formatCode($code1, $code2, $code3);

            $item->setAttribute('report_location_id', $location?->location_id);
            $item->setAttribute('report_floor_id', $location?->floor_id);
            $item->setAttribute('report_floor_name', $location?->floor_name);
            $item->setAttribute('report_location_code1', $code1);
            $item->setAttribute('report_location_code2', $code2);
            $item->setAttribute('report_location_code3', $code3);
            $item->setAttribute('report_location_no', self::normalizeLabel($locationNo));

            return $item;
        });
    }

    public static function locationNo(WmsInventoryCountItem $item): string
    {
        $resolved = $item->getAttribute('report_location_no');
        if ($resolved !== null && $resolved !== '') {
            return (string) $resolved;
        }

        $locationNo = (string) ($item->location_no ?? '');
        $locationNo = $locationNo !== ''
            ? $locationNo
            : Location::formatCode(
                $item->location_code1,
                $item->location_code2,
                $item->location_code3,
            );

        return self::normalizeLabel($locationNo);
    }

    public static function locationCode1(WmsInventoryCountItem $item): string
    {
        return (string) ($item->getAttribute('report_location_code1') ?? $item->location_code1 ?? '');
    }

    public static function locationCode2(WmsInventoryCountItem $item): string
    {
        return (string) ($item->getAttribute('report_location_code2') ?? $item->location_code2 ?? '');
    }

    public static function locationCode3(WmsInventoryCountItem $item): string
    {
        return (string) ($item->getAttribute('report_location_code3') ?? $item->location_code3 ?? '');
    }

    public static function floorName(WmsInventoryCountItem $item): string
    {
        return (string) ($item->getAttribute('report_floor_name') ?? $item->floor_name ?? '');
    }

    public static function normalizeLabel(?string $locationNo): string
    {
        $locationNo = trim((string) $locationNo);
        $normalized = mb_strtoupper((string) preg_replace('/[\s\-_]+/u', '', $locationNo));

        if ($normalized === '' || in_array($normalized, ['Z00', 'フリーロケ', 'ノーロケ'], true)) {
            return self::NO_LOCATION_LABEL;
        }

        return $locationNo;
    }

    private function hasSnapshotLocation(WmsInventoryCountItem $item): bool
    {
        return trim(Location::formatCode(
            $item->location_code1,
            $item->location_code2,
            $item->location_code3,
        )) !== '' || trim((string) ($item->location_no ?? '')) !== '';
    }

    /**
     * @param  array<int, int>  $itemIds
     * @return Collection<int, object>
     */
    private function defaultLocations(int $warehouseId, array $itemIds): Collection
    {
        if ($itemIds === []) {
            return collect();
        }

        return collect($itemIds)
            ->chunk(1000)
            ->flatMap(fn (Collection $chunk): Collection => DB::connection('sakemaru')
                ->table('item_incoming_default_locations as idl')
                ->join('locations as l', 'l.id', '=', 'idl.location_id')
                ->leftJoin('floors as f', 'f.id', '=', 'l.floor_id')
                ->where('idl.warehouse_id', $warehouseId)
                ->whereIn('idl.item_id', $chunk->all())
                ->get([
                    'idl.item_id',
                    'l.id as location_id',
                    'f.id as floor_id',
                    'f.name as floor_name',
                    'l.code1',
                    'l.code2',
                    'l.code3',
                ]))
            ->keyBy(fn (object $row): int => (int) $row->item_id);
    }
}
