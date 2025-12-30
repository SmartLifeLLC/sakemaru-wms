<?php

namespace App\Filament\Resources\WmsPickingItemResults\Pages;

use App\Filament\Concerns\HasWmsUserViews;
use App\Filament\Resources\WmsPickingItemResults\WmsPickingItemResultResource;
use App\Models\Sakemaru\ClientSetting;
use Archilex\AdvancedTables\AdvancedTables;
use Archilex\AdvancedTables\Components\PresetView;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ListWmsPickingItemResults extends ListRecords
{
    use AdvancedTables;
    use HasWmsUserViews {
        HasWmsUserViews::getUserViews insteadof AdvancedTables;
        HasWmsUserViews::getFavoriteUserViews insteadof AdvancedTables;
    }

    protected static string $resource = WmsPickingItemResultResource::class;

    public function table(Table $table): Table
    {
        return WmsPickingItemResultResource::table($table)
            ->modifyQueryUsing(fn (Builder $query) => $query->with([
                'pickingTask',
                'earning.buyer.partner',
                'earning.warehouse',
                'earning.delivery_course',
                'trade',
                'item',
                'location',
            ]));
    }

    public function getPresetViews(): array
    {
        $systemDate = ClientSetting::systemDate();

        return [
            'default' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query->whereHas(
                    'pickingTask',
                    fn (Builder $q) => $q->whereDate('shipment_date', $systemDate)
                ))
                ->favorite()
                ->label('当日')
                ->default(),

            'pending' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->whereHas('pickingTask', fn (Builder $q) => $q->whereDate('shipment_date', $systemDate))
                    ->where('status', 'PENDING'))
                ->favorite()
                ->label('未着手'),

            'picking' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->whereHas('pickingTask', fn (Builder $q) => $q->whereDate('shipment_date', $systemDate))
                    ->where('status', 'PICKING'))
                ->favorite()
                ->label('ピッキング中'),

            'completed' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->whereHas('pickingTask', fn (Builder $q) => $q->whereDate('shipment_date', $systemDate))
                    ->where('status', 'COMPLETED'))
                ->favorite()
                ->label('完了'),

            'has_shortage' => PresetView::make()
                ->modifyQueryUsing(fn (Builder $query) => $query
                    ->whereHas('pickingTask', fn (Builder $q) => $q->whereDate('shipment_date', $systemDate))
                    ->where('shortage_qty', '>', 0))
                ->favorite()
                ->label('欠品あり'),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            //
        ];
    }
}
