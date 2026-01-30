<?php

namespace App\Filament\Resources\DeliveryCourseChangeResource\Pages;

use App\Filament\Resources\DeliveryCourseChangeResource;
use App\Filament\Resources\DeliveryCourseChangeResource\Widgets\DeliveryCourseChangeStats;
use Filament\Pages\Concerns\ExposesTableToWidgets;
use Filament\Resources\Pages\ListRecords;

class ListDeliveryCourseChanges extends ListRecords
{
    use ExposesTableToWidgets;

    protected static string $resource = DeliveryCourseChangeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // 必要に応じてヘッダーアクションを追加
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            DeliveryCourseChangeStats::class,
        ];
    }

    public function getHeading(): string|\Illuminate\Contracts\Support\Htmlable
    {
        $date = $this->tableFilters['shipment_date']['shipment_date'] ?? \App\Models\Sakemaru\ClientSetting::systemDateYMD();

        $warehouseId = $this->tableFilters['warehouse_id']['value'] ?? auth()->user()->default_warehouse_id;
        $warehouseName = $warehouseId
            ? \Illuminate\Support\Facades\DB::connection('sakemaru')->table('warehouses')->where('id', $warehouseId)->value('name')
            : '未選択';

        return parent::getHeading()." [{$date} / {$warehouseName}]";
    }
}
