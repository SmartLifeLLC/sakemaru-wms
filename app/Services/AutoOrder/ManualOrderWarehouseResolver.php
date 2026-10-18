<?php

namespace App\Services\AutoOrder;

use App\Enums\AutoOrder\TransmissionType;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Manual ordering is available independently of automatic-order switches. */
class ManualOrderWarehouseResolver
{
    public function selected(int $warehouseId): array
    {
        return $this->warehouses()->where('id', $warehouseId)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function transfer(int $warehouseId): array
    {
        if ($this->selected($warehouseId) === []) {
            return [];
        }

        $satelliteIds = DB::connection('sakemaru')->table('item_contractors as ic')
            ->join('wms_contractor_settings as wcs', 'wcs.contractor_id', '=', 'ic.contractor_id')
            ->where('ic.client_id', (int) config('app.client_id'))
            ->where('wcs.transmission_type', TransmissionType::INTERNAL->value)
            ->where('wcs.supply_warehouse_id', $warehouseId)
            ->distinct()->pluck('ic.warehouse_id')->all();

        return $this->warehouses()->whereIn('id', [$warehouseId, ...$satelliteIds])
            ->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public function forCalculation(?int $warehouseId, bool $transferOnly): array
    {
        $query = $this->warehouses()->where('is_virtual', false);
        if ($warehouseId !== null) {
            $query->whereIn('id', $transferOnly ? $this->transfer($warehouseId) : [$warehouseId]);
        }

        return $query->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function warehouses(): Builder
    {
        $clientId = (int) config('app.client_id');
        throw_if($clientId < 1, \RuntimeException::class, '対象顧客が未設定です。管理者に設定を確認してください。');

        return DB::connection('sakemaru')->table('warehouses')
            ->where('client_id', $clientId)->where('is_active', true);
    }
}
