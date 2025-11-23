<?php

namespace App\Filament\Resources\WmsShipmentSlips\Pages;

use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsShipmentSlips\WmsShipmentSlipsResource;
use App\Models\Sakemaru\ClientSetting;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Carbon\Carbon;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
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
}
