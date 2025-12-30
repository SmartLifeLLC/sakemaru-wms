<?php

namespace App\Filament\Resources\WmsShipmentSlips\Pages;

use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsShipmentSlips\Tables\WmsShipmentSlipsTable;
use App\Filament\Resources\WmsShipmentSlips\WmsShipmentSlipsResource;
use App\Models\Sakemaru\ClientSetting;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder;

class ListWmsShipmentSlips extends ListRecords
{
    use AdvancedTables;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsShipmentSlipsResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }

    public function getPresetViews(): array
    {
        return [
            'default' => PresetView::make()->modifyQueryUsing(fn (Builder $query) => $query->where('shipment_date', ClientSetting::systemDateYMD()))->favorite()->label('当日')->default(),
            'all' => PresetView::make()->modifyQueryUsing(fn (Builder $query) => $query->where('shipment_date', ClientSetting::systemYesterdayYMD()))->favorite()->label('前日')->default(),
        ];
    }

    protected function paginateTableQuery(Builder $query): Paginator
    {
        $paginator = parent::paginateTableQuery($query);

        // ページネーション結果のレコードにグループ化されたタスクをロード
        $records = collect($paginator->items());
        WmsShipmentSlipsTable::loadGroupedTasks($records);

        return $paginator;
    }
}
