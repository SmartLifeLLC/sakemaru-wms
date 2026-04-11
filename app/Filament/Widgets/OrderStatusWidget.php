<?php

namespace App\Filament\Widgets;

use App\Enums\AutoOrder\JobProcessName;
use App\Enums\AutoOrder\SettlementStatus;
use App\Models\Sakemaru\ClientSetting;
use App\Models\Sakemaru\Warehouse;
use App\Models\WmsAutoOrderJobControl;
use App\Models\WmsContractorSetting;
use App\Models\WmsWarehouseAutoOrderSetting;
use Filament\Widgets\Widget;
use Livewire\Attributes\On;

class OrderStatusWidget extends Widget
{
    protected string $view = 'filament.widgets.order-status-widget';

    protected int|string|array $columnSpan = 'full';

    public string $filterDate = '';

    public array $hubWarehouses = [];

    public array $satelliteWarehouses = [];

    public array $warehouseStatuses = [];

    public function mount(): void
    {
        if (empty($this->filterDate)) {
            $this->filterDate = ClientSetting::systemDateYMD();
        }
        $this->loadData();
    }

    #[On('filter-date-updated')]
    public function onFilterDateUpdated(string $filterDate): void
    {
        $this->filterDate = $filterDate;
        $this->loadData();
    }

    protected function loadData(): void
    {
        // HUB倉庫IDを wms_contractor_settings から動的取得
        $hubWarehouseIds = WmsContractorSetting::where('transmission_type', 'INTERNAL')
            ->whereNotNull('supply_warehouse_id')
            ->distinct()
            ->pluck('supply_warehouse_id')
            ->toArray();

        // 自動発注有効な倉庫設定を取得
        $enabledSettings = WmsWarehouseAutoOrderSetting::where('is_auto_order_enabled', true)
            ->pluck('warehouse_id')
            ->toArray();

        // HUB倉庫情報
        $hubModels = Warehouse::whereIn('id', $hubWarehouseIds)
            ->where('is_virtual', false)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        $this->hubWarehouses = $hubModels->map(fn ($w) => [
            'id' => $w->id,
            'name' => $w->name,
        ])->toArray();

        // サテライト倉庫 = 自動発注有効 かつ HUBでない
        $satelliteWarehouseIds = array_diff($enabledSettings, $hubWarehouseIds);
        $satelliteModels = Warehouse::whereIn('id', $satelliteWarehouseIds)
            ->where('is_virtual', false)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        $this->satelliteWarehouses = $satelliteModels->map(fn ($w) => [
            'id' => $w->id,
            'name' => $w->name,
        ])->toArray();

        // 指定日のORDER_CALCジョブを取得（倉庫別の最新ステータス）
        $jobs = WmsAutoOrderJobControl::where('process_name', JobProcessName::ORDER_CALC)
            ->where('target_date', $this->filterDate)
            ->whereNotNull('warehouse_id')
            ->where('status', '!=', 'FAILED')
            ->orderBy('id', 'desc')
            ->get();

        // 倉庫ごとの最新ステータスをマップ（キーはstring統一 — Livewire JSONシリアライズ対策）
        $this->warehouseStatuses = [];
        $allWarehouseIds = array_merge($hubWarehouseIds, array_values($satelliteWarehouseIds));

        foreach ($allWarehouseIds as $warehouseId) {
            $warehouseJobs = $jobs->where('warehouse_id', $warehouseId);
            $key = (string) $warehouseId;

            if ($warehouseJobs->isEmpty()) {
                $this->warehouseStatuses[$key] = 'none';
            } elseif ($warehouseJobs->contains('settlement_status', SettlementStatus::CONFIRMED)) {
                $this->warehouseStatuses[$key] = 'confirmed';
            } else {
                $this->warehouseStatuses[$key] = 'pending';
            }
        }
    }
}
